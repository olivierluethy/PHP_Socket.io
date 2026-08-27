# PHP-native real-time session backend

A PHP-only alternative to a Node.js/Socket.io real-time session layer (issue #1).
Multi-user sessions — join, shared state, participants, event fan-out — with **no
persistent Node process** and **no database**, so it runs on standard PHP hosting.

Transport: **long-polling** (portable everywhere). Storage: **file-based JSON +
append-only NDJSON event log**, coordinated with `flock()` for safe concurrency.

## Run locally

```bash
php -S localhost:8000        # from the repo root
```

Then open <http://localhost:8000/public/index.html> in **two** browser tabs:
create a session in one, copy the id into the other and Join, and type in the
shared note — it syncs across tabs within ~1s. That is the acceptance proof.

## HTTP API

| Endpoint | Method | Body / query | Response |
|---|---|---|---|
| `api/create.php` | POST | `{name, state?}` | `{sessionId, hostId, cursor:0}` |
| `api/join.php` | POST | `{sessionId, name}` | `{participantId, snapshot, participants, cursor}` |
| `api/leave.php` | POST | `{sessionId, participantId}` | `{ok:true}` |
| `api/publish.php` | POST | `{sessionId, participantId, type, data}` | `{seq}` |
| `api/poll.php` | GET | `?sessionId=&participantId=&since=` | `{events, cursor}` (blocks ≤25s) |
| `api/state.php` | GET | `?sessionId=` | `{snapshot, participants, cursor}` |

`type` values `state.patch` (shallow-merge `data` into the shared snapshot),
`participant.join` / `participant.leave` are understood; any other `type` (e.g.
`chat.msg`, `playback.seek`) is opaque and just fanned out.

## Honest limits

- **Long-poll holds one PHP worker per waiting client** (up to ~25s). Great for
  small group sessions (TuneBot-style); it is not a chat server for hundreds.
- No true WebSocket push, no sub-100ms sync — those need a persistent process
  (Ratchet/Swoole/Workerman) on a VPS, and horizontal scaling needs shared
  state (Redis). Out of scope for this shared-hosting MVP.
- SSE and presence-pruning (idle participant timeout) are the natural next slice.
