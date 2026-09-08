<?php

declare(strict_types=1);

namespace Realtime\Tests\Support;

use Realtime\Domain\EventRecord;
use Realtime\Domain\ParticipantRecord;
use Realtime\Domain\SessionRecord;
use Realtime\Exception\NotFoundException;
use Realtime\Storage\StorageDriver;

/**
 * In-memory {@see StorageDriver} for tests. It reproduces the semantics the
 * services depend on — monotonic per-session seq, seq == version, rolling event
 * retention, presence TTL, fixed-window rate limiting and the mutate() lock
 * contract — without a database, so the full {@see \Realtime\Service\SessionService}
 * can be exercised anywhere PHP runs.
 *
 * The lock is a no-op because the test runner is single-threaded; the guarantee
 * under test is the *optimistic version check*, which is storage-independent.
 *
 * A pluggable clock (via {@see self::travel()}) lets presence-TTL and retention
 * behaviour be tested deterministically instead of with real sleeps.
 */
final class ArrayStorageDriver implements StorageDriver
{
    /** @var array<string, array{ownerId:?string,state:array,version:int,status:string,createdAt:int,updatedAt:int}> */
    private array $sessions = [];
    /** @var array<string, list<EventRecord>> */
    private array $events = [];
    /** @var array<string, array<string, ParticipantRecord>> */
    private array $participants = [];
    /** @var array<string, array{window:int,hits:int}> */
    private array $rate = [];

    /** Seconds added to real time; {@see self::travel()} advances it for tests. */
    private int $clockOffset = 0;

    private function now(): int
    {
        return time() + $this->clockOffset;
    }

    /** Advance the driver's clock by $seconds (for presence/retention tests). */
    public function travel(int $seconds): void
    {
        $this->clockOffset += $seconds;
    }

    // ---- Sessions -----------------------------------------------------------

    public function createSession(string $id, ?string $ownerId, array $state, array $meta = []): void
    {
        $now = $this->now();
        $this->sessions[$id] = [
            'ownerId' => $ownerId,
            'state' => $state,
            'version' => 0,
            'status' => 'active',
            'createdAt' => $now,
            'updatedAt' => $now,
        ];
        $this->events[$id] = [];
        $this->participants[$id] = [];
    }

    public function findSession(string $id): ?SessionRecord
    {
        $s = $this->sessions[$id] ?? null;
        if ($s === null) {
            return null;
        }
        return new SessionRecord(
            id: $id,
            ownerId: $s['ownerId'],
            state: $s['state'],
            version: $s['version'],
            status: $s['status'],
            createdAt: $s['createdAt'],
            updatedAt: $s['updatedAt'],
        );
    }

    public function mutate(string $sessionId, callable $fn): mixed
    {
        $record = $this->findSession($sessionId);
        if ($record === null) {
            throw new NotFoundException('Session not found');
        }
        return $fn($record);
    }

    public function commitMutation(
        string $sessionId,
        array $newState,
        int $newVersion,
        string $type,
        array $payload,
        ?string $actorId
    ): EventRecord {
        $now = $this->now();
        $this->sessions[$sessionId]['state'] = $newState;
        $this->sessions[$sessionId]['version'] = $newVersion;
        $this->sessions[$sessionId]['updatedAt'] = $now;

        $event = new EventRecord($newVersion, $type, $payload, $actorId, $now);
        $this->events[$sessionId][] = $event;
        return $event;
    }

    public function updateStatus(string $sessionId, string $status): void
    {
        if (isset($this->sessions[$sessionId])) {
            $this->sessions[$sessionId]['status'] = $status;
        }
    }

    // ---- Event log ----------------------------------------------------------

    public function eventsSince(string $sessionId, int $sinceSeq, int $limit = 200): array
    {
        $out = [];
        foreach ($this->events[$sessionId] ?? [] as $event) {
            if ($event->seq > $sinceSeq) {
                $out[] = $event;
            }
        }
        return array_slice($out, 0, max(1, min($limit, 1000)));
    }

    public function oldestRetainedSeq(string $sessionId): int
    {
        $events = $this->events[$sessionId] ?? [];
        if ($events === []) {
            return 0;
        }
        return $events[0]->seq;
    }

