<?php
require __DIR__ . '/bootstrap.php';
$body = read_json_body();
require_fields($body, ['name']);
$res = $store->createSession((string) $body['name'], (array) ($body['state'] ?? []));
json_out(['sessionId' => $res['sessionId'], 'hostId' => $res['hostId'], 'cursor' => 0]);
