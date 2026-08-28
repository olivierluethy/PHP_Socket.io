# PHP-Native Real-Time — Architecture Blueprint

Target architecture for the `Realtime\` module (`packages/php-realtime`). It
delivers Socket.IO-level session features **without a persistent Node/WebSocket
process**, so it runs on conventional PHP hosting. This is the design contract;
the code implements it and [`REALTIME_MIGRATION.md`](REALTIME_MIGRATION.md) maps
TuneVote onto it.

Design goals: (1) guaranteed-deployable on shared PHP hosting, (2) no lost
updates under concurrency, (3) framework-agnostic and reusable, (4) everything
env-driven.

---

## 1. Transport — tiered, no persistent WS server

Real-time is **delta synchronisation over HTTP**, negotiated per client.

### Tier A — HTTP polling (default, always deployable)
- Clients pull deltas from `GET /sync?since={version}`.
- **Long-poll:** the request holds up to `RT_LONGPOLL_TIMEOUT` (~25 s) waiting for
  a new event, then returns (empty if nothing arrived). New events return
  immediately → typically sub-second latency.
- **Short-poll fallback:** if the host kills long requests, the client polls every
  `RT_SHORTPOLL_INTERVAL` (~1.5 s). The client auto-detects (a long-poll that
  returns near-instantly empty, repeatedly, downgrades to short-poll).
- **This tier is the baseline guarantee** — works on any host that runs PHP.

### Tier B — Server-Sent Events (optional upgrade)
- `GET /stream` holds one response open and pushes `data:` frames as events append.
- Lower latency, fewer request round-trips. **Auto-negotiated:** the client tries
  SSE first where `RT_SSE_ENABLED=true`, falls back to Tier A on any failure.
- Requires a host that allows long-lived requests / enough PHP workers.

### Explicitly out of scope
True bidirectional WebSocket push and sub-100 ms fan-out **at scale** need a
persistent process (Ratchet / Swoole / Workerman) and shared state (Redis) on a
VPS — i.e. exactly the Node problem this project removes. **We do not build a PHP
WS daemon.** For TuneVote's group-session scale, long-poll/SSE is ample.

---

## 2. State model — authoritative state + append-only event log

Each session has:
- **One authoritative state** (`rt_sessions.state`, JSON) — the snapshot a late
  joiner or resyncing client loads.
- **A monotonic `version`** (`rt_sessions.version`) — bumped on every mutation.
- **An append-only event log** (`rt_events`) with a per-session monotonic `seq`.
  **Invariant: `seq` of the event produced by a mutation == the new `version`.**
  A single counter serves as both `lastVersion` and `lastSeq`, so the client only
  tracks one cursor (`since`).

**Delta pull:** client sends `since=N`; server returns events `seq > N` and the
new cursor. **Snapshot-on-gap:** if `since < oldest_retained_seq` (client fell
behind the rolling retention window), the server returns a **full state snapshot
+ current version** instead of deltas, and the client hard-resyncs.

**Rolling retention:** keep the last `RT_EVENT_RETENTION` (~500) events per
session; older events are pruned. Gaps beyond that trigger a snapshot.

**One mutation = one event.** Derived changes are encoded in that event's payload
rather than emitting several, keeping `seq`↔`version` exactly aligned.

---

## 3. Concurrency & locking — serialise mutations per session

Every mutation runs inside `StorageDriver::mutate($sessionId, $fn)`:

1. **Acquire the per-session lock.** MySQL: `GET_LOCK('rt:{id}', timeout)` (named
   advisory lock, the documented fallback) **then** open a transaction and
   `SELECT … FROM rt_sessions WHERE id=? FOR UPDATE` (InnoDB row lock). Redis: a
   `SET key val NX PX` lock.
2. **Read** the current `SessionRecord` (version + state) under the lock.
3. **Optimistic version check.** If the action carries a `baseVersion` and it ≠
   the current version, reject with **409 VersionConflict** (stale write). Actions
   that are naturally commutative (e.g. append to queue) may omit `baseVersion`.
4. **Reduce.** The app's reducer computes the new state from `(state, action,
   actor)`.
5. **Commit atomically:** `newVersion = version + 1`; write state + version;
   append one event with `seq = newVersion`; prune old events. All in the same
   transaction.
6. **Release** the lock (commit + `RELEASE_LOCK`).

Result: mutations to a session are strictly serialised — **no race conditions,
no lost updates**. Reads (`/sync`) never take the write lock.

---

## 4. Persistence — pluggable `StorageDriver`

```
interface StorageDriver {
  createSession / findSession / updateStatus
  mutate(sessionId, fn)        // per-session lock + txn
  commitMutation(...)          // state+version+event, atomic
  eventsSince / oldestRetainedSeq / pruneEvents
  awaitEvents(...)             // optional blocking wait (Redis); null on MySQL
  upsertParticipant / findParticipant / heartbeat / removeParticipant
  activeParticipants / activeCount / pruneStaleParticipants
  rateLimitAllow(key, limit, window)
}
```

- **MySQL/MariaDB driver — mandatory, standard-hosting-safe.** PDO, InnoDB,
  `FOR UPDATE` + `GET_LOCK`, JSON columns. No extensions beyond `pdo_mysql`.
- **Redis driver — optional upgrade.** Pub/sub gives `awaitEvents()` true blocking
  fan-out (near-zero-latency long-poll/SSE without DB polling) and `SET NX` locks.
  Selected via `RT_DRIVER=redis`; the rest of the module is unchanged.

The long-poll loop asks `awaitEvents()` first; if the driver returns `null`
(MySQL), it polls `eventsSince()` every `RT_POLL_INTERVAL_US` until the deadline.

---

## 5. Presence — heartbeat + TTL

- Participants `POST /heartbeat` every `RT_HEARTBEAT_INTERVAL` (~10 s), updating
  `rt_participants.last_heartbeat_at`. Poll requests also refresh it.
- **Live count / roster** = participants whose heartbeat is within `RT_PRESENCE_TTL`
  (~30 s), computed on read.
- **Stale pruning** happens lazily on read (and can be driven by a scheduled tick):
  participants past the TTL are dropped and a `presence` event is appended so
  other clients see the count change. No persistent reaper process required.

---

## 6. Security — server-authoritative

- **Signed participant tokens** (HMAC-SHA256, JWT-like) bind `{sessionId, userId,
  role, exp}`. Issued on create/join; **required on every mutating action,
  heartbeat and stream**. The server reads session/role from the *verified token*,
  never from the request body.
- **Role/permission model** (`owner`/`host` ≥ `participant` ≥ `viewer`).
  Authorization is checked **server-side on every action** via a per-action
  minimum-role map the app declares; the client's claimed role is ignored.
- **Input validation** on every endpoint; unknown/oversized payloads rejected.
- **Rate limiting** (fixed-window) on both action and poll/sync endpoints, keyed
  per participant.
- **CORS allowlist** from config (no wildcard in production).
- Never trust client-supplied state — the reducer is the only writer.

---

## 7. Reusable module & client

- **Module** `packages/php-realtime`, PSR-4 `Realtime\`, framework-agnostic. A host
  app boots it with a `Config` and an app reducer/permission map, then dispatches
  the HTTP request into `Realtime::handle()`. Nothing app-specific leaks into the
  module; TuneVote's domain logic lives in *its* reducer, registered from the app.
- **API surface:** `POST /sessions`, `POST /sessions/{id}/join`,
  `POST /sessions/{id}/leave`, `POST /sessions/{id}/actions`,
  `GET /sessions/{id}/sync?since=`, `GET /sessions/{id}/stream`,
  `POST /sessions/{id}/heartbeat`.
- **JS `RealtimeClient`** mirrors Socket.IO ergonomics: `connect()`, `on(event,cb)`,
  `emit(action,payload)`, presence heartbeats, SSE→polling fallback, auto-reconnect
  with backoff — so the frontend migration is mechanical (`socket.on` →
  `client.on`, `socket.emit` → `client.emit`).

---

## 8. Scalability

- **Single shared-hosting instance:** long-poll holds one PHP worker per waiting
  client for up to the timeout. Fine for many small/medium group sessions
  (TuneVote scale). Sizing = concurrent viewers ≈ peak held workers; short-poll
  fallback relieves worker pressure at a small latency cost.
- **Horizontal scale:** switch `RT_DRIVER=redis` for pub/sub fan-out + distributed
  locks so multiple PHP nodes share state and long-poll/SSE wake instantly. The
  DB remains the durable source of truth.
- **Sub-second fan-out to hundreds/thousands:** requires the persistent-infra path
  (Redis + SSE workers, or a WS daemon) — out of scope for the standard-hosting
  target, documented as an upgrade.

---

## 9. Hosting-compatibility matrix

| Capability | Shared PHP hosting (cPanel etc.) | VPS / more workers | + Redis |
|---|---|---|---|
| Session CRUD, actions, delta sync | ✅ | ✅ | ✅ |
| Authoritative state + event log + snapshot resync | ✅ | ✅ | ✅ |
| Per-session locking (no lost updates) | ✅ `GET_LOCK`+`FOR UPDATE` | ✅ | ✅ Redis locks |
| Presence / live viewer counts | ✅ (prune-on-read) | ✅ | ✅ |
| Roles, tokens, rate limiting, CORS | ✅ | ✅ | ✅ |
| **Long-poll** (~sub-second when active) | ✅ (holds 1 worker/client ≤25 s) | ✅ better | ✅ instant wake (no DB poll) |
| **Short-poll** fallback | ✅ | ✅ | ✅ |
| **SSE** (Tier B, lower latency) | ⚠️ only if long-lived requests allowed | ✅ | ✅ |
| Scheduled prune/reconcile tick | ⚠️ cron if available; else lazy-on-read | ✅ | ✅ |
| Sub-100 ms fan-out to hundreds+ | ❌ | ⚠️ limited | ✅ (with SSE workers) |
| True bidirectional WebSocket push | ❌ (out of scope) | ⚠️ needs WS daemon | ⚠️ needs WS daemon |

Legend: ✅ supported · ⚠️ conditional · ❌ not on this tier.

**Bottom line:** everything TuneVote uses today — shared queue, votes,
playback/queue sync, live viewer counts, roles, shared state — runs on the ✅
column, i.e. **plain PHP + MySQL, no persistent process, no VPS.** SSE, instant
fan-out and large-scale push are opt-in upgrades, not requirements.
