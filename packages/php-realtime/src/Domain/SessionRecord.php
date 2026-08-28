<?php

declare(strict_types=1);

namespace Realtime\Domain;

/** The authoritative state of a session at a point in time. */
final class SessionRecord
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $ownerId,
        /** @var array<string,mixed> Authoritative shared state (the "snapshot"). */
        public readonly array $state,
        /** Monotonic version; equals the seq of the last event applied. */
        public readonly int $version,
        public readonly string $status,
        public readonly int $createdAt,
        public readonly int $updatedAt,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $state = json_decode((string) ($row['state'] ?? '{}'), true);
        return new self(
            id: (string) $row['id'],
            ownerId: isset($row['owner_id']) ? (string) $row['owner_id'] : null,
            state: is_array($state) ? $state : [],
            version: (int) ($row['version'] ?? 0),
            status: (string) ($row['status'] ?? 'active'),
            createdAt: (int) ($row['created_at'] ?? 0),
            updatedAt: (int) ($row['updated_at'] ?? 0),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ownerId' => $this->ownerId,
            'state' => (object) $this->state,
            'version' => $this->version,
            'status' => $this->status,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
