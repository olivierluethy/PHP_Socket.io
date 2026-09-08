<?php

declare(strict_types=1);

namespace Realtime\Tests\Integration;

use Realtime\Domain\Identity;
use Realtime\Domain\Role;
use Realtime\Service\SessionService;
use Realtime\Tests\Support\Factory;
use Realtime\Tests\Support\TestCase;

/**
 * Delta sync: clients pull events after a cursor and get a full snapshot when
 * they have fallen behind the rolling retention window (blueprint §2).
 */
final class SyncTest extends TestCase
{
    private Factory $factory;
    private SessionService $service;

    public function __construct()
    {
        // Tiny retention so the gap path is reachable in a few actions.
        $this->factory = new Factory(['eventRetention' => 3]);
        $this->service = $this->factory->service();
    }

    private function seed(int $actions): string
    {
        $created = $this->service->create('owner-1', ['queue' => []]);
        $sid = $created['sessionId'];
        $actor = new Identity($sid, 'owner-1', Role::OWNER);
        for ($i = 0; $i < $actions; $i++) {
            $this->service->submitAction($actor, 'queue.add', ['item' => "song-{$i}"]);
        }
        return $sid;
    }

    public function testSyncReturnsEventsAfterCursor(): void
    {
        $sid = $this->seed(2);

        $res = $this->service->syncOnce($sid, 0);
        $this->assertSame('events', $res['kind']);
        $this->assertCount(2, $res['events']);
        $this->assertSame(2, $res['cursor']);

        // Nothing new after the latest cursor.
        $empty = $this->service->syncOnce($sid, 2);
        $this->assertSame('events', $empty['kind']);
        $this->assertCount(0, $empty['events']);
        $this->assertSame(2, $empty['cursor']);
    }

    public function testSnapshotOnGapWhenClientFellBehindRetention(): void
    {
        // retention = 3; after 6 actions the oldest retained seq is 4.
        $sid = $this->seed(6);
        $this->assertSame(3, $this->factory->storage->eventCount($sid), 'retention trims to keep N');
        $this->assertSame(4, $this->factory->storage->oldestRetainedSeq($sid));

        // A client at since=0 is below the window → hard resync via snapshot.
        $res = $this->service->syncOnce($sid, 0);
        $this->assertSame('snapshot', $res['kind']);
        $this->assertSame(6, $res['snapshot']['version']);

        // A client that is current stays on the fast delta path.
        $res2 = $this->service->syncOnce($sid, 5);
        $this->assertSame('events', $res2['kind']);
        $this->assertCount(1, $res2['events']);
    }

    public function testSnapshotStateSurvivesResync(): void
    {
        $sid = $this->seed(6);
        $res = $this->service->syncOnce($sid, 0);
        // The snapshot carries the full authoritative queue, not just deltas.
        $this->assertCount(6, (array) $res['snapshot']['state']->queue);
    }
}
