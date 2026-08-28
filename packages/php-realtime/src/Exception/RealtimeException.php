<?php

declare(strict_types=1);

namespace Realtime\Exception;

/**
 * Base exception for the realtime module. The integer code doubles as the HTTP
 * status the transport layer should return, so handlers can throw these freely
 * and the kernel maps them to a clean JSON error response.
 */
class RealtimeException extends \RuntimeException
{
    public function __construct(string $message, int $httpStatus = 400)
    {
        parent::__construct($message, $httpStatus);
    }

    public function httpStatus(): int
    {
        $code = $this->getCode();
        return $code >= 400 && $code <= 599 ? $code : 400;
    }
}

/** The action's base version no longer matches the session (a stale write was rejected). */
final class VersionConflictException extends RealtimeException
{
    public function __construct(public readonly int $currentVersion, string $message = 'Stale write rejected: session has advanced')
    {
        parent::__construct($message, 409);
    }
}

/** Authentication failed (missing/invalid/expired token). */
final class UnauthorizedException extends RealtimeException
{
    public function __construct(string $message = 'Unauthorized')
    {
        parent::__construct($message, 401);
    }
}

/** Authenticated but not allowed to perform this action. */
final class ForbiddenException extends RealtimeException
{
    public function __construct(string $message = 'Forbidden')
    {
        parent::__construct($message, 403);
    }
}

/** Referenced session/participant does not exist. */
final class NotFoundException extends RealtimeException
{
    public function __construct(string $message = 'Not found')
    {
        parent::__construct($message, 404);
    }
}

/** Client exceeded the rate limit for this endpoint. */
final class RateLimitException extends RealtimeException
{
    public function __construct(string $message = 'Too many requests')
    {
        parent::__construct($message, 429);
    }
}
