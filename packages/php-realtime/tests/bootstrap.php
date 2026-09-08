<?php

declare(strict_types=1);

/**
 * Test bootstrap. Loads the module's own (Composer-free) autoloader and adds a
 * second PSR-4 mapping for the test namespace, so the suite runs with nothing but
 * PHP installed: `php tests/run.php`.
 */
require __DIR__ . '/../autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Realtime\\Tests\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
