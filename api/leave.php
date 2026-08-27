<?php
require __DIR__ . '/bootstrap.php';
$body = read_json_body();
require_fields($body, ['sessionId', 'participantId']);
$store->publish($body['sessionId'], 'participant.leave', (string) $body['participantId'], []);
$store->removeParticipant($body['sessionId'], (string) $body['participantId']);
json_out(['ok' => true]);
