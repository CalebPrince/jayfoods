<?php

declare(strict_types=1);

/**
 * PDO/SQLite connection singleton (native pdo_sqlite, no ORM).
 */
final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $path = dirname(__DIR__, 2) . '/database/jayfoods.sqlite';

            self::$instance = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            self::$instance->exec('PRAGMA foreign_keys = ON;');
        }

        return self::$instance;
    }
}
