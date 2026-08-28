<?php

declare(strict_types=1);

namespace Realtime\Transport;

use Realtime\Config;
use Realtime\Domain\Identity;
use Realtime\Http\Response;
use Realtime\Service\SessionService;
use Realtime\Storage\StorageDriver;

/**
 * Tier A transport. Holds the request up to RT_LONGPOLL_TIMEOUT waiting for new
 * events, returning immediately when they arrive (→ ~sub-second latency) or an
 * empty batch on timeout (the client re-polls). The same endpoint serves
 * short-poll clients: they pass a fresh `since` each time and get whatever is
 * available without a meaningful wait. Works on any PHP host.
 */
final class LongPoll
{
    public function __construct(
        private SessionService $service,
        private StorageDriver $storage,
        private Config $config,
    ) {
    }

    public function handle(string $sessionId, int $since, Identity $actor): Response
    {
        // Snapshot-on-gap and the first (immediate) event check.
        $first = $this->service->syncOnce($sessionId, $since);
        if ($first['kind'] === 'snapshot' || $first['events'] !== []) {
            $this->storage->heartbeat($sessionId, $actor->userId);
            return Response::json($first);
        }

        @set_time_limit($this->config->longPollTimeout + 5);
        $deadline = microtime(true) + $this->config->longPollTimeout;

        while (microtime(true) < $deadline && !connection_aborted()) {
            $remainingMs = (int) max(0, ($deadline - microtime(true)) * 1000);

            $awaited = $this->storage->awaitEvents($sessionId, $since, min($remainingMs, 1000));
            if ($awaited === null) {
                usleep($this->config->pollIntervalUs);
                $events = $this->storage->eventsSince($sessionId, $since);
            } else {
                $events = $awaited;
            }

            if ($events !== []) {
                $this->storage->heartbeat($sessionId, $actor->userId);
                $last = $events[count($events) - 1];
                return Response::json([
                    'kind' => 'events',
                    'events' => array_map(static fn ($e) => $e->toArray(), $events),
                    'cursor' => $last->seq,
                ]);
            }
        }

        $this->storage->heartbeat($sessionId, $actor->userId);
        return Response::json(['kind' => 'events', 'events' => [], 'cursor' => $since]);
    }
}
