<?php

declare(strict_types=1);

/**
 * Hand-rolled JWT (HS256) — no external libraries.
 * Only the HS256 algorithm is supported, by design.
 */
final class Jwt
{
    /**
     * @param array<string, mixed> $claims
     */
    public static function encode(array $claims): string
    {
        $header  = ['typ' => 'JWT', 'alg' => 'HS256'];
        $segments = [
            self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES)),
        ];

        $signingInput = implode('.', $segments);
        $signature    = hash_hmac('sha256', $signingInput, Config::jwtSecret(), true);
        $segments[]   = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * Verify signature + expiry and return the claims, or null when invalid.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headB64, $payloadB64, $sigB64] = $parts;

        $expected = hash_hmac(
            'sha256',
            $headB64 . '.' . $payloadB64,
            Config::jwtSecret(),
            true
        );
        $provided = self::base64UrlDecode($sigB64);

        if (!hash_equals($expected, $provided)) {
            return null;
        }

        $claims = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!is_array($claims)) {
            return null;
        }

        if (isset($claims['exp']) && time() >= (int) $claims['exp']) {
            return null;
        }

        return $claims;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
