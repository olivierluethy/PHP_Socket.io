<?php

declare(strict_types=1);

/**
 * Zero-dependency PSR-4 autoloader for the Realtime\ namespace.
 *
 * Prefer Composer (`composer.json` declares the same PSR-4 mapping). This file
 * exists so the module also runs on hosts without Composer — just
 * `require 'packages/php-realtime/autoload.php';`.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Realtime\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
