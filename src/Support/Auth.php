<?php

declare(strict_types=1);

/**
 * Reads the JWT from the HttpOnly cookie, validates it, and confirms the
 * admin still exists. Results are cached for the duration of the request.
 */
final class Auth
{
    /** @var array<string, mixed>|null|false false = not yet resolved */
    private static $cached = false;

    /**
     * @return array<string, mixed>|null  The admin row (id, email, name) or null.
     */
    public static function user(): ?array
    {
        if (self::$cached !== false) {
            return self::$cached;
        }

        self::$cached = null;

        $token = $_COOKIE[Config::COOKIE_NAME] ?? '';
        if ($token === '') {
            return null;
        }

        $claims = Jwt::decode($token);
        if ($claims === null || !isset($claims['sub'])) {
            return null;
        }

        $stmt = Database::connection()->prepare(
            'SELECT id, email, name FROM admins WHERE id = :id AND is_active=1'
        );
        $stmt->execute([':id' => (int) $claims['sub']]);
        $admin = $stmt->fetch();

        if ($admin) {
            self::$cached = [
                'id'    => (int) $admin['id'],
                'email' => $admin['email'],
                'name'  => $admin['name'],
            ];
        }

        return self::$cached;
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user ? (int) $user['id'] : null;
    }

    /**
     * Halt with 401 unless a valid admin is authenticated.
     */
    public static function requireAdmin(): void
    {
        if (self::user() === null) {
            Response::json(['error' => 'Unauthorized. Please sign in.'], 401);
            exit;
        }
    }

    /**
     * Reset cache (used right after login/logout mutates the cookie).
     */
    public static function forget(): void
    {
        self::$cached = false;
    }
}
