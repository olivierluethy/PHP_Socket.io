# PHP-native real-time session backend

A reusable, **PHP-native** replacement for a Node.js/Socket.io real-time session
layer. Shared authoritative state, event fan-out, presence/live viewer counts,
roles and permissions — delivered over HTTP long-poll/SSE on standard PHP +
MySQL hosting, with **no persistent Node process and no WebSocket daemon**.

Built to retire the Socket.io VPS behind [TuneVote](https://tunevote.com), but
the module is app-agnostic and reusable across projects.

## Layout

| Path | What |
|---|---|
| [`packages/php-realtime/`](packages/php-realtime) | The reusable module (PSR-4 `Realtime\`). Start at its [README](packages/php-realtime/README.md). |
| [`packages/php-realtime/client/`](packages/php-realtime/client) | `RealtimeClient` JS library (SSE→polling, presence, reconnect). |
| [`public/`](public) | Front controller (`realtime.php`, `.htaccess`) + a styled two-tab demo. |
| [`docs/`](docs) | Architecture & migration docs (below). |

## Docs

- [`docs/REALTIME_AUDIT.md`](docs/REALTIME_AUDIT.md) — the existing Node/Socket.io layer, and what must survive migration.
- [`docs/REALTIME_BLUEPRINT.md`](docs/REALTIME_BLUEPRINT.md) — target architecture + hosting-compatibility matrix.
- [`docs/REALTIME_MIGRATION.md`](docs/REALTIME_MIGRATION.md) — event-by-event Node→PHP mapping + cutover.
- [`docs/STYLEGUIDE.md`](docs/STYLEGUIDE.md) — TuneVote visual system (source of truth for any UI here).

## Run the demo locally

```bash
# 1. Point at any MySQL and auto-create the tables for the demo:
export RT_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=realtime_demo;charset=utf8mb4'
export RT_DB_USER='root' RT_DB_PASSWORD='' RT_TOKEN_SECRET='dev-secret' RT_AUTO_MIGRATE=1

# 2. Serve:
php -S localhost:8000 public/realtime.php
```

Open <http://localhost:8000> in two tabs: **Create** in one, copy the session id
into the other and **Join**, then type in the shared note — it syncs within ~1 s,
and the live count updates from presence heartbeats.

## Design in one paragraph

Each session has one authoritative JSON state and a monotonic `version`; every
mutation is serialised per session (`GET_LOCK` + `SELECT … FOR UPDATE` + an
optimistic version check) and appends one event to a per-session log. Clients
keep a `since` cursor and pull deltas via long-poll (holding the request up to
~25 s), short-poll fallback, or SSE; if a client falls behind the retained
window it gets a full snapshot to resync. Presence is heartbeat + TTL. Roles and
tokens are server-authoritative. Storage is a pluggable driver — MySQL by
default, Redis optional. See the [blueprint](docs/REALTIME_BLUEPRINT.md).
