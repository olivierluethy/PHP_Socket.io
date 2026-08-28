<?php

declare(strict_types=1);

namespace Realtime;

/**
 * Immutable, environment-driven configuration for the realtime module.
 *
 * Everything is sourced from env/config — there are no hardcoded URLs or
 * localhost/prod toggles anywhere in the module. An app builds a Config once
 * (typically from Config::fromEnv()) and hands it to {@see Realtime::boot()}.
 */
final class Config
{
    public function __construct(
        /** Storage driver: "mysql" (default, shared-hosting safe) or "redis". */
        public readonly string $driver = 'mysql',

        /** PDO DSN for the MySQL/MariaDB driver, e.g. "mysql:host=127.0.0.1;dbname=app;charset=utf8mb4". */
        public readonly string $dbDsn = '',
        public readonly string $dbUser = '',
        public readonly string $dbPassword = '',
        /** Table name prefix so the module can share a database with the host app. */
        public readonly string $tablePrefix = 'rt_',

        /** Redis connection URL for the optional Redis driver, e.g. "redis://127.0.0.1:6379". */
        public readonly string $redisUrl = '',

        /**
         * HMAC secret used to sign participant tokens. MUST be set to a long
         * random value in production; an empty secret disables token security
         * and is only tolerable for local development.
         */
        public readonly string $tokenSecret = '',
        /** Participant token lifetime in seconds (default 12h). */
        public readonly int $tokenTtl = 43200,

        /** Long-poll: max seconds a /sync request may block waiting for events. */
        public readonly int $longPollTimeout = 25,
        /** Short-poll: client fallback interval hint (ms) surfaced to the client. */
        public readonly int $shortPollInterval = 1500,
        /** Server-side poll granularity (µs) while a long-poll waits for new events. */
        public readonly int $pollIntervalUs = 400000,

        /** Whether the SSE (Tier B) transport is enabled/advertised. */
        public readonly bool $sseEnabled = true,
        /** SSE stream max lifetime (seconds) before the client is asked to reconnect. */
        public readonly int $sseMaxLifetime = 300,

        /** Presence: seconds since last heartbeat after which a participant is stale. */
        public readonly int $presenceTtl = 30,
        /** Presence: recommended client heartbeat interval (seconds). */
        public readonly int $heartbeatInterval = 10,

        /** Event log retention: keep at least this many recent events per session. */
        public readonly int $eventRetention = 500,

        /** Rate limit: max actions per participant per window. */
        public readonly int $actionRateLimit = 30,
        /** Rate limit: max sync/poll requests per participant per window. */
        public readonly int $pollRateLimit = 120,
        /** Rate limit window length (seconds). */
        public readonly int $rateWindow = 10,

        /** Allowed CORS origins ("*" or a list of exact origins). */
        public readonly array $corsOrigins = ['*'],
    ) {
    }

    /**
     * Build configuration from environment variables. Unset variables fall back
     * to the constructor defaults, so a minimal deployment only needs the DB
     * credentials and a token secret.
     */
    public static function fromEnv(array $overrides = []): self
    {
        $env = static function (string $key, $default) {
            $v = getenv($key);
            return $v === false || $v === '' ? $default : $v;
        };
        $bool = static fn ($v): bool => in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
        $list = static function ($v): array {
            $parts = array_filter(array_map('trim', explode(',', (string) $v)));
            return $parts === [] ? ['*'] : array_values($parts);
        };

        $d = new self();

        $values = [
            'driver' => (string) $env('RT_DRIVER', $d->driver),
            'dbDsn' => (string) $env('RT_DB_DSN', $d->dbDsn),
            'dbUser' => (string) $env('RT_DB_USER', $d->dbUser),
            'dbPassword' => (string) $env('RT_DB_PASSWORD', $d->dbPassword),
            'tablePrefix' => (string) $env('RT_TABLE_PREFIX', $d->tablePrefix),
            'redisUrl' => (string) $env('RT_REDIS_URL', $d->redisUrl),
            'tokenSecret' => (string) $env('RT_TOKEN_SECRET', $d->tokenSecret),
            'tokenTtl' => (int) $env('RT_TOKEN_TTL', $d->tokenTtl),
            'longPollTimeout' => (int) $env('RT_LONGPOLL_TIMEOUT', $d->longPollTimeout),
            'shortPollInterval' => (int) $env('RT_SHORTPOLL_INTERVAL', $d->shortPollInterval),
            'pollIntervalUs' => (int) $env('RT_POLL_INTERVAL_US', $d->pollIntervalUs),
            'sseEnabled' => getenv('RT_SSE_ENABLED') === false ? $d->sseEnabled : $bool(getenv('RT_SSE_ENABLED')),
            'sseMaxLifetime' => (int) $env('RT_SSE_MAX_LIFETIME', $d->sseMaxLifetime),
            'presenceTtl' => (int) $env('RT_PRESENCE_TTL', $d->presenceTtl),
            'heartbeatInterval' => (int) $env('RT_HEARTBEAT_INTERVAL', $d->heartbeatInterval),
            'eventRetention' => (int) $env('RT_EVENT_RETENTION', $d->eventRetention),
            'actionRateLimit' => (int) $env('RT_ACTION_RATE_LIMIT', $d->actionRateLimit),
            'pollRateLimit' => (int) $env('RT_POLL_RATE_LIMIT', $d->pollRateLimit),
            'rateWindow' => (int) $env('RT_RATE_WINDOW', $d->rateWindow),
            'corsOrigins' => getenv('RT_CORS_ORIGINS') === false ? $d->corsOrigins : $list(getenv('RT_CORS_ORIGINS')),
        ];

        return new self(...array_merge($values, $overrides));
    }

    public function table(string $name): string
    {
        return $this->tablePrefix . $name;
    }
}
