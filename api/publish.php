<?php
require __DIR__ . '/bootstrap.php';
$body = read_json_body();
require_fields($body, ['sessionId', 'participantId', 'type']);
if (!$store->exists($body['sessionId'])) {
    json_out(['error' => 'Unknown session'], 404);
}
$seq = $store->publish(
    $body['sessionId'],
    (string) $body['type'],
    (string) $body['participantId'],
    $body['data'] ?? null
);
$store->touch($body['sessionId'], (string) $body['participantId']);
json_out(['seq' => $seq]);
