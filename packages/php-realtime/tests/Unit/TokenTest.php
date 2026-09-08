<?php

declare(strict_types=1);

namespace Realtime\Tests\Unit;

use Realtime\Support\Token;
use Realtime\Tests\Support\TestCase;

/**
 * The signed participant token is the module's authentication primitive: the
 * server reads session/role from a verified token, never from the request body.
 * These tests pin down that a valid token round-trips and that every tampering,
 * expiry and wrong-secret path is rejected.
 */
final class TokenTest extends TestCase
{
    private const SECRET = 'a-long-random-secret-value';

    public function testIssueAndVerifyRoundTrip(): void
    {
        $token = Token::issue(['sid' => 's1', 'uid' => 'u1', 'role' => 'owner'], self::SECRET, 3600);
        $claims = Token::verify($token, self::SECRET);

        $this->assertNotNull($claims);
        $this->assertSame('s1', $claims['sid']);
        $this->assertSame('u1', $claims['uid']);
        $this->assertSame('owner', $claims['role']);
        $this->assertTrue($claims['exp'] > time());
    }

    public function testRejectsWrongSecret(): void
    {
        $token = Token::issue(['sid' => 's1', 'uid' => 'u1', 'role' => 'owner'], self::SECRET, 3600);
        $this->assertNull(Token::verify($token, 'a-different-secret'));
    }

    public function testRejectsTamperedPayload(): void
    {
        // Forge a token that claims role=owner but is signed for role=viewer.
        $viewer = Token::issue(['sid' => 's1', 'uid' => 'u1', 'role' => 'viewer'], self::SECRET, 3600);
        [$body, $sig] = explode('.', $viewer);

        $decoded = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
        $decoded['role'] = 'owner';
        $forgedBody = rtrim(strtr(base64_encode(json_encode($decoded)), '+/', '-_'), '=');

        // Re-attach the OLD signature (attacker cannot compute the new HMAC).
        $this->assertNull(Token::verify($forgedBody . '.' . $sig, self::SECRET));
    }

    public function testRejectsExpiredToken(): void
    {
        // exp is in the past.
        $token = Token::issue(['sid' => 's1', 'uid' => 'u1', 'role' => 'owner'], self::SECRET, -10);
        $this->assertNull(Token::verify($token, self::SECRET));
    }

    public function testRejectsMalformedToken(): void
    {
        $this->assertNull(Token::verify('not-a-token', self::SECRET));
        $this->assertNull(Token::verify('a.b.c', self::SECRET));
        $this->assertNull(Token::verify('', self::SECRET));
    }

    public function testSignatureIsUrlSafe(): void
    {
        $token = Token::issue(['sid' => 's1', 'uid' => 'u1', 'role' => 'participant'], self::SECRET, 3600);
        // base64url: no +, /, or = padding — safe in a query string for EventSource.
        $this->assertFalse(str_contains($token, '+'));
        $this->assertFalse(str_contains($token, '/'));
        $this->assertFalse(str_contains($token, '='));
    }
}
