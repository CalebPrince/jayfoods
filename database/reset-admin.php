<?php

declare(strict_types=1);

/**
 * One-time command-line administrator password reset.
 *
 * Usage:
 *   php database/reset-admin.php admin@example.com 'NewStrongPassword'
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php database/reset-admin.php <email> <new-password>\n");
    exit(1);
}

$email = strtolower(trim((string) $argv[1]));
$password = (string) $argv[2];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "[reset-admin] Enter a valid administrator email address.\n");
    exit(1);
}

if (strlen($password) < 12) {
    fwrite(STDERR, "[reset-admin] The new password must contain at least 12 characters.\n");
    exit(1);
}

$dbPath = __DIR__ . '/jayfoods.sqlite';
if (!is_file($dbPath)) {
    fwrite(STDERR, "[reset-admin] Database not found. Run database/migrate.php first.\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $stmt = $pdo->prepare(
        'UPDATE admins SET password_hash = :hash WHERE lower(email) = lower(:email)'
    );
    $stmt->execute([
        ':hash' => password_hash($password, PASSWORD_DEFAULT),
        ':email' => $email,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "[reset-admin] Reset failed: {$e->getMessage()}\n");
    exit(1);
}

if ($stmt->rowCount() !== 1) {
    fwrite(STDERR, "[reset-admin] No administrator found with email: $email\n");
    exit(1);
}

echo "[reset-admin] Password updated successfully for $email\n";

