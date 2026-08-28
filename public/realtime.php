<?php

declare(strict_types=1);

/**
 * Front controller for the realtime module.
 *
 *  Production (Apache): .htaccess rewrites /realtime/* to this file.
 *  Local dev:  php -S localhost:8000 public/realtime.php
 *              (serves the static demo files and routes /realtime/* to the kernel)
 *
 * Configure via RT_* environment variables (see packages/php-realtime/README).
 * At minimum: RT_DB_DSN, RT_DB_USER, RT_DB_PASSWORD, RT_TOKEN_SECRET.
 */

require __DIR__ . '/../packages/php-realtime/autoload.php';

use Realtime\Realtime;
use Realtime\Storage\MySqlStorageDriver;

// Under the built-in server, let real static files (the demo) serve normally.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($path === '/') {
        header('Location: /index.html');
        exit;
    }
    // Serve the client library straight from the package (no duplicated copy).
    if ($path === '/realtime-client.js') {
        header('Content-Type: application/javascript');
        readfile(__DIR__ . '/../packages/php-realtime/client/realtime-client.js');
        exit;
    }
    if (!str_contains($path, '/realtime/') && is_file(__DIR__ . $path)) {
        return false;
    }
}

$realtime = Realtime::fromEnv()
    // Demo permission wiring: viewers can only read; participants can patch state.
    ->setDefaultPermission(\Realtime\Domain\Role::PARTICIPANT);

// Convenience for local demos: ensure the tables exist. In production, run the
// migration in packages/php-realtime/migrations instead.
if (filter_var(getenv('RT_AUTO_MIGRATE') ?: '', FILTER_VALIDATE_BOOL)) {
    $driver = $realtime->driver();
    if ($driver instanceof MySqlStorageDriver) {
        $driver->ensureSchema();
    }
}

$realtime->run();
