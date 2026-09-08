<?php

declare(strict_types=1);

namespace Realtime\Tests\Support;

/**
 * Minimal, zero-dependency test base class. The module deliberately runs without
 * Composer (see the bundled autoloader), so its tests do too: every method whose
 * name starts with "test" is discovered and run by tests/run.php, and the assert*
 * helpers throw {@see AssertionFailed} on failure. No PHPUnit required.
 */
abstract class TestCase
{
    private int $assertions = 0;

    public function assertionCount(): int
    {
        return $this->assertions;
    }

    protected function assertTrue(bool $cond, string $message = ''): void
    {
        $this->assertions++;
        if (!$cond) {
            throw new AssertionFailed($message !== '' ? $message : 'Expected true, got false');
        }
    }

    protected function assertFalse(bool $cond, string $message = ''): void
    {
        $this->assertTrue(!$cond, $message !== '' ? $message : 'Expected false, got true');
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ' — ' : '')
                . 'Expected ' . $this->export($expected) . ', got ' . $this->export($actual)
            );
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected != $actual) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ' — ' : '')
                . 'Expected ' . $this->export($expected) . ' (loose), got ' . $this->export($actual)
            );
        }
    }

    protected function assertNull(mixed $actual, string $message = ''): void
    {
        $this->assertSame(null, $actual, $message);
    }

    protected function assertNotNull(mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($actual === null) {
            throw new AssertionFailed($message !== '' ? $message : 'Expected non-null, got null');
        }
    }

    protected function assertCount(int $expected, array $actual, string $message = ''): void
    {
        $this->assertSame($expected, count($actual), $message !== '' ? $message : 'Unexpected count');
    }

    /**
     * Assert that $fn throws $exceptionClass. Returns the caught exception so the
     * caller can make further assertions (e.g. on the HTTP status).
     *
     * @param class-string<\Throwable> $exceptionClass
     */
    protected function assertThrows(string $exceptionClass, callable $fn, string $message = ''): \Throwable
    {
        $this->assertions++;
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!($e instanceof $exceptionClass)) {
                throw new AssertionFailed(
                    ($message !== '' ? $message . ' — ' : '')
                    . "Expected {$exceptionClass}, got " . $e::class . ': ' . $e->getMessage()
                );
            }
            return $e;
        }
        throw new AssertionFailed(
            ($message !== '' ? $message . ' — ' : '') . "Expected {$exceptionClass}, but nothing was thrown"
        );
    }

    private function export(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_null($value) => 'null',
            is_scalar($value) => var_export($value, true),
            default => gettype($value),
        };
    }
}
