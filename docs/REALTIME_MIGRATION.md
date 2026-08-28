# TuneVote — Node/Socket.io → php-realtime Migration

How to move TuneVote's realtime layer onto `packages/php-realtime`. Companion to
the [audit](REALTIME_AUDIT.md) and [blueprint](REALTIME_BLUEPRINT.md). Concrete
integration files live in
[`packages/php-realtime/integrations/tunevote/`](../packages/php-realtime/integrations/tunevote).

---

## 0. Premise correction (read first)

The original brief assumed *"the main application backend is PHP and the VPS
exists solely to keep Socket.io alive."* The codebase says otherwise, and it
changes the plan:

- **`tunevote_api` is 100% Node.js/Express** — auth, queue, votes, playback,
  polls, the reconciler. Socket.io is one part of a single Node process. There
  is **no PHP backend** to mount a module into.
- **Prod topology** (`tunevote_api/DEPLOYMENT.md`): Node API + **MySQL 8 in
  Docker bound to `127.0.0.1:3306`** on the **VPS** (`api.tunevote.com`); the
  **static frontend** on **GoDaddy shared cPanel** (`app.tunevote.com`,
  file-deploy only, no process, cannot reach the VPS DB).

Consequences:

1. The PHP module must run **on the VPS** (php-fpm behind the existing nginx),
   the only place that can reach the `tunevote` MySQL.
2. **Migrating realtime does not retire the VPS.** It removes the *Socket.io
   server*, the *WebSocket-upgrade nginx requirement*, and the persistent-socket
   scaling limit — real wins — but the Node REST API still runs on the VPS.
   Retiring the VPS entirely requires porting the whole REST API to PHP, which
   is a separate, much larger project, out of scope for a realtime module.

**Chosen strategy — keep Node's domain logic, replace only the transport.**
Node continues to own all mutations; wherever it emits over Socket.io it instead
calls the PHP module's fan-out endpoint. The frontend swaps `socket.io-client`
for the module's `RealtimeClient` behind a Socket.io-compatible adapter, so
component code barely changes. Presence moves to module heartbeats. This is the
lowest-risk path and is fully reversible with a flag.

---

## 1. Event-by-event mapping

Every event keeps its **name**, so the frontend's `.on('name', …)` handlers are
unchanged. On the Node side, `getIO().to(id).emit(name, payload)` becomes
`rt.emit(id, name, payload)`; global `getIO().emit(name, payload)` becomes
`rt.emitGlobal(name, payload)` (reserved `global` channel).

