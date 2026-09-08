<?php

declare(strict_types=1);

namespace Realtime\Tests\Integration;

use Realtime\Domain\Identity;
use Realtime\Domain\Role;
use Realtime\Exception\RateLimitException;
use Realtime\Service\SessionService;
use Realtime\Tests\Support\Factory;
use Realtime\Tests\Support\TestCase;

/**
 * Per-participant fixed-window rate limiting on actions — one of the module's
 * abuse defences (blueprint §6).
 */
final class RateLimitTest extends TestCase
{
    private Factory $factory;
    private SessionService $service;

    public function __construct()
    {
        $this->factory = new Factory(['actionRateLimit' => 3, 'rateWindow' => 10]);
        $this->service = $this->factory->service();
    }

    public function testActionsAreLimitedPerParticipantPerWindow(): void
    {
        $created = $this->service->create('owner-1', ['queue' => []]);
        $sid = $created['sessionId'];
        $actor = new Identity($sid, 'owner-1', Role::OWNER);

        // 3 are allowed within the window.
        for ($i = 0; $i < 3; $i++) {
            $this->service->submitAction($actor, 'queue.add', ['item' => "s{$i}"]);
        }

        // The 4th trips the limiter.
        $e = $this->assertThrows(
            RateLimitException::class,
            fn () => $this->service->submitAction($actor, 'queue.add', ['item' => 'overflow'])
        );
        $this->assertSame(429, $e->httpStatus());
    }

    public function testLimitResetsInTheNextWindow(): void
    {
        $created = $this->service->create('owner-1', ['queue' => []]);
        $sid = $created['sessionId'];
        $actor = new Identity($sid, 'owner-1', Role::OWNER);

        for ($i = 0; $i < 3; $i++) {
            $this->service->submitAction($actor, 'queue.add', ['item' => "s{$i}"]);
        }

        // Advance past the window boundary; the counter resets.
        $this->factory->storage->travel(11);
        $r = $this->service->submitAction($actor, 'queue.add', ['item' => 'next-window']);
        $this->assertSame(4, $r['version']);
    }

    public function testLimitIsPerParticipant(): void
    {
        $created = $this->service->create('owner-1', ['queue' => []]);
        $sid = $created['sessionId'];
        $owner = new Identity($sid, 'owner-1', Role::OWNER);
        $guest = new Identity($sid, 'guest-1', Role::PARTICIPANT);

        for ($i = 0; $i < 3; $i++) {
            $this->service->submitAction($owner, 'queue.add', ['item' => "o{$i}"]);
        }

        // A different participant has their own bucket — not blocked by the owner.
        $r = $this->service->submitAction($guest, 'queue.add', ['item' => 'g0']);
        $this->assertSame(4, $r['version']);
    }
}
