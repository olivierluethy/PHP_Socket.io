<?php

declare(strict_types=1);

namespace Realtime\Support;

/** Server clock. Centralised so state derivations (e.g. playback offsets) share one time source. */
final class Clock
{
    public static function now(): int
    {
        return time();
    }

    /** Milliseconds since epoch — used for playback sync so clients can compute drift. */
    public static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
