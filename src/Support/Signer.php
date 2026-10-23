<?php

declare(strict_types=1);

/**
 * Short signed tokens for links and sessions that must not be guessable or
 * last forever: "<payload>.<expires>.<hmac>". Each purpose gets its own key
 * derived from the app key, so a chat session can never be used as an
 * invoice link and the other way round.
 */
final class Signer
{
    public static function sign(string $purpose, string $payload, int $expiresAt): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $payload)) {
            throw new InvalidArgumentException('Signed payloads must be letters, digits, _ or -.');
        }
        $body = $payload . '.' . $expiresAt;
        return $body . '.' . self::mac($purpose, $body);
    }

    /** Returns the payload when the token is genuine and unexpired, otherwise null. */
    public static function verify(string $purpose, string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || !ctype_digit($parts[1])) return null;
        [$payload, $expires, $mac] = $parts;
        if (!hash_equals(self::mac($purpose, $payload . '.' . $expires), $mac)) return null;
        if ((int) $expires < time()) return null;
        return $payload;
    }

    private static function mac(string $purpose, string $body): string
    {
        $key = hash_hmac('sha256', 'jayfoods-signer:' . $purpose, Config::jwtSecret(), true);
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $key, true)), '+/', '-_'), '=');
    }
}
