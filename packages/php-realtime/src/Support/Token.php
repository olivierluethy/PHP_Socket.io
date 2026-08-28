<?php

declare(strict_types=1);

namespace Realtime\Support;

/**
 * Stateless, HMAC-signed participant tokens (a compact JWT-like format).
 *
 * A token binds a participant to a specific session and role. It is issued
 * server-side on create/join and MUST be presented on every mutating action,
 * heartbeat and stream request. The server never trusts a client-supplied
 * session/role — it reads them from the verified token, not the request body.
 *
 * Format: base64url(payload) . "." . base64url(HMAC-SHA256(payload, secret))
 */
final class Token
{
    /**
     * @param array{sid:string,uid:string,role:string} $claims
     */
    public static function issue(array $claims, string $secret, int $ttlSeconds): string
    {
        $payload = [
            'sid' => $claims['sid'],
            'uid' => $claims['uid'],
            'role' => $claims['role'],
            'iat' => time(),
            'exp' => time() + $ttlSeconds,
        ];
        $body = self::b64(Json::encode($payload));
        return $body . '.' . self::b64(self::mac($body, $secret));
    }

    /**
     * Verify a token and return its claims, or null if invalid/expired.
     *
     * @return array{sid:string,uid:string,role:string,iat:int,exp:int}|null
     */
    public static function verify(string $token, string $secret): ?array
    {
        // When no secret is configured (local dev), tokens are unsigned pass-throughs.
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$body, $sig] = $parts;

        $expected = self::b64(self::mac($body, $secret));
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $decoded = json_decode(self::unb64($body), true);
        if (!is_array($decoded) || !isset($decoded['sid'], $decoded['uid'], $decoded['role'], $decoded['exp'])) {
            return null;
        }
        if ((int) $decoded['exp'] < time()) {
            return null;
        }
        return $decoded;
    }

    private static function mac(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret, true);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $b64): string
    {
        return (string) base64_decode(strtr($b64, '-_', '+/'), true);
    }
}
