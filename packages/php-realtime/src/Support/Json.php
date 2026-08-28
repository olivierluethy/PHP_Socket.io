<?php

declare(strict_types=1);

namespace Realtime\Support;

use Realtime\Exception\RealtimeException;

/** Strict JSON helpers so a malformed payload fails loudly instead of silently. */
final class Json
{
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    public static function decodeAssoc(string $json, string $context = 'payload'): array
    {
        if (trim($json) === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RealtimeException("Invalid JSON in {$context}: {$e->getMessage()}", 400);
        }
        return is_array($decoded) ? $decoded : [];
    }
}
