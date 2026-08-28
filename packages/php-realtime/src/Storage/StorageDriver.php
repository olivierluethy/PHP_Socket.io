<?php

declare(strict_types=1);

namespace Realtime\Storage;

use Realtime\Domain\EventRecord;
use Realtime\Domain\ParticipantRecord;
use Realtime\Domain\SessionRecord;

/**
 * Persistence + concurrency + fan-out abstraction.
 *
 * The MySQL driver is mandatory and standard-hosting-safe. A Redis driver is an
 * optional performance upgrade. Anything the services need from storage goes
 * through this interface, so the rest of the module is storage-agnostic.
 *
 * Concurrency contract: {@see self::mutate()} runs its callback with an
 * EXCLUSIVE per-session lock held, so all mutations to a session are
 * serialised. Inside that callback the driver instance is the locked context —
 * {@see self::commitMutation()} is only ever called from within it.
 */
interface StorageDriver
{
    // ---- Sessions -----------------------------------------------------------

    /** @param array<string,mixed> $state @param array<string,mixed> $meta */
    public function createSession(string $id, ?string $ownerId, array $state, array $meta = []): void;

    public function findSession(string $id): ?SessionRecord;

    /**
     * Run $fn while holding this session's exclusive lock, having already read
     * and passed in the current {@see SessionRecord}. Whatever $fn returns is
     * returned to the caller. Implementations use a DB transaction with
     * `SELECT ... FOR UPDATE` (plus a `GET_LOCK` advisory lock as a fallback),
     * or a Redis lock. Throws NotFoundException if the session is gone.
     *
     * @param callable(SessionRecord):mixed $fn
     */
    public function mutate(string $sessionId, callable $fn): mixed;

    /**
     * Persist a new authoritative state and append one event atomically, with
     * seq = previous version + 1 and the session version advanced to match.
     * MUST be called only from inside {@see self::mutate()}.
     *
     * @param array<string,mixed> $newState @param array<string,mixed> $payload
     */
    public function commitMutation(
        string $sessionId,
        array $newState,
        int $newVersion,
        string $type,
        array $payload,
        ?string $actorId
    ): EventRecord;

    public function updateStatus(string $sessionId, string $status): void;

    // ---- Event log ----------------------------------------------------------

    /** @return EventRecord[] events with seq > $sinceSeq, ascending, capped at $limit. */
    public function eventsSince(string $sessionId, int $sinceSeq, int $limit = 200): array;

    /** Smallest seq still retained; if a client's `since` is below this, it must resync. */
    public function oldestRetainedSeq(string $sessionId): int;

    /** Trim the event log to at most the last $keep events (rolling retention). */
    public function pruneEvents(string $sessionId, int $keep): void;

    /**
     * Optional low-latency wait: block up to $timeoutMs for events after
     * $sinceSeq and return them, or return null if the driver cannot block
     * (the caller then falls back to its own poll loop). Redis implements this
     * via pub/sub; MySQL returns null.
     *
     * @return EventRecord[]|null
     */
    public function awaitEvents(string $sessionId, int $sinceSeq, int $timeoutMs): ?array;

    // ---- Participants / presence -------------------------------------------

    /** @param array<string,mixed> $meta */
    public function upsertParticipant(string $sessionId, string $userId, string $role, array $meta = []): void;

    public function findParticipant(string $sessionId, string $userId): ?ParticipantRecord;

    public function heartbeat(string $sessionId, string $userId): void;

    public function removeParticipant(string $sessionId, string $userId): void;

    /** @return ParticipantRecord[] participants with a heartbeat within $ttlSeconds. */
    public function activeParticipants(string $sessionId, int $ttlSeconds): array;

    public function activeCount(string $sessionId, int $ttlSeconds): int;

    public function pruneStaleParticipants(string $sessionId, int $ttlSeconds): void;

    // ---- Rate limiting ------------------------------------------------------

    /** Fixed-window counter. Returns true if the hit is allowed, false if the limit is exceeded. */
    public function rateLimitAllow(string $key, int $limit, int $windowSeconds): bool;
}
