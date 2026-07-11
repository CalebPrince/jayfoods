<?php
declare(strict_types=1);
final class SecretBox
{
    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', Config::jwtSecret(), true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new RuntimeException('Could not encrypt the secret.');
        return base64_encode($iv . $tag . $cipher);
    }
    public static function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Saved secret is invalid.');
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', Config::jwtSecret(), true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) throw new RuntimeException('Could not decrypt the secret.');
        return $plain;
    }
}
