<?php
// Shared bootstrap for every endpoint: config, helpers, CORS, storage.
declare(strict_types=1);

require_once __DIR__ . '/../src/Store.php';

// Runtime data lives outside the web-served api/ and public/ folders.
if (!defined('DATA_DIR')) {
    define('DATA_DIR', __DIR__ . '/../data');
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    echo json_encode($data);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function require_fields(array $body, array $keys): void
{
    $missing = [];
    foreach ($keys as $k) {
        if (!isset($body[$k]) || $body[$k] === '') {
            $missing[] = $k;
        }
    }
    if ($missing) {
        json_out(['error' => 'Missing fields: ' . implode(', ', $missing)], 400);
    }
}

function gen_id(string $prefix = ''): string
{
    return $prefix . bin2hex(random_bytes(9));
}

// Preflight support for browser clients on another origin.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    json_out(['ok' => true]);
}

$store = new Store(DATA_DIR);
