<?php

declare(strict_types=1);

namespace Realtime\Domain;

/**
 * A verified caller, derived from the signed participant token — never from the
 * request body. Every authorization decision reads the role from here.
 */
final class Identity
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $userId,
        public readonly string $role,
    ) {
    }

    /** @param array{sid:string,uid:string,role:string} $claims */
    public static function fromClaims(array $claims): self
    {
        return new self(
            sessionId: (string) $claims['sid'],
            userId: (string) $claims['uid'],
            role: Role::normalize((string) $claims['role']),
        );
    }
}
