<?php

declare(strict_types=1);

namespace Realtime\Storage;

use PDO;
use Realtime\Config;
use Realtime\Domain\EventRecord;
use Realtime\Domain\ParticipantRecord;
use Realtime\Domain\SessionRecord;
use Realtime\Exception\NotFoundException;
use Realtime\Exception\RealtimeException;
use Realtime\Support\Json;

/**
 * Mandatory, standard-hosting-safe driver. Pure PDO + InnoDB, no extensions
 * beyond pdo_mysql. Mutations are serialised per session with a named advisory
 * lock (GET_LOCK) wrapped around a `SELECT ... FOR UPDATE` transaction.
 *
 * Timestamps are stored as unix seconds (BIGINT) so presence TTL and retention
 * maths are timezone-free and portable across MySQL/MariaDB versions. JSON is
 * encoded/decoded in PHP (never via DB JSON functions) so the schema also works
 * where the JSON type is just an alias for LONGTEXT.
 */
final class MySqlStorageDriver implements StorageDriver
{
    private PDO $pdo;
    private string $sessions;
    private string $participants;
    private string $events;
    private string $rateLimits;

    /** Seconds to wait on the advisory lock before giving up. */
    private int $lockTimeout = 5;

    public function __construct(private Config $config, ?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? new PDO(
            $config->dbDsn,
            $config->dbUser,
            $config->dbPassword,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $this->sessions = $config->table('sessions');
        $this->participants = $config->table('participants');
        $this->events = $config->table('events');
        $this->rateLimits = $config->table('rate_limits');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    // ---- Sessions -----------------------------------------------------------

    public function createSession(string $id, ?string $ownerId, array $state, array $meta = []): void
    {
        $now = time();
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->sessions} (id, owner_id, state, version, status, meta, created_at, updated_at)
             VALUES (:id, :owner, :state, 0, 'active', :meta, :now, :now)"
        );
        $stmt->execute([
            'id' => $id,
            'owner' => $ownerId,
            'state' => Json::encode((object) $state),
            'meta' => Json::encode((object) $meta),
            'now' => $now,
        ]);
    }

