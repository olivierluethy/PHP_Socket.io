# php-realtime

A framework-agnostic, **PHP-native real-time session backend**. It gives you
Socket.io-style session features — shared authoritative state, event fan-out,
presence/live counts, roles — **without a persistent Node/WebSocket process**,
so it runs on conventional PHP + MySQL hosting.

- **Transport:** long-poll (baseline, works anywhere) → short-poll fallback →
  optional SSE (Tier B). No WebSocket daemon.
- **State:** one authoritative JSON snapshot per session + a monotonic `version`
  and an append-only event log; clients pull deltas and get a snapshot on gap.
- **Concurrency:** every mutation is serialised per session (`GET_LOCK` +
  `SELECT … FOR UPDATE`) with an optimistic version check — no lost updates.
- **Storage:** pluggable `StorageDriver`; **MySQL/MariaDB** driver mandatory,
  **Redis** driver optional.
- **Security:** HMAC-signed participant tokens, server-authoritative roles,
  input validation, rate limiting, config-driven CORS.

See the design docs: [`../../docs/REALTIME_BLUEPRINT.md`](../../docs/REALTIME_BLUEPRINT.md).

## Install

Composer:

```bash
composer require tunevote/php-realtime
```

…or, on a host without Composer, just `require` the bundled autoloader:

```php
require 'packages/php-realtime/autoload.php';
```

Then create the tables (`migrations/001_create_rt_tables.sql`), or call
`(new MySqlStorageDriver($config))->ensureSchema()` for local dev.

## Mount it (a few lines)

```php
use Realtime\Realtime;
use Realtime\Domain\Role;
use Realtime\Service\ActionContext;
use Realtime\Service\ActionResult;

Realtime::fromEnv()
    // Register your domain actions (nothing app-specific lives in the module).
    ->registerAction('queue.add', function (ActionContext $c): ActionResult {
        $state = $c->state();
        $state['queue'][] = $c->payload['item'] ?? null;
        return ActionResult::state($state);
    })
    ->setPermission('playback.set', Role::OWNER)   // per-action minimum role
    ->setDefaultPermission(Role::PARTICIPANT)
    ->run();                                        // routes + streams + sends
```

Point your web server so `/realtime/*` hits that front controller (see
`public/realtime.php` and `public/.htaccess` in the repo root for an example).

## Configuration (environment)

All config is env-driven — no hardcoded URLs.

| Var | Default | Purpose |
|---|---|---|
| `RT_DRIVER` | `mysql` | `mysql` or `redis` |
| `RT_DB_DSN` | — | PDO DSN, e.g. `mysql:host=127.0.0.1;dbname=app;charset=utf8mb4` |
| `RT_DB_USER` / `RT_DB_PASSWORD` | — | DB credentials |
| `RT_TABLE_PREFIX` | `rt_` | Table prefix (share a DB with your app) |
| `RT_REDIS_URL` | — | `redis://host:port[/db]` for the Redis driver |
| `RT_TOKEN_SECRET` | — | **Set this.** HMAC secret for participant tokens |
| `RT_TOKEN_TTL` | `43200` | Token lifetime (s) |
| `RT_LONGPOLL_TIMEOUT` | `25` | Max seconds a `/sync` holds open |
| `RT_SHORTPOLL_INTERVAL` | `1500` | Client short-poll interval hint (ms) |
| `RT_SSE_ENABLED` | `true` | Advertise/allow the SSE transport |
| `RT_PRESENCE_TTL` | `30` | Seconds before a silent participant is stale |
| `RT_HEARTBEAT_INTERVAL` | `10` | Recommended client heartbeat (s) |
| `RT_EVENT_RETENTION` | `500` | Events kept per session before snapshot-on-gap |
| `RT_ACTION_RATE_LIMIT` / `RT_POLL_RATE_LIMIT` | `30` / `120` | Per-participant per-window |
| `RT_RATE_WINDOW` | `10` | Rate-limit window (s) |
| `RT_CORS_ORIGINS` | `*` | Comma-separated allowlist |

## HTTP API

| Method & path | Body / query | Returns |
|---|---|---|
| `POST /sessions` | `{ownerId?, state?, meta?}` | `{sessionId, userId, role, token, snapshot}` |
| `POST /sessions/{id}/join` | `{userId?, meta?}` | `{sessionId, userId, role, token, snapshot}` |
| `POST /sessions/{id}/leave` | — (token) | `{ok:true}` |
| `POST /sessions/{id}/actions` | `{type, payload?, baseVersion?}` (token) | `{version, event}` |
| `GET /sessions/{id}/sync?since=` | (token) | `{kind:'events', events, cursor}` or `{kind:'snapshot', snapshot}` |
| `GET /sessions/{id}/stream?since=` | `?token=` | SSE stream (Tier B) |
| `POST /sessions/{id}/heartbeat` | — (token) | `{count, participants, nextHeartbeatMs}` |
| `POST /sessions/{id}/emit` | `{type, payload?, statePatch?}` (`X-Realtime-Service` secret) | `{version, event}` — trusted server-to-server fan-out (see the TuneVote integration) |

Tokens are sent as `Authorization: Bearer <token>`, the `X-Realtime-Token`
header, or `?token=` (for SSE `EventSource`).

## Browser client

```js
import { RealtimeClient } from '.../client/realtime-client.mjs';

const client = new RealtimeClient('https://api.example.com/realtime');
await client.join(sessionId, { meta: { name: 'Ada' } });
client.on('snapshot', (s) => render(s.state));
client.on('presence', (p) => setViewers(p.count));
client.on('queue.add', () => reloadQueue());
await client.emit('queue.add', { item });
```

## What's out of scope

True bidirectional WebSocket push and sub-100 ms fan-out **at scale** need a
persistent process + Redis on a VPS — the very thing this replaces. For
group-session workloads, long-poll/SSE is ample. See the hosting-compatibility
matrix in the blueprint.
