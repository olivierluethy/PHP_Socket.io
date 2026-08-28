# TuneVote Real-Time Layer — Audit (pre-migration)

Snapshot of the **current** Node.js + Socket.IO real-time layer that this project
replaces, and the checklist of behaviour that must survive the move to a
PHP-native transport. Sources: `tunevote_api` (Express 5 + Socket.IO 4.8 + MySQL
8) and `tunevote_frontend` (Vite/React + `socket.io-client` 4.8).

> Companion docs: [`REALTIME_BLUEPRINT.md`](REALTIME_BLUEPRINT.md) (target
> architecture) and [`REALTIME_MIGRATION.md`](REALTIME_MIGRATION.md)
> (event-by-event Node→PHP mapping + cutover).

---

## 1. Why Node is required today

1. **Persistent WebSocket server.** Socket.IO (`lib/io.js`, `socket.js`) keeps a
   long-lived process holding one connection per client for server→client push.
   This is the *only* structural reason a persistent runtime exists, and the only
   thing that forces the paid VPS.
2. **In-memory timers**, `sessionTimers` / `phaseTimers` in `playback.js` — song
   advance and voting-phase transitions via `setTimeout`. **Explicitly
   non-authoritative:** the DB is the source of truth (`sessions.current_plays_until`,
   `voting_rounds.phase_ends_at`) and a **reconciler rebuilds all work from the DB
   every 2 s** (`scheduler.js`). A scheduled PHP tick replaces both.
3. **Single-process pub/sub.** `getIO()` is a process-global singleton; no Redis
   adapter, so there is no horizontal scaling today. `scheduler.js` already notes a
   `GET_LOCK` MySQL advisory lock would be needed for multi-instance.
4. **Boot-time schedulers** (reconciler, weekly-summary emailer) started in
   `index.js`.

**Deployment shape:** single Ubuntu VPS; API under **PM2** as `tunevote_api`
(`node index.js`) on `:4000`; **nginx** reverse-proxies `api.tunevote.com` →
`127.0.0.1:4000` and *must* inject WebSocket `Upgrade` headers or Socket.IO
fails; MySQL loopback-only; frontend is a static Vite build on separate cPanel
hosting. **The WS-upgrade nginx requirement and the whole VPS disappear with
polling/SSE.**

---

## 2. Socket.IO events (complete inventory)

Single default namespace `/`. **Rooms = the session id** (clients
`socket.join(sessionId)` from the handshake `?sessionId=`); a connection with no
`sessionId` joins no room and exists purely to receive **global** emits (the
dashboard/home listeners).

### Client → Server

| Event | Payload | Handler | Purpose |
|---|---|---|---|
| `connection` | handshake `query.sessionId`, `auth.token`/`auth.guestToken`, headers `authorization`/`x-guest-token` | `socket.js:18` | Join session room, resolve identity, start heartbeat. |
| `heartbeat` | — | `socket.js:81` → `touchLastSeen` | `UPDATE session_participants SET last_seen=NOW()`. |
| `song_reaction` | `{ emoji }` (∈ `😍 🔥 👏 🎉 😴`) | `socket.js:87` | Ephemeral reaction, 5/s rate-limited, re-broadcast; never persisted. |
| `disconnect` | — | `socket.js:96` | Set `is_live=0`, broadcast roster+count, `participant_left`, `host_left` if owner. |

### Server → Client (room-scoped unless **GLOBAL**)

