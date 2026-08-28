<?php

declare(strict_types=1);

namespace Realtime\Storage;

use Realtime\Config;
use Realtime\Domain\EventRecord;
use Realtime\Domain\ParticipantRecord;
use Realtime\Domain\SessionRecord;
use Realtime\Exception\NotFoundException;
use Realtime\Exception\RealtimeException;
use Realtime\Support\Json;

/**
 * Optional performance-upgrade driver (requires ext-redis). Shares state across
 * PHP nodes, uses Redis SET-NX locks for per-session serialisation, and wakes
 * long-poll/SSE waiters instantly via pub/sub (falling back to polling if the
 * subscribe path is unavailable). Same durability contract at the app level;
 * point RT_DRIVER=redis and nothing else changes.
 *
 * Keys per session {sid}: hash `rt:{sid}` (session), zset `rt:{sid}:ev`
 * (events by seq), hash `rt:{sid}:p` (participants), channel `rt:{sid}:pub`.
 */
final class RedisStorageDriver implements StorageDriver
{
    private \Redis $redis;
    private ?\Redis $subscriber = null;
    private int $lockTimeoutMs = 5000;

    public function __construct(private Config $config)
    {
        if (!class_exists(\Redis::class)) {
            throw new RealtimeException('ext-redis is not installed; use the MySQL driver', 500);
        }
        $this->redis = $this->connect();
    }

    private function connect(): \Redis
    {
        $parts = parse_url($this->config->redisUrl ?: 'redis://127.0.0.1:6379');
        $redis = new \Redis();
        $redis->connect($parts['host'] ?? '127.0.0.1', (int) ($parts['port'] ?? 6379), 2.0);
        if (!empty($parts['pass'])) {
            $redis->auth($parts['pass']);
        }
        if (isset($parts['path']) && ltrim($parts['path'], '/') !== '') {
            $redis->select((int) ltrim($parts['path'], '/'));
        }
        return $redis;
    }

    private function k(string $sid, string $suffix = ''): string
    {
        return 'rt:' . $sid . $suffix;
    }

    // ---- Sessions -----------------------------------------------------------

