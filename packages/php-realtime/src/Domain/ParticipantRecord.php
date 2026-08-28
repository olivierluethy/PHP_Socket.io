<?php

declare(strict_types=1);

namespace Realtime\Domain;

/** A participant in a session, with presence timing. */
final class ParticipantRecord
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $userId,
        public readonly string $role,
        /** @var array<string,mixed> App-defined metadata (display name, avatar, ...). */
        public readonly array $meta,
        public readonly int $lastHeartbeatAt,
        public readonly int $joinedAt,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $meta = json_decode((string) ($row['meta'] ?? '{}'), true);
        return new self(
            sessionId: (string) $row['session_id'],
            userId: (string) $row['user_id'],
            role: (string) $row['role'],
            meta: is_array($meta) ? $meta : [],
            lastHeartbeatAt: (int) ($row['last_heartbeat_at'] ?? 0),
            joinedAt: (int) ($row['joined_at'] ?? 0),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'userId' => $this->userId,
            'role' => $this->role,
            'meta' => (object) $this->meta,
            'lastHeartbeatAt' => $this->lastHeartbeatAt,
            'joinedAt' => $this->joinedAt,
        ];
    }
}
