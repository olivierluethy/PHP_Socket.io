<?php

declare(strict_types=1);

namespace Realtime\Service;

use Realtime\Config;
use Realtime\Domain\EventRecord;
use Realtime\Domain\Identity;
use Realtime\Domain\Role;
use Realtime\Domain\SessionRecord;
use Realtime\Exception\ForbiddenException;
use Realtime\Exception\NotFoundException;
use Realtime\Exception\RateLimitException;
use Realtime\Exception\VersionConflictException;
use Realtime\Storage\StorageDriver;
use Realtime\Support\Ids;
use Realtime\Support\Token;

/**
 * The application-facing service. Everything the API surface needs — create,
 * join, leave, submit action, sync deltas, heartbeat — lives here. It is
 * transport-agnostic: the HTTP kernel and SSE/long-poll transports call these
 * methods; another app could call them directly.
 *
 * All security and concurrency guarantees are enforced here: roles come only
 * from verified tokens, mutations run inside the driver's per-session lock with
 * an optimistic version check, and every state change appends exactly one event.
 */
final class SessionService
{
    /** @var (callable(SessionRecord,?string,array):string)|null */
    private $roleResolver;

    public function __construct(
        private StorageDriver $storage,
        private Config $config,
        private ActionRegistry $actions,
        private Permissions $permissions,
        /** Default role assigned to a joiner who is not the owner. */
        private string $defaultJoinRole = Role::PARTICIPANT,
        ?callable $roleResolver = null,
    ) {
        $this->roleResolver = $roleResolver;
    }

    // ---- Lifecycle ----------------------------------------------------------

    /**
     * @param array<string,mixed> $initialState
     * @param array<string,mixed> $ownerMeta
     * @return array<string,mixed>
     */
    public function create(?string $ownerId, array $initialState = [], array $ownerMeta = [], ?string $sessionId = null): array
    {
        if ($sessionId !== null) {
            if (!Ids::isValidSession($sessionId)) {
                throw new \Realtime\Exception\RealtimeException('Invalid session id', 400);
            }
            if ($this->storage->findSession($sessionId) !== null) {
                throw new \Realtime\Exception\RealtimeException('Session already exists', 409);
            }
        } else {
            $sessionId = Ids::session();
        }
        $ownerId ??= Ids::participant();

        $this->storage->createSession($sessionId, $ownerId, $initialState, []);
        $this->storage->upsertParticipant($sessionId, $ownerId, Role::OWNER, $ownerMeta);

        return [
            'sessionId' => $sessionId,
            'userId' => $ownerId,
            'role' => Role::OWNER,
            'token' => $this->issueToken($sessionId, $ownerId, Role::OWNER),
            'snapshot' => $this->snapshot($sessionId),
        ];
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public function join(string $sessionId, ?string $userId, array $meta = []): array
    {
        $session = $this->storage->findSession($sessionId);
        if ($session === null) {
            throw new NotFoundException('Session not found');
        }

        $userId ??= Ids::participant('g_');
        $role = $this->resolveRole($session, $userId, $meta);

        // Upsert + presence event atomically so existing clients see the count change.
        $this->storage->mutate($sessionId, function (SessionRecord $sess) use ($sessionId, $userId, $role, $meta): void {
            $this->storage->upsertParticipant($sessionId, $userId, $role, $meta);
            $payload = $this->presencePayload($sessionId, ['event' => 'join', 'userId' => $userId, 'role' => $role]);
            $this->storage->commitMutation($sessionId, $sess->state, $sess->version + 1, 'presence', $payload, $userId);
        });
        $this->storage->pruneEvents($sessionId, $this->config->eventRetention);

        return [
            'sessionId' => $sessionId,
            'userId' => $userId,
            'role' => $role,
            'token' => $this->issueToken($sessionId, $userId, $role),
            'snapshot' => $this->snapshot($sessionId),
        ];
    }

    public function leave(Identity $actor): void
    {
        $sessionId = $actor->sessionId;
        if ($this->storage->findSession($sessionId) === null) {
            return; // idempotent: leaving a gone session is a no-op
        }
        $this->storage->mutate($sessionId, function (SessionRecord $sess) use ($sessionId, $actor): void {
            $this->storage->removeParticipant($sessionId, $actor->userId);
            $payload = $this->presencePayload($sessionId, ['event' => 'leave', 'userId' => $actor->userId]);
            $this->storage->commitMutation($sessionId, $sess->state, $sess->version + 1, 'presence', $payload, $actor->userId);
        });
        $this->storage->pruneEvents($sessionId, $this->config->eventRetention);
    }

    // ---- Actions ------------------------------------------------------------

    /**
     * Authorize, apply and log a mutation. Serialised per session with an
     * optimistic version check; returns the appended event.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function submitAction(Identity $actor, string $type, array $payload, ?int $baseVersion = null): array
    {
        if (!$this->permissions->allows($actor->role, $type)) {
            throw new ForbiddenException("Role '{$actor->role}' may not perform '{$type}'");
        }
        if (!$this->storage->rateLimitAllow(
            "action:{$actor->sessionId}:{$actor->userId}",
            $this->config->actionRateLimit,
            $this->config->rateWindow
        )) {
            throw new RateLimitException();
        }

        /** @var EventRecord $event */
        $event = $this->storage->mutate($actor->sessionId, function (SessionRecord $sess) use ($actor, $type, $payload, $baseVersion): EventRecord {
            if ($baseVersion !== null && $baseVersion !== $sess->version) {
                throw new VersionConflictException($sess->version);
            }
            $result = $this->actions->handle(new ActionContext($sess, $actor, $type, $payload));
            return $this->storage->commitMutation(
                $actor->sessionId,
                $result->state,
                $sess->version + 1,
                $result->eventType ?? $type,
                $result->eventPayload ?? $payload,
                $actor->userId
            );
        });

        $this->storage->pruneEvents($actor->sessionId, $this->config->eventRetention);
        $this->storage->heartbeat($actor->sessionId, $actor->userId); // acting implies presence

        return ['version' => $event->seq, 'event' => $event->toArray()];
    }

