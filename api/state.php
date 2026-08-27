<?php
// Current snapshot for a late join / reconnect.
require __DIR__ . '/bootstrap.php';

$sessionId = (string) ($_GET['sessionId'] ?? '');
if (!$store->exists($sessionId)) {
    json_out(['error' => 'Unknown session'], 404);
}
$meta = $store->meta($sessionId);
json_out([
    'snapshot' => $meta['state'] ?? new stdClass(),
    'participants' => $store->participants($sessionId),
    'cursor' => $store->currentSeq($sessionId),
]);
