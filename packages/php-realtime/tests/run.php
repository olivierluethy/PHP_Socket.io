<?php

declare(strict_types=1);

/**
 * Zero-dependency test runner. Discovers every *Test.php under tests/Unit and
 * tests/Integration, runs each public method whose name starts with "test", and
 * reports pass/fail counts. Exit code is non-zero if any test fails, so it plugs
 * straight into CI.
 *
 * Usage:  php tests/run.php  [--filter=Substring]
 */

require __DIR__ . '/bootstrap.php';

use Realtime\Tests\Support\TestCase;

$filter = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--filter=')) {
        $filter = substr($arg, strlen('--filter='));
    }
}

$dirs = [__DIR__ . '/Unit', __DIR__ . '/Integration'];
$files = [];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if (str_ends_with($entry, 'Test.php')) {
            $files[] = $dir . '/' . $entry;
        }
    }
}
sort($files);
foreach ($files as $file) {
    require_once $file;
}

$total = 0;
$failed = 0;
$assertions = 0;
$failures = [];
$start = microtime(true);

foreach (get_declared_classes() as $class) {
    if (!is_subclass_of($class, TestCase::class)) {
        continue;
    }
    $reflection = new ReflectionClass($class);
    if ($reflection->isAbstract()) {
        continue;
    }

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!str_starts_with($method->getName(), 'test')) {
            continue;
        }
        $label = $class . '::' . $method->getName();
        if ($filter !== null && !str_contains($label, $filter)) {
            continue;
        }

        $total++;
        /** @var TestCase $instance */
        $instance = new $class();
        try {
            $method->invoke($instance);
            $assertions += $instance->assertionCount();
            echo "\033[32m.\033[0m";
        } catch (\Throwable $e) {
            $assertions += $instance->assertionCount();
            $failed++;
            $failures[] = [$label, $e];
            echo "\033[31mF\033[0m";
        }
    }
}

$elapsed = number_format((microtime(true) - $start) * 1000, 1);
echo "\n\n";

if ($failures !== []) {
    echo "Failures:\n";
    foreach ($failures as [$label, $e]) {
        echo "\n  \033[31m✗ {$label}\033[0m\n";
        echo '    ' . $e->getMessage() . "\n";
        echo '    at ' . $e->getFile() . ':' . $e->getLine() . "\n";
    }
    echo "\n";
}

$passed = $total - $failed;
$color = $failed === 0 ? "\033[32m" : "\033[31m";
echo "{$color}Tests: {$total}, Passed: {$passed}, Failed: {$failed}, Assertions: {$assertions}\033[0m";
echo "  ({$elapsed} ms)\n";

exit($failed === 0 ? 0 : 1);