    public function pruneEvents(string $sessionId, int $keep): void
    {
        $events = $this->events[$sessionId] ?? [];
        if ($events === []) {
            return;
        }
        $max = $events[count($events) - 1]->seq;
        $threshold = $max - $keep;
        if ($threshold <= 0) {
            return;
        }
        $this->events[$sessionId] = array_values(array_filter(
            $events,
            static fn (EventRecord $e): bool => $e->seq > $threshold
        ));
    }

    public function awaitEvents(string $sessionId, int $sinceSeq, int $timeoutMs): ?array
    {
        // Like MySQL: this driver cannot block; callers poll eventsSince().
        return null;
    }

    // ---- Participants / presence -------------------------------------------

    public function upsertParticipant(string $sessionId, string $userId, string $role, array $meta = []): void
    {
        $now = $this->now();
        $existing = $this->participants[$sessionId][$userId] ?? null;
        $this->participants[$sessionId][$userId] = new ParticipantRecord(
            sessionId: $sessionId,
            userId: $userId,
            role: $role,
            meta: $meta,
            lastHeartbeatAt: $now,
            joinedAt: $existing?->joinedAt ?? $now,
        );
    }

    public function findParticipant(string $sessionId, string $userId): ?ParticipantRecord
    {
        return $this->participants[$sessionId][$userId] ?? null;
    }

    public function heartbeat(string $sessionId, string $userId): void
    {
        $p = $this->participants[$sessionId][$userId] ?? null;
        if ($p === null) {
            return;
        }
        $this->participants[$sessionId][$userId] = new ParticipantRecord(
            sessionId: $p->sessionId,
            userId: $p->userId,
            role: $p->role,
            meta: $p->meta,
            lastHeartbeatAt: $this->now(),
            joinedAt: $p->joinedAt,
        );
    }

    public function removeParticipant(string $sessionId, string $userId): void
    {
        unset($this->participants[$sessionId][$userId]);
    }

    public function activeParticipants(string $sessionId, int $ttlSeconds): array
    {
        $cutoff = $this->now() - $ttlSeconds;
        $out = [];
        foreach ($this->participants[$sessionId] ?? [] as $p) {
            if ($p->lastHeartbeatAt >= $cutoff) {
                $out[] = $p;
            }
        }
        usort($out, static fn (ParticipantRecord $a, ParticipantRecord $b): int => $a->joinedAt <=> $b->joinedAt);
        return $out;
    }

    public function activeCount(string $sessionId, int $ttlSeconds): int
    {
        return count($this->activeParticipants($sessionId, $ttlSeconds));
    }

    public function pruneStaleParticipants(string $sessionId, int $ttlSeconds): int
    {
        $cutoff = $this->now() - $ttlSeconds;
        $removed = 0;
        foreach ($this->participants[$sessionId] ?? [] as $uid => $p) {
            if ($p->lastHeartbeatAt < $cutoff) {
                unset($this->participants[$sessionId][$uid]);
                $removed++;
            }
        }
        return $removed;
    }

    // ---- Rate limiting ------------------------------------------------------

    public function rateLimitAllow(string $key, int $limit, int $windowSeconds): bool
    {
        $windowSeconds = max(1, $windowSeconds);
        $now = $this->now();
        $windowStart = $now - ($now % $windowSeconds);

        $entry = $this->rate[$key] ?? null;
        if ($entry === null || $entry['window'] !== $windowStart) {
            $this->rate[$key] = ['window' => $windowStart, 'hits' => 1];
        } else {
            $this->rate[$key]['hits']++;
        }

        return $this->rate[$key]['hits'] <= $limit;
    }

    // ---- Test-only helpers --------------------------------------------------

    /** Backdate a participant's heartbeat to simulate a stale (timed-out) client. */
    public function backdateHeartbeat(string $sessionId, string $userId, int $secondsAgo): void
    {
        $p = $this->participants[$sessionId][$userId] ?? null;
        if ($p === null) {
            return;
        }
        $this->participants[$sessionId][$userId] = new ParticipantRecord(
            sessionId: $p->sessionId,
            userId: $p->userId,
            role: $p->role,
            meta: $p->meta,
            lastHeartbeatAt: $this->now() - $secondsAgo,
            joinedAt: $p->joinedAt,
        );
    }

    public function eventCount(string $sessionId): int
    {
        return count($this->events[$sessionId] ?? []);
    }
}
