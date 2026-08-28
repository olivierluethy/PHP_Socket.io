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
