<?php

declare(strict_types=1);

/**
 * Admin authentication: login (issues JWT in an HttpOnly cookie), logout,
 * the current-session probe, and password change.
 */
final class AuthController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function login(): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true);
        $email = trim((string) ($input['email'] ?? ''));
        $pass  = (string) ($input['password'] ?? '');

        if ($email === '' || $pass === '') {
            Response::json(['error' => 'Email and password are required.'], 422);
            return;
        }

        $stmt = $this->db->prepare('SELECT id, email, name, password_hash FROM admins WHERE email = :email AND is_active=1');
        $stmt->execute([':email' => $email]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($pass, $admin['password_hash'])) {
            Response::json(['error' => 'Invalid email or password.'], 401);
            return;
        }

        $now = time();
        $token = Jwt::encode([
            'sub'   => (int) $admin['id'],
            'email' => $admin['email'],
            'name'  => $admin['name'],
            'iat'   => $now,
            'exp'   => $now + Config::JWT_TTL_SECONDS,
        ]);

        $this->setCookie($token, $now + Config::JWT_TTL_SECONDS);
        Auth::forget();

        Response::json([
            'data' => [
                'id'    => (int) $admin['id'],
                'email' => $admin['email'],
                'name'  => $admin['name'],
            ],
        ]);
    }

    public function logout(): void
    {
        $this->setCookie('', time() - 3600);
        Auth::forget();
        Response::json(['data' => ['ok' => true]]);
    }

    /**
     * Returns the current admin, or 401 if the session is missing/expired.
     */
    public function me(): void
    {
        $user = Auth::user();
        if ($user === null) {
            Response::json(['error' => 'Unauthorized.'], 401);
            return;
        }
        Response::json(['data' => $user]);
    }

    public function changePassword(): void
    {
        $input   = json_decode((string) file_get_contents('php://input'), true);
        $current = (string) ($input['current_password'] ?? '');
        $next    = (string) ($input['new_password'] ?? '');

        if (strlen($next) < 8) {
            Response::json(['error' => 'New password must be at least 8 characters.'], 422);
            return;
        }

        $id   = Auth::id();
        $stmt = $this->db->prepare('SELECT password_hash FROM admins WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($current, $row['password_hash'])) {
            Response::json(['error' => 'Current password is incorrect.'], 422);
            return;
        }

        $update = $this->db->prepare('UPDATE admins SET password_hash = :hash WHERE id = :id');
        $update->execute([
            ':hash' => password_hash($next, PASSWORD_DEFAULT),
            ':id'   => $id,
        ]);

        Response::json(['data' => ['ok' => true]]);
    }

    public function updateProfile(): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true);
        $name  = trim((string) ($input['name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($errors) {
            Response::json(['error' => 'Please correct the highlighted fields.', 'fields' => $errors], 422);
            return;
        }

        $id = Auth::id();
        $duplicate = $this->db->prepare('SELECT id FROM admins WHERE lower(email) = lower(:email) AND id != :id');
        $duplicate->execute([':email' => $email, ':id' => $id]);
        if ($duplicate->fetch()) {
            Response::json(['error' => 'That email address is already in use.', 'fields' => ['email' => 'Email is already in use.']], 422);
            return;
        }

        $stmt = $this->db->prepare('UPDATE admins SET name = :name, email = :email WHERE id = :id');
        $stmt->execute([':name' => $name, ':email' => $email, ':id' => $id]);
        Auth::forget();

        Response::json(['data' => Auth::user()]);
    }

    private function setCookie(string $value, int $expires): void
    {
        setcookie(Config::COOKIE_NAME, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure'   => $this->isHttpsRequest(),
        ]);
    }

    /**
     * PHP commonly exposes HTTPS as the literal string "off" on plain HTTP.
     * Treating any non-empty value as true creates a Secure cookie that the
     * browser will not return, causing an immediate redirect back to login.
     */
    private function isHttpsRequest(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return true;
        }

        // Honour the standard proxy header when TLS terminates upstream.
        $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return $forwardedProto === 'https';
    }
}
