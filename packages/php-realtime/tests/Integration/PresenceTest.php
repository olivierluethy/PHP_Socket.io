<?php

declare(strict_types=1);

namespace Realtime\Tests\Integration;

use Realtime\Domain\Identity;
use Realtime\Domain\Role;
use Realtime\Service\SessionService;
use Realtime\Tests\Support\Factory;
use Realtime\Tests\Support\TestCase;

/**
 * Presence: live viewer counts and rosters that self-heal after a silent
 * disconnect (blueprint §5 / audit §5). A timed-out participant must be pruned
 * on read AND produce a presence event, so other clients watching the event
 * stream see the count drop — the PHP equivalent of the Node reaper's rebroadcast.
 */
final class PresenceTest extends TestCase
{
    private Factory $factory;
    private SessionService $service;

    public function __construct()
    {
        $this->factory = new Factory(['presenceTtl' => 30]);
        $this->service = $this->factory->service();
    }

    public function testJoinAndLeaveEmitPresenceEvents(): void
    {
        $created = $this->service->create('owner-1');
        $sid = $created['sessionId'];

        $this->service->join($sid, 'guest-1');
        $events = $this->service->syncOnce($sid, 0)['events'];
        $this->assertCount(1, $events);
        $this->assertSame('presence', $events[0]['type']);
        $this->assertSame(2, $events[0]['payload']->count);

        $guest = new Identity($sid, 'guest-1', Role::PARTICIPANT);
        $this->service->leave($guest);

        $snapshot = $this->service->snapshot($sid);
        $this->assertSame(1, $snapshot['viewerCount']);
    }

    public function testHeartbeatReportsCurrentCountAndRoster(): void
    {
        $created = $this->service->create('owner-1', [], ['name' => 'Ada']);
        $sid = $created['sessionId'];
        $this->service->join($sid, 'guest-1', ['name' => 'Bob']);

        $owner = new Identity($sid, 'owner-1', Role::OWNER);
        $res = $this->service->heartbeat($owner);

        $this->assertSame(2, $res['count']);
        $this->assertCount(2, $res['participants']);
    }

    public function testTimedOutParticipantIsPrunedAndBroadcast(): void
    {
        $created = $this->service->create('owner-1');
        $sid = $created['sessionId'];
        $this->service->join($sid, 'guest-1');

        $versionBefore = $this->service->snapshot($sid)['version'];

        // Guest's tab closed silently: its heartbeat is now older than the TTL.
        $this->factory->storage->backdateHeartbeat($sid, 'guest-1', 60);

        // Any surviving client's heartbeat drives the lazy reaper.
        $owner = new Identity($sid, 'owner-1', Role::OWNER);
        $res = $this->service->heartbeat($owner);

        // The stale guest is gone from the live count...
        $this->assertSame(1, $res['count']);
        $this->assertNull($this->factory->storage->findParticipant($sid, 'guest-1'));

        // ...and other clients learn about it through a new presence event.
        $after = $this->service->syncOnce($sid, $versionBefore);
        $this->assertSame('events', $after['kind']);
        $this->assertCount(1, $after['events'], 'a presence timeout event is broadcast');
        $this->assertSame('presence', $after['events'][0]['type']);
        $this->assertSame(1, $after['events'][0]['payload']->count);
    }

    public function testFreshHeartbeatDoesNotBroadcast(): void
    {
        $created = $this->service->create('owner-1');
        $sid = $created['sessionId'];
        $this->service->join($sid, 'guest-1');

        $versionBefore = $this->service->snapshot($sid)['version'];

        // Everyone is still active → a heartbeat must not append noise events.
        $owner = new Identity($sid, 'owner-1', Role::OWNER);
        $this->service->heartbeat($owner);

        $after = $this->service->syncOnce($sid, $versionBefore);
        $this->assertCount(0, $after['events'], 'no presence churn when nobody left');
    }
}
