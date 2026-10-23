<?php

declare(strict_types=1);

/**
 * Brings the database up to date after a deploy, so a cPanel "Update from
 * Remote" never leaves new code running against an old database.
 *
 * On each request it compares a fingerprint of database/schema.sql and
 * database/migrate.php with the one saved after the last successful run
 * (database/schema.stamp, outside the web root). When they differ, one
 * request runs the same idempotent migration as `php database/migrate.php`,
 * under a file lock so two requests never run it at once. A failure goes to
 * the error log and is retried after five minutes; the request carries on.
 */
final class AutoMigrate
{
    private const RETRY_SECONDS = 300;

    /** @return string 'current', 'migrated', 'waiting' (a recent failure) or 'failed' */
    public static function ensure(): string
    {
        $root = dirname(__DIR__, 2);
        $dir = dirname(getenv('JAYFOODS_DB') ?: $root . '/database/jayfoods.sqlite');
        $stamp = $dir . '/schema.stamp';
        $want = self::fingerprint($root);
        if ($want === '' || @file_get_contents($stamp) === $want) return 'current';

        $failed = $dir . '/schema.failed';
        if (is_file($failed) && time() - (int) @filemtime($failed) < self::RETRY_SECONDS) return 'waiting';

        $lock = @fopen($dir . '/migrate.lock', 'c');
        if ($lock === false) return 'failed';
        try {
            flock($lock, LOCK_EX);
            if (@file_get_contents($stamp) === $want) return 'current'; // another request just did it
            ob_start();
            try {
                (static function () use ($root): void { require $root . '/database/migrate.php'; })();
                $out = (string) ob_get_clean();
            } catch (Throwable $e) {
                ob_end_clean();
                @touch($failed);
                if (class_exists('ErrorLog', false)) ErrorLog::record('server', 'Automatic database update failed: ' . $e->getMessage(), 'database/migrate.php');
                return 'failed';
            }
            // A brand-new database gets a first admin with a random password: keep it for the owner, readable on the server only.
            if (str_contains($out, 'Seeded default admin')) {
                @file_put_contents($dir . '/first-run-admin.txt', $out);
                @chmod($dir . '/first-run-admin.txt', 0600);
            }
            file_put_contents($stamp, $want);
            @unlink($failed);
            return 'migrated';
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function fingerprint(string $root): string
    {
        $parts = [];
        foreach (['schema.sql', 'migrate.php'] as $f) {
            $hash = @sha1_file($root . '/database/' . $f);
            if ($hash === false) return '';
            $parts[] = $hash;
        }
        return sha1(implode(':', $parts));
    }
}
