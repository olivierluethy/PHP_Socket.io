<?php

declare(strict_types=1);

namespace Realtime\Domain;

/**
 * Session roles, ordered by privilege. "host" is accepted as an alias for
 * "owner" so apps that use either term map onto the same authority level.
 *
 * The permission model is deliberately simple and server-authoritative: the
 * app declares, per action type, the minimum role required (see
 * {@see \Realtime\Service\Permissions}). Roles never come from the client —
 * they are read from the verified participant token.
 */
final class Role
{
    public const OWNER = 'owner';
    public const HOST = 'host';        // alias of OWNER
    public const PARTICIPANT = 'participant';
    public const VIEWER = 'viewer';

    private const RANK = [
        self::VIEWER => 0,
        self::PARTICIPANT => 1,
        self::HOST => 2,
        self::OWNER => 2,
    ];

    public static function isValid(string $role): bool
    {
        return array_key_exists($role, self::RANK);
    }

    public static function normalize(string $role): string
    {
        return self::isValid($role) ? $role : self::VIEWER;
    }

    public static function rank(string $role): int
    {
        return self::RANK[$role] ?? 0;
    }

    /** True if $role is at least as privileged as $required. */
    public static function satisfies(string $role, string $required): bool
    {
        return self::rank($role) >= self::rank($required);
    }
}
