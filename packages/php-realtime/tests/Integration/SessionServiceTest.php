<?php

declare(strict_types=1);

namespace Realtime\Tests\Integration;

use Realtime\Domain\Identity;
use Realtime\Domain\Role;
use Realtime\Exception\ForbiddenException;
use Realtime\Exception\NotFoundException;
use Realtime\Exception\RealtimeException;
use Realtime\Exception\VersionConflictException;
use Realtime\Service\SessionService;
use Realtime\Tests\Support\Factory;
use Realtime\Tests\Support\TestCase;

/**
 * End-to-end behaviour of the service that the HTTP kernel sits on: create/join/
 * leave, action application, the optimistic version check (no lost updates), and
 * server-authoritative permission enforcement.
 */
final class SessionServiceTest extends TestCase
{
    private Factory $factory;
    private SessionService $service;

    public function __construct()
    {
        $this->factory = new Factory();
        $this->service = $this->factory->service();
    }

    private function owner(string $sessionId, string $userId): Identity
    {
        return new Identity($sessionId, $userId, Role::OWNER);
    }

    public function testCreateStartsAtVersionZeroWithSnapshot(): void
    {
        $res = $this->service->create('owner-1', ['queue' => []]);

        $this->assertSame(Role::OWNER, $res['role']);
        $this->assertSame('owner-1', $res['userId']);
        $this->assertSame(0, $res['snapshot']['version']);
        $this->assertSame(1, $res['snapshot']['viewerCount'], 'owner is present after create');
        $this->assertTrue(is_string($res['token']) && $res['token'] !== '', 'a token is issued');
    }

    public function testJoinAppendsPresenceEventAndBumpsVersion(): void
    {
        $created = $this->service->create('owner-1');
        $sid = $created['sessionId'];

        $joined = $this->service->join($sid, 'guest-1');

        $this->assertSame('guest-1', $joined['userId']);
        $this->assertSame(Role::PARTICIPANT, $joined['role']);
        // One presence event => version advanced to 1.
        $this->assertSame(1, $joined['snapshot']['version']);
        $this->assertSame(2, $joined['snapshot']['viewerCount']);
    }

    public function testOwnerIsRecognisedOnRejoin(): void
    {
        $created = $this->service->create('owner-1');
        $sid = $created['sessionId'];

        // Joining again with the owner id resolves back to the owner role.
        $rejoined = $this->service->join($sid, 'owner-1');
        $this->assertSame(Role::OWNER, $rejoined['role']);
    }

    public function testSubmitActionAppliesReducerAndKeepsSeqEqualToVersion(): void
    {
        $created = $this->service->create('owner-1', ['queue' => []]);
        $sid = $created['sessionId'];
        $actor = $this->owner($sid, 'owner-1');

        $r1 = $this->service->submitAction($actor, 'queue.add', ['item' => 'song-A']);
        $r2 = $this->service->submitAction($actor, 'queue.add', ['item' => 'song-B']);

        // seq == version invariant (blueprint §2).
        $this->assertSame(1, $r1['version']);
        $this->assertSame(1, $r1['event']['seq']);
        $this->assertSame(2, $r2['version']);
        $this->assertSame(2, $r2['event']['seq']);

        $snapshot = $this->service->snapshot($sid);
        $this->assertSame(2, $snapshot['version']);
        $this->assertSame(['song-A', 'song-B'], (array) $snapshot['state']->queue);
    }

    public function testActionCanRewriteBroadcastEventType(): void
    {
        $created = $this->service->create('owner-1');
        $sid = $created['sessionId'];
        $actor = $this->owner($sid, 'owner-1');

        $res = $this->service->submitAction($actor, 'playback.set', ['videoId' => 'yt-123']);
        // The handler broadcasts as 'playback_sync' even though the request type was 'playback.set'.
        $this->assertSame('playback_sync', $res['event']['type']);
    }

    public function testOptimisticVersionCheckRejectsStaleWrite(): void
    {
        $created = $this->service->create('owner-1', ['queue' => []]);
        $sid = $created['sessionId'];
        $actor = $this->owner($sid, 'owner-1');

        // Two writers both read baseVersion 0.
        $this->service->submitAction($actor, 'queue.add', ['item' => 'first'], 0);

        // The second, still based on version 0, is a lost update → rejected.
        $e = $this->assertThrows(
            VersionConflictException::class,
            fn () => $this->service->submitAction($actor, 'queue.add', ['item' => 'second'], 0)
        );
        $this->assertSame(409, $e->httpStatus());
    }

    public function testCommutativeActionMayOmitBaseVersion(): void
    {
        $created = $this->service->create('owner-1', ['queue' => []]);
        $sid = $created['sessionId'];
        $actor = $this->owner($sid, 'owner-1');

        // No baseVersion => append is allowed to proceed regardless of version.
        $this->service->submitAction($actor, 'queue.add', ['item' => 'a']);
        $r = $this->service->submitAction($actor, 'queue.add', ['item' => 'b']);
        $this->assertSame(2, $r['version']);
    }

    public function testPermissionEnforcedFromRoleNotRequest(): void
    {
        $created = $this->service->create('owner-1');
        $sid = $created['sessionId'];
        $participant = new Identity($sid, 'guest-1', Role::PARTICIPANT);

        // playback.set requires OWNER; a participant is forbidden.
        $e = $this->assertThrows(
            ForbiddenException::class,
            fn () => $this->service->submitAction($participant, 'playback.set', ['videoId' => 'x'])
        );
        $this->assertSame(403, $e->httpStatus());
    }

    public function testUnknownActionIsRejected(): void
    {
        $created = $this->service->create('owner-1');
        $sid = $created['sessionId'];
        $actor = $this->owner($sid, 'owner-1');

        $e = $this->assertThrows(
            RealtimeException::class,
            fn () => $this->service->submitAction($actor, 'does.not.exist', [])
        );
        $this->assertSame(422, $e->httpStatus());
    }

    public function testCreateWithDuplicateAppSuppliedIdIsRejected(): void
    {
        $this->service->create('owner-1', [], [], 'fixed-id');
        $e = $this->assertThrows(
            RealtimeException::class,
            fn () => $this->service->create('owner-2', [], [], 'fixed-id')
        );
        $this->assertSame(409, $e->httpStatus());
    }

    public function testActionOnMissingSessionThrowsNotFound(): void
    {
        $actor = $this->owner('ghost', 'owner-1');
        $this->assertThrows(
            NotFoundException::class,
            fn () => $this->service->submitAction($actor, 'queue.add', ['item' => 'x'])
        );
    }

    public function testSystemEventFansOutWithoutToken(): void
    {
        $created = $this->service->create('owner-1', ['title' => 'old']);
        $sid = $created['sessionId'];

        $res = $this->service->systemEvent($sid, 'session_renamed', ['title' => 'new'], ['title' => 'new']);
        $this->assertSame(1, $res['version']);
        $this->assertSame('session_renamed', $res['event']['type']);

        $snapshot = $this->service->snapshot($sid);
        $this->assertSame('new', $snapshot['state']->title);
    }
}
