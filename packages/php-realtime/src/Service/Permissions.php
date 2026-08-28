<?php

declare(strict_types=1);

namespace Realtime\Service;

use Realtime\Domain\Role;

/**
 * Server-authoritative permission map: per action type, the minimum role
 * required to perform it. The host app declares the rules; unlisted types fall
 * back to $default. The caller's role always comes from the verified token, so
 * a client cannot escalate by claiming a different role.
 */
final class Permissions
{
    /**
     * @param array<string,string> $rules  actionType => minimum Role
     * @param string $default  minimum role for any action not in $rules
     */
    public function __construct(
        private array $rules = [],
        private string $default = Role::PARTICIPANT,
    ) {
    }

    public function requiredRole(string $type): string
    {
        return $this->rules[$type] ?? $this->default;
    }

    public function allows(string $role, string $type): bool
    {
        return Role::satisfies($role, $this->requiredRole($type));
    }

    public function withRule(string $type, string $minRole): self
    {
        $clone = clone $this;
        $clone->rules[$type] = $minRole;
        return $clone;
    }
}
