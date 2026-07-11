<?php

declare(strict_types=1);

final class SmtpMailer
{
    private array $settings;
    /** @var resource|null */
    private $socket = null;

    public function __construct()
    {
        $row = Database::connection()->query('SELECT * FROM smtp_settings WHERE id = 1')->fetch();
        $this->settings = $row ?: [];
    }

    public function isConfigured(): bool
    {
        return filter_var($this->settings['username'] ?? '', FILTER_VALIDATE_EMAIL)
            && !empty($this->settings['password_encrypted']);
    }

    public function notificationEmail(): string
    {
        return (string) ($this->settings['notification_email'] ?? '');
    }

    public function send(string $to, string $subject, string $html, string $text = ''): void
    {
        if (!$this->isConfigured()) throw new RuntimeException('SMTP is not fully configured.');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Recipient email is invalid.');

        $host = (string) $this->settings['host'];
        $port = (int) $this->settings['port'];
        $ssl = $this->settings['encryption'] === 'ssl';
        $target = ($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errorNumber = 0; $errorMessage = '';
        $this->socket = @stream_socket_client($target, $errorNumber, $errorMessage, 15, STREAM_CLIENT_CONNECT);
        if (!$this->socket) throw new RuntimeException("SMTP connection failed: $errorMessage");
        stream_set_timeout($this->socket, 15);

        try {
            $this->expect([220]);
            $this->command('EHLO jayfoods.local', [250]);
            if (!$ssl) {
                $this->command('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Could not establish SMTP encryption.');
                }
                $this->command('EHLO jayfoods.local', [250]);
            }
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode((string) $this->settings['username']), [334]);
            $this->command(base64_encode($this->decrypt((string) $this->settings['password_encrypted'])), [235]);
            $this->command('MAIL FROM:<' . $this->settings['username'] . '>', [250]);
            $this->command('RCPT TO:<' . $to . '>', [250, 251]);
            $this->command('DATA', [354]);
            $this->write($this->message($to, $subject, $html, $text) . "\r\n.\r\n");
            $this->expect([250]);
            $this->command('QUIT', [221]);
        } finally {
            if (is_resource($this->socket)) fclose($this->socket);
            $this->socket = null;
        }
    }

    private function message(string $to, string $subject, string $html, string $text): string
    {
        $boundary = 'jf_' . bin2hex(random_bytes(12));
        $fromName = $this->header((string) $this->settings['sender_name']);
        $subject = $this->header($subject);
        $text = $text !== '' ? $text : trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html))));
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $fromName . ' <' . $this->settings['username'] . '>',
            'To: <' . $to . '>',
            'Subject: ' . $subject,
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@jayfoods.local>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html)) . "--$boundary--";
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        return preg_replace('/^\./m', '..', $message);
    }

    private function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Saved SMTP password is invalid.');
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', Config::jwtSecret(), true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) throw new RuntimeException('Could not decrypt the SMTP password.');
        return $plain;
    }

    private function command(string $line, array $codes): string
    {
        $this->write($line . "\r\n");
        return $this->expect($codes);
    }

    private function write(string $data): void
    {
        if (!is_resource($this->socket) || fwrite($this->socket, $data) === false) throw new RuntimeException('SMTP write failed.');
    }

    private function expect(array $codes): string
    {
        $response = '';
        do {
            $line = fgets($this->socket, 515);
            if ($line === false) throw new RuntimeException('SMTP server stopped responding.');
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) throw new RuntimeException('SMTP error: ' . trim($response));
        return $response;
    }

    private function header(string $value): string
    {
        $value = trim(str_replace(["\r", "\n"], '', $value));
        return preg_match('/[^\x20-\x7E]/', $value)
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }
}
