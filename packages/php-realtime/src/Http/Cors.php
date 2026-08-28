<?php

declare(strict_types=1);

namespace Realtime\Http;

use Realtime\Config;

/** Config-driven CORS. No wildcard leaks into production unless explicitly set. */
final class Cors
{
    public function __construct(private Config $config)
    {
    }

    /** @return array<string,string> headers to attach to a response */
    public function headers(Request $request): array
    {
        $allowed = $this->config->corsOrigins;
        $origin = $request->header('origin');

        if (in_array('*', $allowed, true)) {
            $allowOrigin = '*';
        } elseif ($origin !== null && in_array($origin, $allowed, true)) {
            $allowOrigin = $origin;
        } else {
            return []; // origin not allowed → no CORS headers
        }

        return [
            'Access-Control-Allow-Origin' => $allowOrigin,
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Realtime-Token',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ];
    }

    public function isPreflight(Request $request): bool
    {
        return $request->method === 'OPTIONS';
    }
}
