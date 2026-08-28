# TuneVote integration

Reference artifacts for migrating TuneVote's Socket.io realtime onto php-realtime
**without rewriting TuneVote's Node domain logic**. Node keeps auth, queue,
votes, playback and the reconciler; it just fans out events to the PHP module
instead of Socket.io. See [`../../../../docs/REALTIME_MIGRATION.md`](../../../../docs/REALTIME_MIGRATION.md)
for the full event map and cutover runbook.

| File | Where it goes | What it does |
|---|---|---|
| `realtime.tunevote.php` | VPS (php-fpm behind nginx), routed as `/realtime/*` | Boots the module against the shared `tunevote` MySQL; owner role resolver; the ephemeral `song_reaction` action. |
| `rt-emit.js` | `tunevote_api/lib/` | `rt.emit(sessionId, type, payload)` / `rt.emitGlobal(...)` / `rt.ensureSession(...)` — drop-in for `getIO().to(...).emit(...)`. |
| `realtimeClient.js` | `tunevote_frontend/src/realtime/` | `connectRealtime({ sessionId, userId, token })` — a Socket.io-compatible `.on/.emit/.disconnect` over `RealtimeClient`. |

## Why this shape

- **DB reachability:** the only MySQL is loopback-only on the VPS, so the PHP
  module runs on the VPS too (php-fpm), sharing the DB with the `rt_` prefix.
- **Minimal risk:** domain logic is untouched; each `getIO()...emit()` becomes a
  one-line `rt.emit(...)`; each `io(...)` becomes `connectRealtime(...)`.
- **Honest scope:** this retires the Socket.io server, the WebSocket-upgrade
  nginx config and the persistent-connection scaling limit. It does **not** by
  itself retire the VPS — the Node REST API still runs there. Retiring the VPS
  entirely means porting the REST API to PHP, which is out of scope for a
  realtime module.

## Node side (sketch)

```js
const rt = require('./lib/rt-emit');

// when a session is created/started:
await rt.ensureSession(sessionId, ownerUserId, {});

// anywhere you emit today:
// getIO().to(sessionId).emit('queue_updated', {})   ->
await rt.emit(sessionId, 'queue_updated', {});
// getIO().emit('participant_count_update', {...})    ->  (global)
await rt.emitGlobal('session_renamed', { sessionId, title });

// playback sync carries authoritative fields + a snapshot patch so late
// joiners are correct:
await rt.emit(sessionId, 'playback_sync', payload, { nowPlaying: payload });
```

## Frontend side (sketch)

```js
// PlaybackContext.jsx / SessionPage.jsx
import { connectRealtime } from '../realtime/realtimeClient';

const socket = connectRealtime({ sessionId, userId: user?.id, token });
socket.on('playback_sync', applySync);
socket.on('queue_updated', reloadQueue);
socket.on('presence', ({ count }) => setViewers(count));   // replaces participant_count_update
socket.emit('song_reaction', { emoji });
// on cleanup:
socket.disconnect();
```

Presence/live counts come from the module's `presence` event (heartbeats are
automatic), so `participant_count_update` / `live_participants_updated` map onto
`presence`. Global events use the reserved `global` channel — call
`rt.ensureSession('global')` once at Node boot and have dashboard/home sockets
`connectRealtime({})`.
