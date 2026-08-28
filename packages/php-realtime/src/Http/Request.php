<?php

declare(strict_types=1);

namespace Realtime\Http;

use Realtime\Support\Json;

/**
 * Framework-agnostic view of an incoming HTTP request. Built from PHP globals
 * by default, but can be constructed explicitly so the kernel is easy to embed
 * in any framework (or test without a web server).
 */
final class Request
{
    /**
     * @param array<string,string> $query
     * @param array<string,string> $headers header names lower-cased
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly string $rawBody,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return new self(
            $method,
            rtrim($path, '/') ?: '/',
            array_map('strval', $_GET),
            $headers,
            (string) file_get_contents('php://input'),
        );
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        return Json::decodeAssoc($this->rawBody, 'request body');
    }

    public function query(string $key, ?string $default = null): ?string
    {
        return $this->query[$key] ?? $default;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Participant token, from Authorization: Bearer, the X-Realtime-Token header,
     * or a ?token= query param (the last is needed for SSE EventSource, which
     * cannot set request headers).
     */
    public function token(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth !== null && stripos($auth, 'bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return $this->header('x-realtime-token') ?? $this->query('token');
    }
}
