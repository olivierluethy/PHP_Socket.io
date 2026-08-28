-- php-realtime schema (default table prefix `rt_`).
-- Standard-hosting-safe: InnoDB, utf8mb4, no JSON-type dependency (LONGTEXT holds
-- JSON so it works on MariaDB 10.1+ and MySQL 5.6+). Timestamps are unix seconds.
-- If you use a non-default RT_TABLE_PREFIX, rename the tables to match.

-- Authoritative session state + monotonic version.
CREATE TABLE IF NOT EXISTS rt_sessions (
    id          VARCHAR(64)      NOT NULL PRIMARY KEY,
    owner_id    VARCHAR(64)      NULL,
    state       LONGTEXT         NOT NULL,             -- JSON snapshot
    version     BIGINT UNSIGNED  NOT NULL DEFAULT 0,   -- == seq of last event
    status      VARCHAR(16)      NOT NULL DEFAULT 'active',
    meta        LONGTEXT         NULL,                 -- JSON, app-defined
    created_at  BIGINT UNSIGNED  NOT NULL,
    updated_at  BIGINT UNSIGNED  NOT NULL,
    INDEX idx_status_updated (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Participants + presence (heartbeat/TTL).
CREATE TABLE IF NOT EXISTS rt_participants (
    session_id         VARCHAR(64)     NOT NULL,
    user_id            VARCHAR(64)     NOT NULL,
    role               VARCHAR(16)     NOT NULL DEFAULT 'viewer',
    meta               LONGTEXT        NULL,           -- JSON (display name, avatar, ...)
    last_heartbeat_at  BIGINT UNSIGNED NOT NULL,
    joined_at          BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (session_id, user_id),
    INDEX idx_presence (session_id, last_heartbeat_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only event log; rolling retention (older rows pruned per session).
CREATE TABLE IF NOT EXISTS rt_events (
    session_id  VARCHAR(64)      NOT NULL,
    seq         BIGINT UNSIGNED  NOT NULL,             -- per-session monotonic
    type        VARCHAR(64)      NOT NULL,
    payload     LONGTEXT         NULL,                 -- JSON
    actor_id    VARCHAR(64)      NULL,
    created_at  BIGINT UNSIGNED  NOT NULL,
    PRIMARY KEY (session_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fixed-window rate-limit counters (per participant per endpoint).
CREATE TABLE IF NOT EXISTS rt_rate_limits (
    k             CHAR(32)         NOT NULL PRIMARY KEY, -- md5 of the limiter key
    window_start  BIGINT UNSIGNED  NOT NULL,
    hits          INT UNSIGNED     NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
