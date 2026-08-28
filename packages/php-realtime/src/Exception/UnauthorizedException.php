<?php

declare(strict_types=1);

namespace Realtime\Exception;

/** Authentication failed (missing/invalid/expired token). */
final class UnauthorizedException extends RealtimeException
{
    public function __construct(string $message = 'Unauthorized')
    {
        parent::__construct($message, 401);
    }
}
