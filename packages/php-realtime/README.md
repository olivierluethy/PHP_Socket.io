# php-realtime

A framework-agnostic, **PHP-native real-time session backend**. It gives you
Socket.IO-style session features — shared authoritative state, event fan-out,
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

## Contents

- [Why not just use Socket.IO?](#why-not-just-use-socketio)
- [Install](#install)
- [Quick start](#quick-start-a-shared-queue-in-40-lines) — a shared queue in ~40 lines
- [Core concepts](#core-concepts)
- [Server-side usage](#server-side-usage)
- [Browser client](#browser-client)
- [How a change propagates](#how-a-change-propagates)
- [Configuration](#configuration-environment)
- [HTTP API](#http-api)
- [Framework integration](#framework-integration)
- [Deployment & hosting](#deployment--hosting)
- [Security checklist](#security-checklist)
- [Tests](#tests)
- [FAQ / troubleshooting](#faq--troubleshooting)
- [What's out of scope](#whats-out-of-scope)

## Why not just use Socket.IO?

Socket.IO is excellent — but it needs a **persistent Node process holding a
WebSocket per client**, which forces a VPS and a separate runtime alongside your
PHP app. If your app is already PHP on shared/cPanel-style hosting, that's a
whole extra box, deploy pipeline and failure mode for what is usually a handful
of small group sessions. `php-realtime` gives you the same session ergonomics
over plain HTTP.

| | Socket.IO (Node) | php-realtime |
|---|---|---|
| Runtime | Persistent Node process | Your existing PHP-FPM/Apache |
| Transport | WebSocket (+ polling fallback) | Long-poll → short-poll → optional SSE |
| Hosting | VPS (nginx WS upgrade) | Shared PHP + MySQL; no daemon |
| Server→client push | Native, sub-100 ms | ~sub-second (long-poll/SSE) |
| Authoritative state | You build it | Built in (JSON snapshot + version) |
| Lost-update safety | You build it | Built in (per-session lock + version check) |
| Presence / counts | You build it | Built in (heartbeat + TTL) |
| Reconnect / resync | Client library | Built in (snapshot-on-gap + backoff) |
| Horizontal scale | Redis adapter | Redis driver (optional) |
| Migration | — | `socket.on/emit` → `client.on/emit` |

**Rule of thumb:** if you need sub-100 ms fan-out to thousands of concurrent
clients, keep a persistent process. For collaborative group sessions on PHP
hosting, this replaces the Node box entirely. See the
[hosting matrix](../../docs/REALTIME_BLUEPRINT.md#9-hosting-compatibility-matrix).

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

**Requires:** PHP ≥ 8.1, `ext-json`, `ext-pdo` (`pdo_mysql`). Redis driver needs
`ext-redis`.

## Quick start: a shared queue in ~40 lines

A tiny collaborative "queue" where anyone can add an item and only the owner can
clear it. Nothing app-specific lives in the module — your domain logic is just
handlers you register.

**`realtime.php`** (your front controller; point `/realtime/*` at it):

```php
<?php
require __DIR__ . '/vendor/autoload.php'; // or packages/php-realtime/autoload.php

use Realtime\Realtime;
use Realtime\Domain\Role;
use Realtime\Service\ActionContext;
use Realtime\Service\ActionResult;

Realtime::fromEnv()
    // Anyone in the session may add to the queue.
    ->registerAction('queue.add', function (ActionContext $c): ActionResult {
        $state = $c->state();
        $state['queue'][] = $c->payload['item'] ?? null;
        return ActionResult::state($state);
    })
    // Only the owner may clear it.
    ->registerAction('queue.clear', function (ActionContext $c): ActionResult {
        $state = $c->state();
        $state['queue'] = [];
        return ActionResult::state($state);
    })
    ->setPermission('queue.clear', Role::OWNER)   // per-action minimum role
    ->setDefaultPermission(Role::PARTICIPANT)     // everything else: participants
    ->run();                                       // routes + streams + sends
```

**In the browser:**

```html
<script src="/realtime/realtime-client.js"></script>
<script type="module">
  // 1. Create a session (owner) — or join an existing one.
  const created = await RealtimeClient.createSession('/realtime', { state: { queue: [] } });

  const client = new RealtimeClient('/realtime');
  client.attach(created);        // owner token + snapshot from step 1
  client.connect();

  // 2. React to shared state.
  client.on('snapshot', (s) => renderQueue(s.state.queue));
  client.on('queue.add', () => renderQueue(client.state.queue));
  client.on('queue.clear', () => renderQueue([]));
  client.on('presence', (p) => setViewerCount(p.count));

  // 3. Mutate — every other client sees it within ~1 s.
  await client.emit('queue.add', { item: 'first song' });
</script>
```

A second visitor joins with `await client.join(sessionId)` and gets the current
snapshot plus every subsequent event. That's the whole loop.

## Core concepts

- **Session** — a room with an id, an owner, authoritative `state` (arbitrary
  JSON), a monotonic `version`, and an event log.
- **Action** — a named mutation (`queue.add`, `vote.cast`, …). You register a
  **handler** `callable(ActionContext): ActionResult`; it computes the next state
  from the current state, the actor and the payload. The module runs it under the
  per-session lock, appends one event, and bumps the version.
- **Event** — one entry in the append-only log: `{ seq, type, payload, actorId,
  ts }`. **Invariant: `seq` == the `version` produced by that mutation**, so a
  client only ever tracks a single cursor.
- **Snapshot** — the full current state (`{ state, version, status,
  participants, viewerCount }`), served on join and whenever a client falls
  behind the retained event window.
- **Role** — `owner`/`host` (rank 2) ≥ `participant` (1) ≥ `viewer` (0). Roles
  come only from the **verified token**, never the request body.
- **Built-in actions** (no code needed for simple apps): `state.patch`
  (shallow-merge), `state.set` (merge; `null` deletes a key), `state.replace`.

## Server-side usage

### Registering actions and permissions

```php
use Realtime\Realtime;
use Realtime\Domain\Role;
use Realtime\Service\{ActionContext, ActionResult};

$rt = Realtime::fromEnv()
    ->registerAction('vote.cast', function (ActionContext $c): ActionResult {
        $state = $c->state();
        // The actor is server-verified — trust $c->actor, not the payload.
        $state['votes'][$c->actor->userId] = $c->payload['choice'] ?? null;

        // The broadcast event can differ from the request: here we emit a
        // server-derived 'vote_tally' instead of echoing the raw vote.
        return ActionResult::state($state, eventPayload: [
            'total' => count($state['votes']),
        ], eventType: 'vote_tally');
    })
    ->setPermission('vote.cast', Role::PARTICIPANT)
    ->setPermission('round.close', Role::OWNER)
    ->setDefaultPermission(Role::VIEWER);   // read-only by default
```

`ActionResult::state($newState, $eventPayload = null, $eventType = null)` — pass
`$eventPayload`/`$eventType` to broadcast something other than the request (e.g.
receive `{emoji}` but broadcast `{emoji, actorId, ts}`). Omit them to mirror the
request.

### Assigning roles on join

By default the session owner gets `owner` and everyone else your default join
role. Supply a resolver to derive roles from your own auth (a JWT you've already
verified, a DB lookup, …):

```php
$rt->setRoleResolver(function ($session, ?string $userId, array $meta): string {
    if ($userId !== null && isCoHost($session->id, $userId)) {
        return Role::HOST;           // host == owner rank
    }
    return Role::PARTICIPANT;
});
```

### Sharing your app's PDO / custom storage

```php
$rt->usePdo($existingPdo);           // MySQL driver, share the app connection
// or
$rt->useDriver(new MyStorageDriver()); // anything implementing StorageDriver
```

### Server-to-server fan-out (no participant token)

If an existing backend keeps its own domain logic and just wants the module to
**broadcast + persist** an event (e.g. a Node REST API, a webhook, a cron tick),
call `POST /sessions/{id}/emit` with the shared service secret. `statePatch` is
shallow-merged into the authoritative state:

```bash
curl -X POST https://api.example.com/realtime/sessions/$SID/emit \
  -H "X-Realtime-Service: $RT_SERVICE_SECRET" \
  -H "Content-Type: application/json" \
  -d '{ "type": "session_renamed", "payload": {"title": "New title"},
        "statePatch": {"title": "New title"} }'
```

Set `RT_SERVICE_SECRET` to enable it; leave it empty to disable the endpoint.
See [`integrations/tunevote/`](integrations/tunevote) for a Node adapter.

### Driving the service directly

The HTTP kernel is optional — you can call the service from your own code:

```php
$service = Realtime::fromEnv()->service();
$res = $service->create(ownerId: 'u1', initialState: ['queue' => []]);
$snapshot = $service->snapshot($res['sessionId']);
```

## Browser client

Load it as a `<script>` (`window.RealtimeClient`), via CommonJS, or the `.mjs`
shim for bundlers:

```js
import { RealtimeClient } from '.../client/realtime-client.mjs';
```

### Constructing & connecting

```js
const client = new RealtimeClient('https://api.example.com/realtime', {
  transport: 'auto',   // 'auto' (SSE→poll) | 'sse' | 'poll'
  heartbeatMs: 10000,  // presence heartbeat (server can override)
  maxBackoffMs: 30000, // reconnect backoff ceiling
});
```

Three ways to get connected:

```js
// A) Create a brand-new session (this client becomes the owner).
const data = await RealtimeClient.createSession('/realtime', { state: { queue: [] } });
client.attach(data); client.connect();

// B) Join an existing session by id (module issues a token).
await client.join(sessionId, { meta: { name: 'Ada' } });

// C) Attach a token your OWN backend minted (SSO/JWT flows), then connect.
client.attach({ sessionId, token, snapshot, userId, role });
client.connect();
```

### Receiving

```js
client.on('snapshot', (snap) => render(snap.state));   // full state (join + resync)
client.on('presence', (p) => setViewers(p.count));     // { count, participants, event? }
client.on('vote_tally', (payload, ev) => update(payload)); // your event types
client.on('*', (ev) => console.debug(ev.type, ev));    // wildcard: every event
client.on('connect', () => setOnline(true));           // transport (re)connected
client.on('disconnect', () => setOnline(false));       // transport dropped

client.state; // the latest cached authoritative state (also snap.state)
```

The event name you listen for is the event's `type` — i.e. the action type, or
whatever `eventType` the handler chose. Presence events additionally fire under
`'presence'`.

### Sending & optimistic concurrency

```js
// Fire-and-forget mutation.
await client.emit('queue.add', { item: 'song' });

// Guarded write: reject if someone else moved the state first.
try {
  await client.emit('playback.set', { videoId }, { baseVersion: client.version });
} catch (e) {
  if (e.conflict) {           // HTTP 409 — you were stale
    // client already resynced via its poll/SSE loop; retry against fresh state
  } else {
    throw e;
  }
}

await client.leave();         // best-effort leave (also fires on tab close)
```

## How a change propagates

```
Client A                    Server (per-session lock)               Client B
  |  emit('queue.add') ───────▶ authorize (role from token)            |
  |                             rate-limit check                        |
  |                             GET_LOCK + SELECT … FOR UPDATE          |
  |                             run handler → new state                 |
  |                             version+1, append event (seq=version)   |
  |  ◀── { version, event } ──  commit + RELEASE_LOCK                   |
  |                                                                     |
  |                             ◀───────── GET /sync?since=N (held) ────|
  |                             new event > N → return immediately ────▶|  on('queue.add')
```

Every mutation is one lock, one version bump, one event. Readers (`/sync`,
`/stream`) never take the write lock, so they can't block writers or each other.
A client that has fallen behind the retained window (`RT_EVENT_RETENTION`) gets a
**snapshot** instead of deltas and hard-resyncs — no missed-event corruption.

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
| `RT_SERVICE_SECRET` | — | Secret for the trusted `POST /emit` path (empty disables it) |
| `RT_LONGPOLL_TIMEOUT` | `25` | Max seconds a `/sync` holds open |
| `RT_SHORTPOLL_INTERVAL` | `1500` | Client short-poll interval hint (ms) |
| `RT_SSE_ENABLED` | `true` | Advertise/allow the SSE transport |
| `RT_SSE_MAX_LIFETIME` | `300` | SSE stream max seconds before a reconnect nudge |
| `RT_PRESENCE_TTL` | `30` | Seconds before a silent participant is stale |
| `RT_HEARTBEAT_INTERVAL` | `10` | Recommended client heartbeat (s) |
| `RT_EVENT_RETENTION` | `500` | Events kept per session before snapshot-on-gap |
| `RT_ACTION_RATE_LIMIT` / `RT_POLL_RATE_LIMIT` | `30` / `120` | Per-participant per-window |
| `RT_RATE_WINDOW` | `10` | Rate-limit window (s) |
| `RT_CORS_ORIGINS` | `*` | Comma-separated allowlist |

## HTTP API

| Method & path | Body / query | Returns |
|---|---|---|
| `POST /sessions` | `{ownerId?, state?, meta?, sessionId?}` | `{sessionId, userId, role, token, snapshot}` |
| `POST /sessions/{id}/join` | `{userId?, meta?}` | `{sessionId, userId, role, token, snapshot}` |
| `POST /sessions/{id}/leave` | — (token) | `{ok:true}` |
| `POST /sessions/{id}/actions` | `{type, payload?, baseVersion?}` (token) | `{version, event}` |
| `GET /sessions/{id}/sync?since=` | (token) | `{kind:'events', events, cursor}` or `{kind:'snapshot', snapshot}` |
| `GET /sessions/{id}/stream?since=` | `?token=` | SSE stream (Tier B) |
| `POST /sessions/{id}/heartbeat` | — (token) | `{count, participants, nextHeartbeatMs}` |
| `POST /sessions/{id}/emit` | `{type, payload?, statePatch?}` (`X-Realtime-Service` secret) | `{version, event}` — trusted server-to-server fan-out |

Tokens are sent as `Authorization: Bearer <token>`, the `X-Realtime-Token`
header, or `?token=` (for SSE `EventSource`). Routes match the **tail** of the
path, so the kernel works mounted at `/realtime`, `/api/realtime` or the web root.

**Error shape:** every error is `{ "error": "<message>" }` with a matching HTTP
status — `401` (bad/missing token), `403` (role not allowed), `404` (no such
session), `409` (version conflict), `422` (unknown action), `429` (rate limited).

## Framework integration

The module reads a request and writes a response through small `Request`/
`Response` wrappers, so it drops into any stack.

**Plain PHP** (front controller + Apache rewrite):

```php
// public/realtime.php
require __DIR__ . '/../vendor/autoload.php';
Realtime::fromEnv()->registerAction(/* … */)->run();
```

```apache
# public/.htaccess — send /realtime/* to the front controller
RewriteEngine On
RewriteRule ^realtime/ realtime.php [L]
```

**Laravel** (a catch-all route; reuse the app's PDO):

```php
Route::any('/realtime/{path?}', function () {
    return Realtime::fromEnv()
        ->usePdo(DB::connection()->getPdo())
        ->registerAction('queue.add', /* … */)
        ->run(); // writes the response itself
})->where('path', '.*');
```

**Slim / PSR-15**: build the `Realtime` instance once and call `->run()` inside
a catch-all handler, or drive `->service()` directly and shape your own PSR-7
response.

## Deployment & hosting

- **Schema:** run `migrations/001_create_rt_tables.sql` in production. For local
  demos, set `RT_AUTO_MIGRATE=1` and the bundled front controller calls
  `ensureSchema()` for you.
- **Long-poll workers:** each waiting client holds one PHP worker for up to
  `RT_LONGPOLL_TIMEOUT`. Size your FPM pool for peak concurrent viewers; the
  client auto-downgrades to short-poll if the host kills long requests, trading a
  little latency for far fewer held workers.
- **SSE (Tier B):** needs a host that allows long-lived requests and enough
  workers. It's auto-negotiated and optional — set `RT_SSE_ENABLED=false` to
  force polling everywhere.
- **Buffering:** the SSE transport sends `X-Accel-Buffering: no`; if you sit
  behind nginx, ensure proxy buffering is off for the stream path.
- **Scale-out:** switch `RT_DRIVER=redis` for pub/sub fan-out (instant long-poll/
  SSE wake, no DB polling) and distributed locks across multiple PHP nodes. The
  DB remains the durable source of truth.

## Security checklist

- [ ] Set a long, random `RT_TOKEN_SECRET` (an empty secret disables signing —
      dev only). Tokens bind `{session, user, role, exp}` and are the only source
      of a caller's identity/role.
- [ ] Set `RT_CORS_ORIGINS` to an explicit allowlist in production (never `*`).
- [ ] Declare per-action minimum roles; keep `setDefaultPermission` as tight as
      the app allows (`VIEWER` = read-only by default is safest).
- [ ] Set `RT_SERVICE_SECRET` only if you use `POST /emit`; keep it out of the
      browser.
- [ ] Validate/normalise anything you copy from `payload` into state inside your
      handler — the reducer is the only writer, so that's where invariants live.
- [ ] Tune `RT_ACTION_RATE_LIMIT` / `RT_POLL_RATE_LIMIT` for your traffic.

## Tests

The suite is **dependency-free** — it runs the full `SessionService` against an
in-memory `StorageDriver` double, so no database (and no Composer/PHPUnit) is
required:

```bash
php tests/run.php                 # or: composer test
php tests/run.php --filter=Token  # run a subset
```

It covers the guarantees this module rests on: signed-token verification
(tamper/expiry/wrong-secret), server-authoritative permission enforcement, the
optimistic version check (no lost updates), fixed-window rate limiting,
snapshot-on-gap resync + event retention, and presence timeout/broadcast. CI
(`.github/workflows/ci.yml`) runs it on PHP 8.1–8.4.

## FAQ / troubleshooting

**Do clients get events instantly?** With long-poll or SSE, a new event returns
as soon as it's committed — typically sub-second. Short-poll (the fallback for
hosts that kill long requests) is bounded by `RT_SHORTPOLL_INTERVAL`.

**A client shows stale data after a network blip.** That's the resync path: when
`since` is older than the oldest retained event, `/sync` returns a full snapshot
and the client replaces its state. Raise `RT_EVENT_RETENTION` if you want a
larger delta window before snapshots kick in.

**`emit` throws with `.conflict`.** You passed a `baseVersion` that no longer
matches — someone wrote first (HTTP 409). The client's loop has already pulled
the newer state; re-read `client.state` and retry.

**Presence count doesn't drop when a tab closes.** Silent disconnects are reaped
lazily: the next heartbeat from any surviving client prunes stale participants
(past `RT_PRESENCE_TTL`) and broadcasts a `presence` event. Shorten the TTL for
snappier counts.

**Can I use my own ids / share one DB?** Yes — pass `sessionId` to
`POST /sessions` to use app-supplied ids (integer PKs, slugs), and set
`RT_TABLE_PREFIX` to coexist with your app tables.

## What's out of scope

True bidirectional WebSocket push and sub-100 ms fan-out **at scale** need a
persistent process + Redis on a VPS — the very thing this replaces. For
group-session workloads, long-poll/SSE is ample. See the hosting-compatibility
matrix in the blueprint.