    public function createSession(string $id, ?string $ownerId, array $state, array $meta = []): void
    {
        $now = time();
        $this->redis->hMSet($this->k($id), [
            'id' => $id,
            'owner_id' => (string) $ownerId,
            'state' => Json::encode((object) $state),
            'version' => 0,
            'status' => 'active',
            'meta' => Json::encode((object) $meta),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function findSession(string $id): ?SessionRecord
    {
        $row = $this->redis->hGetAll($this->k($id));
        if (!$row) {
            return null;
        }
        if (($row['owner_id'] ?? '') === '') {
            $row['owner_id'] = null;
        }
        return SessionRecord::fromRow($row);
    }

    public function mutate(string $sessionId, callable $fn): mixed
    {
        $lockKey = $this->k($sessionId, ':lock');
        $token = bin2hex(random_bytes(8));
        $deadline = microtime(true) + $this->lockTimeoutMs / 1000;

        while (!$this->redis->set($lockKey, $token, ['nx', 'px' => $this->lockTimeoutMs])) {
            if (microtime(true) >= $deadline) {
                throw new RealtimeException('Could not acquire session lock, try again', 503);
            }
            usleep(20000);
        }

        try {
            $session = $this->findSession($sessionId);
            if ($session === null) {
                throw new NotFoundException('Session not found');
            }
            return $fn($session);
        } finally {
            // Release only if we still own the lock (compare-and-delete).
            $lua = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
            $this->redis->eval($lua, [$lockKey, $token], 1);
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
        $event = new EventRecord($newVersion, $type, $payload, $actorId, $now);

        $this->redis->multi()
            ->hMSet($this->k($sessionId), [
                'state' => Json::encode((object) $newState),
                'version' => $newVersion,
                'updated_at' => $now,
            ])
            ->zAdd($this->k($sessionId, ':ev'), $newVersion, Json::encode([
                'session_id' => $sessionId,
                'seq' => $newVersion,
                'type' => $type,
                'payload' => Json::encode((object) $payload),
                'actor_id' => $actorId,
                'created_at' => $now,
            ]))
            ->exec();

        $this->redis->publish($this->k($sessionId, ':pub'), (string) $newVersion);

        return $event;
    }

    public function updateStatus(string $sessionId, string $status): void
    {
        $this->redis->hSet($this->k($sessionId), 'status', $status);
        $this->redis->hSet($this->k($sessionId), 'updated_at', (string) time());
    }

    // ---- Event log ----------------------------------------------------------

    public function eventsSince(string $sessionId, int $sinceSeq, int $limit = 200): array
    {
        $raw = $this->redis->zRangeByScore(
            $this->k($sessionId, ':ev'),
            '(' . $sinceSeq,
            '+inf',
            ['limit' => [0, max(1, min($limit, 1000))]]
        );
        $out = [];
        foreach ($raw as $json) {
            $row = json_decode((string) $json, true);
            if (is_array($row)) {
                $out[] = EventRecord::fromRow($row);
            }
        }
        return $out;
    }

    public function oldestRetainedSeq(string $sessionId): int
    {
        $first = $this->redis->zRangeByScore($this->k($sessionId, ':ev'), '-inf', '+inf', ['limit' => [0, 1], 'withscores' => true]);
        if (!$first) {
            return 0;
        }
        return (int) reset($first);
    }

    public function pruneEvents(string $sessionId, int $keep): void
    {
        $card = $this->redis->zCard($this->k($sessionId, ':ev'));
        if ($card > $keep) {
            $this->redis->zRemRangeByRank($this->k($sessionId, ':ev'), 0, $card - $keep - 1);
        }
    }

    public function awaitEvents(string $sessionId, int $sinceSeq, int $timeoutMs): ?array
    {
        // Best-effort instant wake via pub/sub; any failure → null (caller polls).
        try {
            $this->subscriber ??= $this->connect();
            $this->subscriber->setOption(\Redis::OPT_READ_TIMEOUT, max(1, (int) ceil($timeoutMs / 1000)));
            $this->subscriber->subscribe([$this->k($sessionId, ':pub')], static function (\Redis $r): void {
                $r->close(); // first message breaks the subscribe loop
            });
        } catch (\Throwable) {
            $this->subscriber = null; // read-timeout or closed; fall through to a read
        }
        return $this->eventsSince($sessionId, $sinceSeq);
    }

    // ---- Participants / presence -------------------------------------------

    public function upsertParticipant(string $sessionId, string $userId, string $role, array $meta = []): void
    {
        $now = time();
        $existing = $this->redis->hGet($this->k($sessionId, ':p'), $userId);
        $joinedAt = $now;
        if ($existing !== false) {
            $prev = json_decode((string) $existing, true);
            $joinedAt = (int) ($prev['joined_at'] ?? $now);
        }
        $this->redis->hSet($this->k($sessionId, ':p'), $userId, Json::encode([
            'role' => $role,
            'meta' => Json::encode((object) $meta),
            'last_heartbeat_at' => $now,
            'joined_at' => $joinedAt,
        ]));
    }

    public function findParticipant(string $sessionId, string $userId): ?ParticipantRecord
    {
        $json = $this->redis->hGet($this->k($sessionId, ':p'), $userId);
        return $json === false ? null : $this->participantFrom($sessionId, $userId, (string) $json);
    }

    public function heartbeat(string $sessionId, string $userId): void
    {
        $json = $this->redis->hGet($this->k($sessionId, ':p'), $userId);
        if ($json === false) {
            return;
        }
        $data = json_decode((string) $json, true);
        $data['last_heartbeat_at'] = time();
        $this->redis->hSet($this->k($sessionId, ':p'), $userId, Json::encode($data));
    }

    public function removeParticipant(string $sessionId, string $userId): void
    {
        $this->redis->hDel($this->k($sessionId, ':p'), $userId);
    }

    public function activeParticipants(string $sessionId, int $ttlSeconds): array
    {
        $cutoff = time() - $ttlSeconds;
        $all = $this->redis->hGetAll($this->k($sessionId, ':p')) ?: [];
        $out = [];
        foreach ($all as $userId => $json) {
            $rec = $this->participantFrom($sessionId, (string) $userId, (string) $json);
            if ($rec->lastHeartbeatAt >= $cutoff) {
                $out[] = $rec;
            }
        }
        usort($out, static fn ($a, $b) => $a->joinedAt <=> $b->joinedAt);
        return $out;
    }

    public function activeCount(string $sessionId, int $ttlSeconds): int
    {
        return count($this->activeParticipants($sessionId, $ttlSeconds));
    }

    public function pruneStaleParticipants(string $sessionId, int $ttlSeconds): void
    {
        $cutoff = time() - $ttlSeconds;
        $all = $this->redis->hGetAll($this->k($sessionId, ':p')) ?: [];
        foreach ($all as $userId => $json) {
            $data = json_decode((string) $json, true);
            if ((int) ($data['last_heartbeat_at'] ?? 0) < $cutoff) {
                $this->redis->hDel($this->k($sessionId, ':p'), (string) $userId);
            }
        }
    }

    // ---- Rate limiting ------------------------------------------------------

    public function rateLimitAllow(string $key, int $limit, int $windowSeconds): bool
    {
        $windowSeconds = max(1, $windowSeconds);
        $now = time();
        $windowStart = $now - ($now % $windowSeconds);
        $k = 'rt:rl:' . md5($key) . ':' . $windowStart;

        $hits = (int) $this->redis->incr($k);
        if ($hits === 1) {
            $this->redis->expire($k, $windowSeconds + 1);
        }
        return $hits <= $limit;
    }

    private function participantFrom(string $sessionId, string $userId, string $json): ParticipantRecord
    {
        $data = json_decode($json, true) ?: [];
        return ParticipantRecord::fromRow([
            'session_id' => $sessionId,
            'user_id' => $userId,
            'role' => $data['role'] ?? 'viewer',
            'meta' => $data['meta'] ?? '{}',
            'last_heartbeat_at' => $data['last_heartbeat_at'] ?? 0,
            'joined_at' => $data['joined_at'] ?? 0,
        ]);
    }
}
