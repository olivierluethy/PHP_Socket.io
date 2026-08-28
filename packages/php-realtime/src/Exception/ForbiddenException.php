<?php

declare(strict_types=1);

namespace Realtime\Exception;

/** Authenticated but not allowed to perform this action. */
final class ForbiddenException extends RealtimeException
{
    public function __construct(string $message = 'Forbidden')
    {
        parent::__construct($message, 403);
    }
}