| Event | Payload | Meaning |
|---|---|---|
| `song_reaction_broadcast` | `{ emoji }` | Reaction fan-out. |
| `participant_left` | `{ participantId, isGuest, hostLeft? }` | Someone left. |
| `host_left` | `{ sessionId }` | Host disconnected. |
| `live_participants_updated` | `[{ participantId, userId, name, role, isHost, isCoHost, promotable, profileImage }]` | Live-participant roster. |
| `participant_count_update` **GLOBAL** | `{ sessionId, count }` | **Live viewer count** (dashboard). |
| `session_renamed` **GLOBAL** | `{ sessionId, title }` | Title changed. |
| `session_started` | `{ autoStarted, firstVideoId, video_start_time, server_time }` | Session went live. |
| `playback_sync` | `{ current_queue_item_id, current_video_id, current_title, video_start_time, server_time, is_playing }` | **Authoritative now-playing sync** (§4). |
| `pause_started` | `{ queue_item_id, title, duration, startTime }` | A "pause" item started. |
| `queue_empty` | — | Started but queue empty. |
| `queue_updated` | `{}` | Queue changed → client refetches queue. |
| `proposals_updated` | `{}` | Suggestions changed → client refetches proposals. |
| `voting_phase_changed` | `{ phase:'suggestion'\|'voting', endsAt, roundId, duration }` | Phase transition. |
| `suggesting_phase_started` | `{ roundId, endsAt, duration }` | New suggestion round. |
| `voting_round_completed` | `{ winnerId, roundId, emergency? }` *(shape inconsistent: also `{winner}`)* | Round closed. |
| `regenerate_suggestions` | `{ roundId }` | AI suggestions regenerated. |
| `session_ended` | `{ reason, message }` | Session terminated (also `socketsLeave`). |
| `session_deleted` | `{ message }` | Host deleted session. |
| `participant_role_changed` | `{ sessionId, participantId, userId, role }` | Co-host promote/demote. |
| `today_top_artists_updated` **GLOBAL**, debounced 1.5 s | `{ date, artists:[…] }` | Live "today top artists" chart. |
| `poll_results` **GLOBAL** | `{ pollId, options, percentages }` | Live poll tally. |
| `invite:accepted` | `{ id, invitee_email, invitee_name, accepted_at }` | Invite accepted *(currently emitted to a room nobody joins — dead)*. |

### Frontend usage (what actually consumes the above)

