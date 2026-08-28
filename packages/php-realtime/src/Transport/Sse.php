<?php

declare(strict_types=1);

namespace Realtime\Transport;

use Realtime\Config;
use Realtime\Domain\Identity;
use Realtime\Service\SessionService;
use Realtime\Storage\StorageDriver;
use Realtime\Support\Json;

/**
 * Tier B transport. Holds one response open and pushes events as `data:` frames,
 * so the client sees changes without re-requesting. Auto-negotiated: the JS
 * client tries this first where enabled and falls back to long-poll on failure.
 *
 * This method streams and then exits — it never returns to the kernel.
 */
final class Sse
{
    public function __construct(
        private SessionService $service,
        private StorageDriver $storage,
        private Config $config,
    ) {
    }

    public function stream(string $sessionId, int $since, Identity $actor, array $corsHeaders = []): void
    {
        foreach ($corsHeaders as $name => $value) {
            header("{$name}: {$value}");
        }
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // stop nginx/FPM from buffering the stream

        @set_time_limit(0);
        ignore_user_abort(false);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $this->emit('hello', ['retry' => $this->config->shortPollInterval, 'heartbeatMs' => $this->config->heartbeatInterval * 1000]);
        echo 'retry: ' . ($this->config->shortPollInterval) . "\n\n";

        // Prime with a snapshot when the client is behind the retained window.
        $cursor = $since;
        $sync = $this->service->syncOnce($sessionId, $since);
        if ($sync['kind'] === 'snapshot') {
            $this->emit('snapshot', $sync['snapshot']);
            $cursor = (int) $sync['snapshot']['version'];
        }

        $deadline = time() + $this->config->sseMaxLifetime;
        $lastBeat = time();
        $this->flush();

        while (!connection_aborted() && time() < $deadline) {
            $awaited = $this->storage->awaitEvents($sessionId, $cursor, 1000);
            $events = $awaited ?? $this->storage->eventsSince($sessionId, $cursor);

            if ($events !== []) {
                foreach ($events as $event) {
                    $this->emitEvent($event->seq, $event->toArray());
                    $cursor = $event->seq;
                }
                $this->storage->heartbeat($sessionId, $actor->userId);
                $this->flush();
            } elseif (time() - $lastBeat >= 15) {
                echo ": ping\n\n"; // comment frame keeps the connection & presence alive
                $this->storage->heartbeat($sessionId, $actor->userId);
                $lastBeat = time();
                $this->flush();
            }

            if ($awaited === null) {
                usleep($this->config->pollIntervalUs);
            }
        }

        // Ask the client to reconnect (roll the connection so workers recycle).
        $this->emit('reconnect', ['cursor' => $cursor]);
        $this->flush();
        exit;
    }

    /** @param array<string,mixed> $data */
    private function emit(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . Json::encode($data) . "\n\n";
    }

    /** @param array<string,mixed> $data */
    private function emitEvent(int $id, array $data): void
    {
        echo "id: {$id}\n";
        echo "event: message\n";
        echo 'data: ' . Json::encode($data) . "\n\n";
    }

    private function flush(): void
    {
        @flush();
    }
}
