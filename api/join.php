<?php
require __DIR__ . '/bootstrap.php';
$body = read_json_body();
require_fields($body, ['sessionId', 'name']);
if (!$store->exists($body['sessionId'])) {
    json_out(['error' => 'Unknown session'], 404);
}
// Cursor captured BEFORE the join event so the newcomer's first poll receives
// its own (and concurrent) join events.
$cursor = $store->currentSeq($body['sessionId']);
$pid = $store->addParticipant($body['sessionId'], (string) $body['name']);
$store->publish($body['sessionId'], 'participant.join', (string) $pid, ['name' => $body['name']]);
$meta = $store->meta($body['sessionId']);
json_out([
    'participantId' => $pid,
    'snapshot' => $meta['state'] ?? new stdClass(),
    'participants' => $store->participants($body['sessionId']),
    'cursor' => $cursor,
]);
