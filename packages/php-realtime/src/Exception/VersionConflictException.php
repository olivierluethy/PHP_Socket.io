<?php

declare(strict_types=1);

namespace Realtime\Exception;

/** The action's base version no longer matches the session (a stale write was rejected). */
final class VersionConflictException extends RealtimeException
{
    public function __construct(
        public readonly int $currentVersion,
        string $message = 'Stale write rejected: session has advanced'
    ) {
        parent::__construct($message, 409);
    }
}
