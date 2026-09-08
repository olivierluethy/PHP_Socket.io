<?php

declare(strict_types=1);

namespace Realtime\Tests\Unit;

use Realtime\Domain\Role;
use Realtime\Service\Permissions;
use Realtime\Tests\Support\TestCase;

/** The per-action minimum-role map the host app declares. */
final class PermissionsTest extends TestCase
{
    public function testFallsBackToDefaultForUnlistedActions(): void
    {
        $p = new Permissions([], Role::PARTICIPANT);
        $this->assertSame(Role::PARTICIPANT, $p->requiredRole('anything'));
        $this->assertTrue($p->allows(Role::PARTICIPANT, 'anything'));
        $this->assertFalse($p->allows(Role::VIEWER, 'anything'));
    }

    public function testPerActionRuleOverridesDefault(): void
    {
        $p = new Permissions(['playback.set' => Role::OWNER], Role::PARTICIPANT);

        $this->assertTrue($p->allows(Role::OWNER, 'playback.set'));
        $this->assertFalse($p->allows(Role::PARTICIPANT, 'playback.set'));
        // A different action still uses the default.
        $this->assertTrue($p->allows(Role::PARTICIPANT, 'queue.add'));
    }

    public function testWithRuleIsImmutable(): void
    {
        $base = new Permissions([], Role::PARTICIPANT);
        $stricter = $base->withRule('delete', Role::OWNER);

        $this->assertFalse($stricter->allows(Role::PARTICIPANT, 'delete'));
        // The original is unchanged.
        $this->assertTrue($base->allows(Role::PARTICIPANT, 'delete'));
    }
}