Four **independent** `io()` connections, no shared hook:
- **PlaybackContext.jsx** (global, persists across routes): emits `heartbeat` (10 s); listens `playback_sync`, `session_started`, `voting_phase_changed`, `pause_started`, `pause_ended`, `session_ended`, `proposals_updated`, `queue_updated`.
- **SessionPage.jsx**: emits `join-session-host` / `leave-session-host`; listens `queue_updated`, `proposals_updated`, `session_started`, `regenerate_suggestions`, `participant_role_changed`, `live_participants_updated`, `session_ended`, `session_renamed`, `invite:accepted`, `suggesting_phase_started`.
- **Dashboard.jsx** (unauthenticated global): listens `participant_count_update`, `session_renamed`.
- **HomePoll.jsx** (unauthenticated global): listens `poll_results`.
- **ReactionBar.jsx**: emits `song_reaction`, listens `song_reaction_broadcast` (shares SessionPage's socket).

Server URL: `SOCKET_SERVER = (VITE_API_URL || "https://api.tunevote.com") + "/"`.
Auth handshake: `io(SOCKET_SERVER, { query:{ sessionId }, auth:{ token | guestToken }})`.

---

## 3. Capabilities that MUST survive the migration

- **Shared song queue** — add/remove/reorder; `queue_updated` fan-out; clients refetch `GET /sessions/:id/queue`.
- **Vote casting** — one vote per identity per proposal; drives round completion and the live charts/polls.
- **Playback / queue sync** — authoritative now-playing derived from the DB (§4); clients stay in sync via `playback_sync` with a server clock for drift correction.
- **Presence-based live viewer counts** — per-session count + full roster, self-healing after closed tabs (§5).
- **Roles / permissions** — host / co-host / user / guest, enforced server-side (§6).
- **Shared session state / resources** — proposals, phases (`suggesting`/`voting`), pauses, rename, session lifecycle (`draft`/`live`/`ended`).
- **Global/dashboard channels** — viewer counts, renames, poll results, "today top artists" seen by clients not in a session room.
- **Ephemeral reactions** — emoji fan-out, rate-limited, never persisted.

---

## 4. Authoritative playback state (the core to reproduce)

There is **no** `current_queue_item_id` column — it is the `id` of the
`queue_items` row with `status='playing'`. `playback_sync` is fully derivable:

- `queue_items.status='playing'` → the single now-playing row per session.
- `queue_items.started_at_ms` (BIGINT epoch ms) + `startedAt` → playback start reference for client clock-offset sync.
- `current_video_id` = row's `video_id`; `current_title` from `youtube_video_cache.title`; `is_playing` from `item_type` (music=true, pause=false).
- `sessions.current_plays_until` (DATETIME) → durable deadline; the reconciler advances any live session past its deadline.
- The old `playback_sync` **table was dropped** — treat `queue_items` as truth.

---

## 5. Presence / live viewer counts

- **Source of truth:** `session_participants.is_live=1` + `last_seen`. No cached counter.
- **Count:** `SELECT COUNT(*) … is_live=1` → `participant_count_update` (global).
- **Roster:** `is_live=1` rows joined to users/guests → `live_participants_updated` (room).
- **Heartbeat:** client emits `heartbeat` → refreshes `last_seen`; **30 s** grace.
- **Reaper:** reconciler every 2 s sets `is_live=0` where `last_seen < NOW()-30s`, then rebroadcasts the count. This is what makes counts self-heal.

**PHP equivalent:** heartbeat becomes a periodic `POST /heartbeat` (or piggybacks on the poll); the reaper becomes a scheduled tick / lazy prune-on-read; counts are computed on read.

---

## 6. Roles / permissions

- `host` = `sessions.user_id` (exactly one); `co-host` (host powers minus delete/role-change); `user` / `guest` (no powers).
- **All privileged mutations already go through REST** (`services/permissions.js`: rename & AI-genre = host/co-host; role change & delete = host only; proposal delete = owner/host). Socket handlers do **almost no** authz beyond identity resolution.
- This is convenient: authz stays in the HTTP endpoints; the real-time layer only needs fan-out + presence.

---

## 7. Auth

- **JWT** (registered): `Authorization: Bearer <jwt>`, `JWT_SECRET`, payload `{id}`.
- **Guest token** (anonymous): opaque string in `guest_users.guest_token`, sent as header `x-guest-token` (REST) or `auth.guestToken` (socket).
- No session cookies. `sessionId` in the socket query is the *room*, not a credential.
- Socket handshake auth is **inconsistent** (heartbeat reads `auth.*` w/ header fallback; disconnect reads headers only → a client authed via `auth` isn't cleaned up on disconnect; the reaper is the backstop). REST re-authenticates on every call. The PHP backend reuses the exact JWT + guest-token scheme and drops socket-handshake auth entirely.

---

## 8. Database schema touched by real-time

- **`sessions`**: `id`, `user_id` (owner), `title`, `is_live`, `status ENUM('draft','live','ended')`, `current_plays_until DATETIME` (+ index `idx_sessions_live_deadline`).
- **`session_participants`**: `id`, `session_id`, `user_id?`, `guest_id?`, `role ENUM('host','co-host','user','guest')`, `is_live`, `last_seen`, `joined_at`, `left_at`; unique `(session_id,user_id)` / `(session_id,guest_id)`.
- **`queue_items`**: `id`, `session_id`, `video_id`, `status ENUM('queued','playing','played','skipped','archived','suggested')`, `startedAt`, `started_at_ms BIGINT`, `item_type ENUM('music','pause')`, `voting_round_id`, indexes `idx_session_status`/`idx_round_status`/`idx_status`.
- **`votes`**: `queue_item_id`, `user_id?`, `guest_id?`, unique `(queue_item_id,user_id)` / `(queue_item_id,guest_id)`.
- **`voting_rounds`**: `session_id`, `state ENUM('suggesting','voting','closed')`, `phase_ends_at`, `winner_queue_item_id`, index `idx_voting_rounds_state`.
- **`session_invites`**, **`guest_users`** (`guest_token`), **`polls`/`poll_options`/`poll_votes`**, chart joins for `today_top_artists`.

The new module adds its own `rt_sessions` / `rt_participants` / `rt_events`
tables (append-only log + authoritative JSON state + version) and **does not
require** rewriting the domain tables above — TuneVote's reducers read/write the
existing domain tables and project the sync-relevant slice into `rt_sessions.state`.

---

## 9. Architectural mistakes to avoid (observed here)

- **Hardcoded prod URLs toggled before every push.** `https://app.tunevote.com` / `https://api.tunevote.com` are hardcoded in ~13 frontend files and several backend routes (incl. a backend self-call `sessions.js:326`, and a startup log printing the wrong URL). → **Everything env-driven, no toggling.**
- **Frontend historically co-hosted on the VPS.** → Frontend is static (cPanel); nothing real-time-specific ties it to a Node box.
- **In-memory-only pub/sub, no shared state.** → DB-authoritative state + event log; Redis optional, never assumed.
- **Wide-open `cors:{origin:"*"}`** everywhere, no allowlist. → Config-driven CORS allowlist.
- **Latent bugs to fix while migrating:** int-vs-string room keys (some emits never reach clients); dead `req.io` emits (`proposals.js:1349`); unused `session-host-*` room (`invites.js:537`); inconsistent `voting_round_completed` payloads; disconnect-path header-only auth.
- **A committed YouTube API key** (`VITE_YOUTUBE_KEY`) in frontend `.env` — flagged for rotation; out of scope for this module but noted.
