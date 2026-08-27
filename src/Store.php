<?php
// File-based, flock-coordinated session store. Portable across standard PHP
// hosting: no database, no persistent process. Each session is a directory with
// a JSON snapshot (meta), an append-only NDJSON event log, participants, and a
// monotonic sequence counter.
declare(strict_types=1);

class Store
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = $root;
        if (!is_dir($root)) {
            @mkdir($root . '/sessions', 0775, true);
        }
    }

    private function dir(string $sessionId): string
    {
        // sessionId is server-generated hex, but guard against traversal anyway.
        if (!preg_match('/^[a-f0-9]{2,40}$/', $sessionId)) {
            return '';
        }
        return $this->root . '/sessions/' . $sessionId;
    }

    public function exists(string $sessionId): bool
    {
        $d = $this->dir($sessionId);
        return $d !== '' && is_dir($d);
    }

    // Run $fn while holding the session's exclusive lock.
    private function withLock(string $sessionId, callable $fn)
    {
        $d = $this->dir($sessionId);
        if ($d === '' || !is_dir($d)) {
            return null;
        }
        $lock = fopen($d . '/.lock', 'c');
        if ($lock === false) {
            return null;
        }
        flock($lock, LOCK_EX);
        try {
            return $fn($d);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function createSession(string $name, array $state): array
    {
        $id = bin2hex(random_bytes(9));
        $hostId = 'p_' . bin2hex(random_bytes(6));
        $d = $this->dir($id);
        @mkdir($d, 0775, true);
        file_put_contents($d . '/seq.txt', '0');
        file_put_contents($d . '/events.log', '');
        file_put_contents($d . '/participants.json', json_encode(new stdClass()));
        file_put_contents($d . '/meta.json', json_encode([
            'id' => $id,
            'name' => $name,
            'hostId' => $hostId,
            'createdAt' => time(),
            'state' => (object) $state,
        ]));
        return ['sessionId' => $id, 'hostId' => $hostId];
    }

    public function meta(string $sessionId): ?array
    {
        $d = $this->dir($sessionId);
        if ($d === '' || !is_file($d . '/meta.json')) {
            return null;
        }
        return json_decode((string) file_get_contents($d . '/meta.json'), true);
    }

    public function currentSeq(string $sessionId): int
    {
        $d = $this->dir($sessionId);
        if ($d === '' || !is_file($d . '/seq.txt')) {
            return 0;
        }
        return (int) file_get_contents($d . '/seq.txt');
    }

    public function participants(string $sessionId): array
    {
        $d = $this->dir($sessionId);
        if ($d === '' || !is_file($d . '/participants.json')) {
            return [];
        }
        $p = json_decode((string) file_get_contents($d . '/participants.json'), true);
        return is_array($p) ? $p : [];
    }

    // Append an event atomically, bump the sequence, and (for state.* events)
    // merge the payload into the session snapshot so late joiners are correct.
    public function publish(string $sessionId, string $type, string $from, $data): ?int
    {
        return $this->withLock($sessionId, function (string $d) use ($type, $from, $data) {
            $seq = ((int) file_get_contents($d . '/seq.txt')) + 1;
            file_put_contents($d . '/seq.txt', (string) $seq);

            $event = [
                'seq' => $seq,
                'ts' => microtime(true),
                'type' => $type,
                'from' => $from,
                'data' => $data,
            ];
            file_put_contents($d . '/events.log', json_encode($event) . "\n", FILE_APPEND);

            if ($type === 'state.patch' && is_array($data)) {
                $meta = json_decode((string) file_get_contents($d . '/meta.json'), true);
                $state = (array) ($meta['state'] ?? []);
                $meta['state'] = array_merge($state, $data);
                file_put_contents($d . '/meta.json', json_encode($meta));
            }
            return $seq;
        });
    }

    // Events with seq > $since.
    public function eventsSince(string $sessionId, int $since): array
    {
        $d = $this->dir($sessionId);
        if ($d === '' || !is_file($d . '/events.log')) {
            return [];
        }
        $out = [];
        $fh = fopen($d . '/events.log', 'r');
        if ($fh === false) {
            return [];
        }
        flock($fh, LOCK_SH);
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $ev = json_decode($line, true);
            if (is_array($ev) && ($ev['seq'] ?? 0) > $since) {
                $out[] = $ev;
            }
        }
        flock($fh, LOCK_UN);
        fclose($fh);
        return $out;
    }

    public function addParticipant(string $sessionId, string $name): ?string
    {
        return $this->withLock($sessionId, function (string $d) use ($name) {
            $pid = 'p_' . bin2hex(random_bytes(6));
            $parts = json_decode((string) file_get_contents($d . '/participants.json'), true) ?: [];
            $parts[$pid] = ['name' => $name, 'joinedAt' => time(), 'lastSeen' => time()];
            file_put_contents($d . '/participants.json', json_encode($parts));
            return $pid;
        });
    }

    public function touch(string $sessionId, string $pid): void
    {
        $this->withLock($sessionId, function (string $d) use ($pid) {
            $parts = json_decode((string) file_get_contents($d . '/participants.json'), true) ?: [];
            if (isset($parts[$pid])) {
                $parts[$pid]['lastSeen'] = time();
                file_put_contents($d . '/participants.json', json_encode($parts));
            }
        });
    }

    public function removeParticipant(string $sessionId, string $pid): void
    {
        $this->withLock($sessionId, function (string $d) use ($pid) {
            $parts = json_decode((string) file_get_contents($d . '/participants.json'), true) ?: [];
            unset($parts[$pid]);
            file_put_contents($d . '/participants.json', json_encode($parts));
        });
    }
}
