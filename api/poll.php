<?php
// Long-poll: block up to ~25s waiting for events after ?since, then return.
// The primary transport (works on standard shared PHP hosting).
require __DIR__ . '/bootstrap.php';

$sessionId = (string) ($_GET['sessionId'] ?? '');
$pid = (string) ($_GET['participantId'] ?? '');
$since = (int) ($_GET['since'] ?? 0);

if (!$store->exists($sessionId)) {
    json_out(['error' => 'Unknown session'], 404);
}

@set_time_limit(30);
$deadline = microtime(true) + 25;

while (true) {
    $events = $store->eventsSince($sessionId, $since);
    if ($events) {
        $last = $events[count($events) - 1];
        if ($pid !== '') {
            $store->touch($sessionId, $pid);
        }
        json_out(['events' => $events, 'cursor' => $last['seq']]);
    }
    if (microtime(true) >= $deadline || connection_aborted()) {
        if ($pid !== '') {
            $store->touch($sessionId, $pid);
        }
        json_out(['events' => [], 'cursor' => $since]);
    }
    usleep(500000);
}
