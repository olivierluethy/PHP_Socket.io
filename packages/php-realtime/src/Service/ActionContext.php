<?php

declare(strict_types=1);

namespace Realtime\Service;

use Realtime\Domain\Identity;
use Realtime\Domain\SessionRecord;

/**
 * Everything an action handler needs, assembled inside the per-session lock so
 * $session is the freshly-read authoritative state (no stale reads).
 */
final class ActionContext
{
    public function __construct(
        public readonly SessionRecord $session,
        public readonly Identity $actor,
        public readonly string $type,
        /** @var array<string,mixed> */
        public readonly array $payload,
    ) {
    }

    /** @return array<string,mixed> Current authoritative state, for convenience. */
    public function state(): array
    {
        return $this->session->state;
    }
}
