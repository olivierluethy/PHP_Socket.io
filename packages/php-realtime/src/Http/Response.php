<?php

declare(strict_types=1);

namespace Realtime\Http;

use Realtime\Support\Json;

/** A buffered HTTP response. Streaming endpoints (SSE) bypass this. */
final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    /** @param mixed $data */
    public static function json($data, int $status = 200, array $headers = []): self
    {
        return new self($status, Json::encode($data), ['Content-Type' => 'application/json'] + $headers);
    }

    public static function noContent(int $status = 204, array $headers = []): self
    {
        return new self($status, '', $headers);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $this->body;
    }
}
