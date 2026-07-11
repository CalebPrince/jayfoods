<?php

declare(strict_types=1);

final class SmtpSettingsController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function show(): void
    {
        $row = $this->db->query(
            'SELECT host, port, encryption, sender_name, username,
                    notification_email, password_encrypted
               FROM smtp_settings WHERE id = 1'
        )->fetch();

        Response::json(['data' => $this->present($row ?: [])]);
    }

    public function update(): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : [];

        $host       = trim((string) ($input['host'] ?? 'smtp.gmail.com'));
        $port       = (int) ($input['port'] ?? 587);
        $encryption = strtolower(trim((string) ($input['encryption'] ?? 'tls')));
        $senderName = trim((string) ($input['sender_name'] ?? ''));
        $username   = strtolower(trim((string) ($input['username'] ?? '')));
        $notify     = strtolower(trim((string) ($input['notification_email'] ?? '')));
        $password   = preg_replace('/\s+/', '', (string) ($input['password'] ?? ''));
        $errors     = [];

        if ($host === '') $errors['host'] = 'SMTP host is required.';
        if ($port < 1 || $port > 65535) $errors['port'] = 'Enter a valid port.';
        if (!in_array($encryption, ['tls', 'ssl'], true)) $errors['encryption'] = 'Choose TLS or SSL.';
        if ($senderName === '') $errors['sender_name'] = 'Sender name is required.';
        if (!filter_var($username, FILTER_VALIDATE_EMAIL)) $errors['username'] = 'Enter a valid Gmail address.';
        if (!filter_var($notify, FILTER_VALIDATE_EMAIL)) $errors['notification_email'] = 'Enter a valid notification email.';
        if ($password !== '' && strlen($password) !== 16) $errors['password'] = 'Google App Passwords contain 16 characters.';

        if ($errors) {
            Response::json(['error' => 'Please correct the highlighted fields.', 'fields' => $errors], 422);
            return;
        }

        $current = $this->db->query('SELECT password_encrypted FROM smtp_settings WHERE id = 1')->fetch();
        $encrypted = (string) ($current['password_encrypted'] ?? '');
        if ($password !== '') {
            try {
                $encrypted = $this->encrypt($password);
            } catch (RuntimeException $e) {
                Response::json(['error' => $e->getMessage(), 'fields' => ['password' => $e->getMessage()]], 500);
                return;
            }
        }

        $stmt = $this->db->prepare(
            'INSERT INTO smtp_settings
                (id, host, port, encryption, sender_name, username, notification_email, password_encrypted, updated_at)
             VALUES (1, :host, :port, :encryption, :sender_name, :username, :notification_email, :password, datetime(\'now\'))
             ON CONFLICT(id) DO UPDATE SET
                host = excluded.host, port = excluded.port, encryption = excluded.encryption,
                sender_name = excluded.sender_name, username = excluded.username,
                notification_email = excluded.notification_email,
                password_encrypted = excluded.password_encrypted, updated_at = datetime(\'now\')'
        );
        $stmt->execute([
            ':host' => $host, ':port' => $port, ':encryption' => $encryption,
            ':sender_name' => $senderName, ':username' => $username,
            ':notification_email' => $notify, ':password' => $encrypted,
        ]);

        Response::json(['data' => $this->present([
            'host' => $host, 'port' => $port, 'encryption' => $encryption,
            'sender_name' => $senderName, 'username' => $username,
            'notification_email' => $notify, 'password_encrypted' => $encrypted,
        ])]);
    }

    public function test(): void
    {
        $mailer = new SmtpMailer();
        $recipient = $mailer->notificationEmail();
        if (!$mailer->isConfigured() || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            Response::json(['error' => 'Save a Gmail address, App Password and notification email first.'], 422);
            return;
        }

        try {
            $mailer->send(
                $recipient,
                'Jay fooDs SMTP test',
                '<!doctype html><html><body style="font-family:Arial,sans-serif"><h2 style="color:#176b3a">SMTP is working</h2><p>Your Jay fooDs website can send email successfully.</p></body></html>'
            );
        } catch (Throwable $e) {
            Response::json(['error' => 'Test email failed: ' . $e->getMessage()], 502);
            return;
        }

        Response::json(['data' => ['ok' => true, 'recipient' => $recipient]]);
    }

    /** @param array<string, mixed> $row */
    private function present(array $row): array
    {
        return [
            'host' => $row['host'] ?? 'smtp.gmail.com',
            'port' => (int) ($row['port'] ?? 587),
            'encryption' => $row['encryption'] ?? 'tls',
            'sender_name' => $row['sender_name'] ?? 'Jay fooDs',
            'username' => $row['username'] ?? '',
            'notification_email' => $row['notification_email'] ?? '',
            'has_password' => !empty($row['password_encrypted']),
        ];
    }

    private function encrypt(string $plain): string
    {
        $key = hash('sha256', Config::jwtSecret(), true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Could not securely store the SMTP password.');
        }
        return base64_encode($iv . $tag . $cipher);
    }
}