| Socket.io event (today) | Direction | php-realtime mapping |
|---|---|---|
| `connection` + `?sessionId=` | c→s | `RealtimeClient.join(sessionId)` → participant token + snapshot; `connect()` starts long-poll/SSE |
| `heartbeat` | c→s | Automatic — the client POSTs `/heartbeat` every ~10 s |
| `disconnect` | c→s | Presence TTL prune + `presence` event (client also calls `/leave` on unmount) |
| `song_reaction {emoji}` | c→s | `client.emit('song_reaction', {emoji})` → module action → broadcast `song_reaction_broadcast` |
| `song_reaction_broadcast` | s→c | Emitted by the `song_reaction` action handler (`realtime.tunevote.php`) |
| `queue_updated` | s→c | `rt.emit(id, 'queue_updated', {})` after the queue mutation commits |
| `proposals_updated` | s→c | `rt.emit(id, 'proposals_updated', {})` |
| `playback_sync {current_queue_item_id, current_video_id, current_title, video_start_time, server_time, is_playing}` | s→c | `rt.emit(id, 'playback_sync', payload, { nowPlaying: payload })` — payload broadcast **and** patched into shared state so late joiners/resyncs are correct |
| `session_started {…}` | s→c | `rt.emit(id, 'session_started', payload, { status:'live' })` |
| `pause_started {…}` | s→c | `rt.emit(id, 'pause_started', payload)` |
| `queue_empty` | s→c | `rt.emit(id, 'queue_empty', {})` |
| `voting_phase_changed {phase, endsAt, roundId, duration}` | s→c | `rt.emit(id, 'voting_phase_changed', payload, { phase: payload })` |
| `suggesting_phase_started {…}` | s→c | `rt.emit(id, 'suggesting_phase_started', payload)` |
| `voting_round_completed {winnerId, roundId}` | s→c | `rt.emit(id, 'voting_round_completed', payload)` — **normalise the payload** (fix the `winnerId` vs `winner` inconsistency while migrating) |
| `regenerate_suggestions {roundId}` | s→c | `rt.emit(id, 'regenerate_suggestions', payload)` |
| `session_ended {…}` | s→c | `rt.emit(id, 'session_ended', payload, { status:'ended' })` |
| `session_deleted {message}` | s→c | `rt.emit(id, 'session_deleted', payload)` |
| `participant_role_changed {…}` | s→c | `rt.emit(id, 'participant_role_changed', payload)` (and, if desired, re-issue that participant's token with the new role) |
| `live_participants_updated [roster]` | s→c | Module `presence` event carries `participants` (roster) + `count` — map in the adapter |
| `participant_count_update {sessionId, count}` **global** | s→c | Module `presence` event `count`; dashboard listens on the `global` channel |
| `session_renamed {sessionId, title}` **global** | s→c | `rt.emitGlobal('session_renamed', payload)` |
| `today_top_artists_updated {…}` **global** | s→c | `rt.emitGlobal('today_top_artists_updated', payload)` (keep the 1.5 s debounce in Node) |
| `poll_results {…}` **global** | s→c | `rt.emitGlobal('poll_results', payload)` |
| `invite:accepted {…}` | s→c | `rt.emit(id, 'invite:accepted', payload)` (also **fixes** today's dead `session-host-*` room) |
| `host_left` / `participant_left` | s→c | Derived from `presence` (owner leaving → count/roster change); emit explicitly via `rt.emit` if the UI needs the distinct event |

**Latent bugs to fix in passing** (from the audit): int-vs-string room keys,
dead `req.io` emits (`proposals.js:1349`), the unused `session-host-*` room
(`invites.js:537`), inconsistent `voting_round_completed` payloads, and
disconnect-path header-only auth — all become moot or trivially fixed since
fan-out is now an explicit `rt.emit(id, …)` call keyed by the session id.

---

## 2. Presence / live viewer counts

- Drop the Socket.io `heartbeat` handler and the `session_participants` socket
  bookkeeping for realtime presence — the module tracks presence in
  `rt_participants` via client heartbeats (TTL 30 s, prune-on-read).
- `participant_count_update` and `live_participants_updated` both map to the
  module's `presence` event (`{ count, participants }`); the frontend adapter
  surfaces it as `socket.on('presence', …)`.
- If TuneVote still needs `session_participants.is_live` for REST/analytics,
  keep updating it in the REST join/leave routes; it no longer drives realtime.

---

## 3. Config — kill the hardcoded URLs

- **Frontend:** realtime base from `VITE_RT_BASE` (e.g.
  `https://api.tunevote.com/realtime`); the adapter already reads it. Remove the
  `https://api.tunevote.com` hardcoded fallbacks flagged in the audit (13 files)
  and route them through `VITE_API_URL`.
- **Node:** `RT_BASE_URL` (loopback, e.g. `http://127.0.0.1:8090/realtime`),
  `RT_SERVICE_SECRET`. Remove backend self-calls over public HTTPS
  (`sessions.js:326`) and the wrong startup log.
- **PHP module:** all `RT_*` env (see the package README); `RT_CORS_ORIGINS=https://app.tunevote.com`.

---

## 4. Database

The module owns `rt_sessions` / `rt_participants` / `rt_events` /
`rt_rate_limits` — additive, **no change to TuneVote's domain tables**. Apply
[`packages/php-realtime/migrations/001_create_rt_tables.sql`](../packages/php-realtime/migrations/001_create_rt_tables.sql)
into the `tunevote` database (they carry the `rt_` prefix, so nothing collides).
Run it against the DB inside the Docker `mysql` container on the VPS.

---

## 5. Deployment (VPS)

1. **Ship the module** to the VPS (e.g. `/var/www/realtime`), including
   `packages/php-realtime` and `integrations/tunevote/realtime.tunevote.php`.
2. **php-fpm**: install `php-fpm` + `php-mysql` (PHP 8.0+). Point a pool at the
   module.
3. **nginx**: add a `location /realtime/` on `api.tunevote.com` that fastcgi-
   passes to php-fpm with `realtime.tunevote.php` as the front controller. The
   existing `Upgrade`/`Connection` WebSocket headers for Socket.io can be
   **removed** once cutover is complete.
4. **Migrate**: run `001_create_rt_tables.sql` in the `tunevote` DB.
5. **Secrets**: set `RT_TOKEN_SECRET` and `RT_SERVICE_SECRET` in the php-fpm
   pool env and the matching `RT_SERVICE_SECRET` + `RT_BASE_URL` in the Node
   `.env`. Set `RT_CORS_ORIGINS=https://app.tunevote.com`.
6. **Node**: add `lib/rt-emit.js`, call `rt.ensureSession('global')` at boot and
   `rt.ensureSession(sessionId, ownerId)` on session create/start, and replace
   `getIO()…emit(…)` sites with `rt.emit(...)` / `rt.emitGlobal(...)`.
7. **Frontend**: add `src/realtime/realtimeClient.js` + the module client,
   swap the `io(...)` connection sites for `connectRealtime(...)`, set
   `VITE_RT_BASE`, rebuild and deploy the static bundle to cPanel.

---

## 6. Cutover & rollback

- **Feature-flag it.** Gate the frontend connection on `VITE_USE_PHP_REALTIME`:
  true → `connectRealtime(...)`, false → the existing `io(...)`. On the Node
  side, `rt.emit` runs **alongside** the existing `getIO().emit` during
  bake-in (double-emit is harmless), so you can flip the frontend flag per
  environment and roll back instantly by flipping it back.
- **Verify** (your call, per the working agreement — you test the app):
  two-tab session shows shared queue/vote/playback sync; live viewer count rises
  and falls with tabs; snapshot resync after a long disconnect; roles enforced.
- **Retire** Socket.io only after the flag has been on in prod: delete the
  `getIO()` emits, the socket server, pm2's WS assumptions, and the nginx WS
  upgrade block. The **Node process stays** (REST API) — see §0.

---

## 7. What actually retires the VPS (future)

Realtime on PHP is step one. To drop the VPS entirely you would additionally:
port the Express REST routes to PHP, move the MySQL off the VPS to a
network-reachable managed DB (so shared hosting can reach it), and replace the
Node reconciler/timers with a PHP cron tick (the DB is already authoritative via
`sessions.current_plays_until` and `voting_rounds.phase_ends_at`, so this is
mechanical). That is a separate project; this migration deliberately scopes to
the realtime layer and is honest about the boundary.
