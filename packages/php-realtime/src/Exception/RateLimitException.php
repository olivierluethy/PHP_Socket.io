<?php

declare(strict_types=1);

namespace Realtime\Exception;

/** Client exceeded the rate limit for this endpoint. */
final class RateLimitException extends RealtimeException
{
    public function __construct(string $message = 'Too many requests')
    {
        parent::__construct($message, 429);
    }
}