    public function findSession(string $id): ?SessionRecord
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->sessions} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? SessionRecord::fromRow($row) : null;
    }

    public function mutate(string $sessionId, callable $fn): mixed
    {
        $lock = 'rt:' . $sessionId;

        $acquire = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
        $acquire->execute([$lock, $this->lockTimeout]);
        if ((int) $acquire->fetchColumn() !== 1) {
            throw new RealtimeException('Could not acquire session lock, try again', 503);
        }

        try {
            $this->pdo->beginTransaction();
            try {
                $stmt = $this->pdo->prepare("SELECT * FROM {$this->sessions} WHERE id = ? FOR UPDATE");
                $stmt->execute([$sessionId]);
                $row = $stmt->fetch();
                if (!$row) {
                    throw new NotFoundException('Session not found');
                }
                $result = $fn(SessionRecord::fromRow($row));
                $this->pdo->commit();
                return $result;
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lock]);
        }
    }

    public function commitMutation(
        string $sessionId,
        array $newState,
        int $newVersion,
        string $type,
        array $payload,
        ?string $actorId
    ): EventRecord {
        $now = time();

        $up = $this->pdo->prepare(
            "UPDATE {$this->sessions} SET state = ?, version = ?, updated_at = ? WHERE id = ?"
        );
        $up->execute([Json::encode((object) $newState), $newVersion, $now, $sessionId]);

        $ins = $this->pdo->prepare(
            "INSERT INTO {$this->events} (session_id, seq, type, payload, actor_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([$sessionId, $newVersion, $type, Json::encode((object) $payload), $actorId, $now]);

        return new EventRecord($newVersion, $type, $payload, $actorId, $now);
    }

    public function updateStatus(string $sessionId, string $status): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->sessions} SET status = ?, updated_at = ? WHERE id = ?"
        );
        $stmt->execute([$status, time(), $sessionId]);
    }

    // ---- Event log ----------------------------------------------------------

    public function eventsSince(string $sessionId, int $sinceSeq, int $limit = 200): array
    {
        $limit = max(1, min($limit, 1000));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->events} WHERE session_id = ? AND seq > ? ORDER BY seq ASC LIMIT {$limit}"
        );
        $stmt->execute([$sessionId, $sinceSeq]);
        return array_map(
            static fn (array $row) => EventRecord::fromRow($row),
            $stmt->fetchAll()
        );
    }

    public function oldestRetainedSeq(string $sessionId): int
    {
        $stmt = $this->pdo->prepare("SELECT MIN(seq) FROM {$this->events} WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $min = $stmt->fetchColumn();
        return $min === null || $min === false ? 0 : (int) $min;
    }

    public function pruneEvents(string $sessionId, int $keep): void
    {
        $stmt = $this->pdo->prepare("SELECT MAX(seq) FROM {$this->events} WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $max = (int) $stmt->fetchColumn();
        $threshold = $max - $keep;
        if ($threshold <= 0) {
            return;
        }
        $del = $this->pdo->prepare("DELETE FROM {$this->events} WHERE session_id = ? AND seq <= ?");
        $del->execute([$sessionId, $threshold]);
    }

    public function awaitEvents(string $sessionId, int $sinceSeq, int $timeoutMs): ?array
    {
        // MySQL cannot block on a channel; the caller polls eventsSince() itself.
        return null;
    }

    // ---- Participants / presence -------------------------------------------

    public function upsertParticipant(string $sessionId, string $userId, string $role, array $meta = []): void
    {
        $now = time();
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->participants} (session_id, user_id, role, meta, last_heartbeat_at, joined_at)
             VALUES (:sid, :uid, :role, :meta, :now, :now)
             ON DUPLICATE KEY UPDATE role = VALUES(role), meta = VALUES(meta), last_heartbeat_at = VALUES(last_heartbeat_at)"
        );
        $stmt->execute([
            'sid' => $sessionId,
            'uid' => $userId,
            'role' => $role,
            'meta' => Json::encode((object) $meta),
            'now' => $now,
        ]);
    }

    public function findParticipant(string $sessionId, string $userId): ?ParticipantRecord
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->participants} WHERE session_id = ? AND user_id = ?"
        );
        $stmt->execute([$sessionId, $userId]);
        $row = $stmt->fetch();
        return $row ? ParticipantRecord::fromRow($row) : null;
    }

    public function heartbeat(string $sessionId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->participants} SET last_heartbeat_at = ? WHERE session_id = ? AND user_id = ?"
        );
        $stmt->execute([time(), $sessionId, $userId]);
    }

    public function removeParticipant(string $sessionId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM {$this->participants} WHERE session_id = ? AND user_id = ?"
        );
        $stmt->execute([$sessionId, $userId]);
    }

    public function activeParticipants(string $sessionId, int $ttlSeconds): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->participants}
             WHERE session_id = ? AND last_heartbeat_at >= ? ORDER BY joined_at ASC"
        );
        $stmt->execute([$sessionId, time() - $ttlSeconds]);
        return array_map(
            static fn (array $row) => ParticipantRecord::fromRow($row),
            $stmt->fetchAll()
        );
    }

    public function activeCount(string $sessionId, int $ttlSeconds): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM {$this->participants} WHERE session_id = ? AND last_heartbeat_at >= ?"
        );
        $stmt->execute([$sessionId, time() - $ttlSeconds]);
        return (int) $stmt->fetchColumn();
    }

    public function pruneStaleParticipants(string $sessionId, int $ttlSeconds): void
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM {$this->participants} WHERE session_id = ? AND last_heartbeat_at < ?"
        );
        $stmt->execute([$sessionId, time() - $ttlSeconds]);
    }

    // ---- Rate limiting ------------------------------------------------------

    public function rateLimitAllow(string $key, int $limit, int $windowSeconds): bool
    {
        $windowSeconds = max(1, $windowSeconds);
        $now = time();
        $windowStart = $now - ($now % $windowSeconds);
        $k = md5($key); // fixed-length PK, safe under index limits

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->rateLimits} (k, window_start, hits) VALUES (:k, :ws, 1)
             ON DUPLICATE KEY UPDATE
                hits = IF(window_start = VALUES(window_start), hits + 1, 1),
                window_start = VALUES(window_start)"
        );
        $stmt->execute(['k' => $k, 'ws' => $windowStart]);

        $read = $this->pdo->prepare("SELECT hits FROM {$this->rateLimits} WHERE k = ?");
        $read->execute([$k]);
        $hits = (int) $read->fetchColumn();

        return $hits <= $limit;
    }

    // ---- Convenience --------------------------------------------------------

    /**
     * Idempotently create the module's tables. Migrations are the canonical way
     * to provision schema; this exists for local demos and quick bootstrapping.
     */
    public function ensureSchema(): void
    {
        $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->sessions} (
                id VARCHAR(64) NOT NULL PRIMARY KEY,
                owner_id VARCHAR(64) NULL,
                state LONGTEXT NOT NULL,
                version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                meta LONGTEXT NULL,
                created_at BIGINT UNSIGNED NOT NULL,
                updated_at BIGINT UNSIGNED NOT NULL,
                INDEX idx_status_updated (status, updated_at)
            ) {$engine}"
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->participants} (
                session_id VARCHAR(64) NOT NULL,
                user_id VARCHAR(64) NOT NULL,
                role VARCHAR(16) NOT NULL DEFAULT 'viewer',
                meta LONGTEXT NULL,
                last_heartbeat_at BIGINT UNSIGNED NOT NULL,
                joined_at BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (session_id, user_id),
                INDEX idx_presence (session_id, last_heartbeat_at)
            ) {$engine}"
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->events} (
                session_id VARCHAR(64) NOT NULL,
                seq BIGINT UNSIGNED NOT NULL,
                type VARCHAR(64) NOT NULL,
                payload LONGTEXT NULL,
                actor_id VARCHAR(64) NULL,
                created_at BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (session_id, seq)
            ) {$engine}"
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->rateLimits} (
                k CHAR(32) NOT NULL PRIMARY KEY,
                window_start BIGINT UNSIGNED NOT NULL,
                hits INT UNSIGNED NOT NULL DEFAULT 0
            ) {$engine}"
        );
    }
}
