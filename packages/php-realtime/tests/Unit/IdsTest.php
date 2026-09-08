<?php

declare(strict_types=1);

namespace Realtime\Tests\Unit;

use Realtime\Support\Ids;
use Realtime\Tests\Support\TestCase;

/** Session-id validation is a security boundary: it must reject path traversal. */
final class IdsTest extends TestCase
{
    public function testGeneratedIdsAreValidAndUnique(): void
    {
        $a = Ids::session();
        $b = Ids::session();
        $this->assertTrue(Ids::isValidSession($a));
        $this->assertFalse($a === $b, 'session ids should be unique');
    }

    public function testAcceptsAppSuppliedIds(): void
    {
        $this->assertTrue(Ids::isValidSession('42'));        // integer PK
        $this->assertTrue(Ids::isValidSession('global'));    // slug
        $this->assertTrue(Ids::isValidSession('room_A-1'));  // mixed
    }

    public function testRejectsTraversalAndSeparators(): void
    {
        $this->assertFalse(Ids::isValidSession('../etc/passwd'));
        $this->assertFalse(Ids::isValidSession('a/b'));
        $this->assertFalse(Ids::isValidSession('a b'));
        $this->assertFalse(Ids::isValidSession(''));
        $this->assertFalse(Ids::isValidSession(str_repeat('x', 65)));
    }

    public function testParticipantIdsCarryPrefix(): void
    {
        $this->assertTrue(str_starts_with(Ids::participant(), 'u_'));
        $this->assertTrue(str_starts_with(Ids::participant('g_'), 'g_'));
    }
}
