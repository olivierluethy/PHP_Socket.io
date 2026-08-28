<?php

declare(strict_types=1);

namespace Realtime\Service;

/**
 * The outcome of an action handler: the new authoritative state, and the event
 * that gets appended and fanned out. The broadcast event type/payload may
 * differ from the request (e.g. a handler receives {emoji} but broadcasts a
 * server-derived {emoji, actorId, ts}). By default they mirror the request.
 */
final class ActionResult
{
    private function __construct(
        /** @var array<string,mixed> */
        public readonly array $state,
        public readonly ?string $eventType,
        /** @var array<string,mixed>|null */
        public readonly ?array $eventPayload,
    ) {
    }

    /**
     * @param array<string,mixed> $newState
     * @param array<string,mixed>|null $eventPayload defaults to the request payload
     */
    public static function state(array $newState, ?array $eventPayload = null, ?string $eventType = null): self
    {
        return new self($newState, $eventType, $eventPayload);
    }
}