    /**
     * Append an event (and optionally patch state) as a trusted server, with no
     * participant token or role check. This is the fan-out path for an existing
     * backend that keeps its own domain logic (e.g. a Node REST API) and just
     * wants the module to broadcast + persist the event. Authenticate the caller
     * at the transport boundary with the service secret.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $statePatch shallow-merged into state when given
     * @return array<string,mixed>
     */
    public function systemEvent(string $sessionId, string $type, array $payload, ?array $statePatch = null): array
    {
        if ($this->storage->findSession($sessionId) === null) {
            throw new NotFoundException('Session not found');
        }
        /** @var EventRecord $event */
        $event = $this->storage->mutate($sessionId, function (SessionRecord $sess) use ($sessionId, $type, $payload, $statePatch): EventRecord {
            $state = $statePatch !== null ? array_merge($sess->state, $statePatch) : $sess->state;
            return $this->storage->commitMutation($sessionId, $state, $sess->version + 1, $type, $payload, 'system');
        });
        $this->storage->pruneEvents($sessionId, $this->config->eventRetention);

        return ['version' => $event->seq, 'event' => $event->toArray()];
    }

    // ---- Presence -----------------------------------------------------------

    /** @return array<string,mixed> */
    public function heartbeat(Identity $actor): array
    {
        $sessionId = $actor->sessionId;
        $this->storage->heartbeat($sessionId, $actor->userId);

        // Lazy reaper: prune anyone past the TTL and, if a silent disconnect was
        // actually reaped, append one presence event so other clients watching
        // the event stream see the viewer count drop (mirrors the Node reaper's
        // rebroadcast — without it a closed tab only surfaces on the next resync).
        $reaped = $this->storage->pruneStaleParticipants($sessionId, $this->config->presenceTtl);

        if ($reaped > 0 && $this->storage->findSession($sessionId) !== null) {
            $this->storage->mutate($sessionId, function (SessionRecord $sess) use ($sessionId): void {
                $payload = $this->presencePayload($sessionId, ['event' => 'timeout']);
                $this->storage->commitMutation($sessionId, $sess->state, $sess->version + 1, 'presence', $payload, null);
            });
            $this->storage->pruneEvents($sessionId, $this->config->eventRetention);
        }

        return [
            'count' => $this->storage->activeCount($sessionId, $this->config->presenceTtl),
            'participants' => $this->roster($sessionId),
            'nextHeartbeatMs' => $this->config->heartbeatInterval * 1000,
        ];
    }

