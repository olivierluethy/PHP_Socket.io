<?php

declare(strict_types=1);

namespace Realtime\Domain;

/** One entry in a session's append-only event log. */
final class EventRecord
{
    public function __construct(
        public readonly int $seq,
        public readonly string $type,
        /** @var array<string,mixed> */
        public readonly array $payload,
        public readonly ?string $actorId,
        public readonly int $createdAt,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
        return new self(
            seq: (int) $row['seq'],
            type: (string) $row['type'],
            payload: is_array($payload) ? $payload : [],
            actorId: isset($row['actor_id']) ? (string) $row['actor_id'] : null,
            createdAt: (int) ($row['created_at'] ?? 0),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'seq' => $this->seq,
            'type' => $this->type,
            'payload' => (object) $this->payload,
            'actorId' => $this->actorId,
            'ts' => $this->createdAt,
        ];
    }
}
