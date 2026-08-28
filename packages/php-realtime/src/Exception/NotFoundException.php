<?php

declare(strict_types=1);

namespace Realtime\Exception;

/** Referenced session/participant does not exist. */
final class NotFoundException extends RealtimeException
{
    public function __construct(string $message = 'Not found')
    {
        parent::__construct($message, 404);
    }
}
