<?php

declare(strict_types=1);

namespace Realtime\Support;

/** Opaque, URL-safe, unguessable identifiers. */
final class Ids
{
    /** 18-hex-char session id (72 bits of entropy). */
    public static function session(): string
    {
        return bin2hex(random_bytes(9));
    }

    /** Prefixed participant/user id, e.g. "u_ab12cd34ef56". */
    public static function participant(string $prefix = 'u_'): string
    {
        return $prefix . bin2hex(random_bytes(6));
    }

    /**
     * Validate an externally-supplied session id. Accepts the module's own hex
     * ids plus app-supplied ids (integer PKs, slugs like "global") — anything
     * URL-safe and free of path separators, so it is safe against traversal.
     */
    public static function isValidSession(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id);
    }
}