    // ---- Sync ---------------------------------------------------------------

    /**
     * One non-blocking sync decision. Returns a full snapshot when the client
     * has fallen behind the retained window, otherwise the events after $since
     * (possibly empty). The long-poll/SSE transports call this in a loop.
     *
     * @return array<string,mixed>
     */
    public function syncOnce(string $sessionId, int $since): array
    {
        $session = $this->storage->findSession($sessionId);
        if ($session === null) {
            throw new NotFoundException('Session not found');
        }

        $oldest = $this->storage->oldestRetainedSeq($sessionId);
        if ($oldest > 0 && $since + 1 < $oldest) {
            return ['kind' => 'snapshot', 'snapshot' => $this->snapshot($sessionId)];
        }

        $events = $this->storage->eventsSince($sessionId, $since);
        $cursor = $events === [] ? $since : $events[count($events) - 1]->seq;

        return [
            'kind' => 'events',
            'events' => array_map(static fn (EventRecord $e) => $e->toArray(), $events),
            'cursor' => $cursor,
        ];
    }

    /** @return array<string,mixed> */
    public function snapshot(string $sessionId): array
    {
        $session = $this->storage->findSession($sessionId);
        if ($session === null) {
            throw new NotFoundException('Session not found');
        }
        return [
            'state' => (object) $session->state,
            'version' => $session->version,
            'status' => $session->status,
            'participants' => $this->roster($sessionId),
            'viewerCount' => $this->storage->activeCount($sessionId, $this->config->presenceTtl),
        ];
    }

    // ---- Tokens -------------------------------------------------------------

    /** Verify a raw token belongs to $sessionId and return the caller's Identity. */
    public function authenticate(string $token, string $sessionId): Identity
    {
        $claims = Token::verify($token, $this->config->tokenSecret);
        if ($claims === null || (string) $claims['sid'] !== $sessionId) {
            throw new \Realtime\Exception\UnauthorizedException('Invalid or expired token');
        }
        return Identity::fromClaims($claims);
    }

    public function issueToken(string $sessionId, string $userId, string $role): string
    {
        return Token::issue(
            ['sid' => $sessionId, 'uid' => $userId, 'role' => $role],
            $this->config->tokenSecret,
            $this->config->tokenTtl
        );
    }

    // ---- Internals ----------------------------------------------------------

    private function resolveRole(SessionRecord $session, string $userId, array $meta): string
    {
        if ($this->roleResolver !== null) {
            return Role::normalize(($this->roleResolver)($session, $userId, $meta));
        }
        if ($session->ownerId !== null && $session->ownerId === $userId) {
            return Role::OWNER;
        }
        return $this->defaultJoinRole;
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function presencePayload(string $sessionId, array $extra): array
    {
        return array_merge($extra, [
            'count' => $this->storage->activeCount($sessionId, $this->config->presenceTtl),
            'participants' => $this->roster($sessionId),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function roster(string $sessionId): array
    {
        return array_map(
            static fn ($p) => $p->toArray(),
            $this->storage->activeParticipants($sessionId, $this->config->presenceTtl)
        );
    }
}
