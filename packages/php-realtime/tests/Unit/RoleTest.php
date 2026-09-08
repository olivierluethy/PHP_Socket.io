<?php

declare(strict_types=1);

namespace Realtime\Tests\Unit;

use Realtime\Domain\Role;
use Realtime\Tests\Support\TestCase;

/** The privilege ordering that every authorization decision rests on. */
final class RoleTest extends TestCase
{
    public function testOwnerAndHostRankEqually(): void
    {
        $this->assertSame(Role::rank(Role::HOST), Role::rank(Role::OWNER));
        $this->assertTrue(Role::satisfies(Role::HOST, Role::OWNER));
        $this->assertTrue(Role::satisfies(Role::OWNER, Role::HOST));
    }

    public function testPrivilegeOrdering(): void
    {
        $this->assertTrue(Role::satisfies(Role::OWNER, Role::PARTICIPANT));
        $this->assertTrue(Role::satisfies(Role::PARTICIPANT, Role::VIEWER));
        $this->assertTrue(Role::satisfies(Role::PARTICIPANT, Role::PARTICIPANT));

        $this->assertFalse(Role::satisfies(Role::VIEWER, Role::PARTICIPANT));
        $this->assertFalse(Role::satisfies(Role::PARTICIPANT, Role::OWNER));
    }

    public function testNormalizeFallsBackToViewer(): void
    {
        $this->assertSame(Role::OWNER, Role::normalize('owner'));
        $this->assertSame(Role::VIEWER, Role::normalize('superadmin'));
        $this->assertSame(Role::VIEWER, Role::normalize(''));
    }

    public function testIsValid(): void
    {
        $this->assertTrue(Role::isValid(Role::PARTICIPANT));
        $this->assertFalse(Role::isValid('root'));
    }
}
