<?php

declare(strict_types=1);

/**
 * Small runtime configuration helper. The JWT signing key lives in a file
 * outside version control (database/app.key); it is created lazily if missing
 * so the API keeps working even when migrate.php has not been run yet.
 */
final class Config
{
    public const JWT_TTL_SECONDS = 60 * 60 * 8; // 8 hours
    public const COOKIE_NAME     = 'jf_token';

    public static function jwtSecret(): string
    {
        $path = dirname(__DIR__, 2) . '/database/app.key';

        if (!is_file($path)) {
            file_put_contents($path, bin2hex(random_bytes(32)));
            @chmod($path, 0600);
        }

        return trim((string) file_get_contents($path));
    }
}
