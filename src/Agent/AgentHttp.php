<?php

declare(strict_types=1);

/**
 * The one place Kafui talks HTTP (AI providers and Meta). Tests replace
 * $transport with a fake so nothing leaves the machine.
 */
final class AgentHttp
{
    /** @var (callable(string,string,array,?string,int):array{status:int,body:string,error:string})|null */
    public static $transport = null;

    /** @return array{status:int,body:string,error:string} */
    public static function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
    {
        $res = self::$transport !== null ? (self::$transport)($method, $url, $headers, $body, $timeoutSeconds) : self::send($method, $url, $headers, $body, $timeoutSeconds);
        if ($res['status'] < 200 || $res['status'] >= 400 || $res['error'] !== '') {
            if (class_exists('ErrorLog', false)) ErrorLog::outgoing($method, $url, (int) $res['status'], (string) $res['error'], (string) $res['body']);
        }
        return $res;
    }

    /** @return array{status:int,body:string,error:string} */
    private static function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'PHP cURL is not available on this server.'];
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(6, max(1, $timeoutSeconds)),
            CURLOPT_TIMEOUT        => max(1, $timeoutSeconds),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        return ['status' => $status, 'body' => is_string($raw) ? $raw : '', 'error' => $error];
    }

    /** @return array<mixed> decoded JSON, or throws with a short reason (never the request, which holds keys) */
    public static function json(string $method, string $url, array $headers, array $payload, int $timeoutSeconds): array
    {
        $res = self::request($method, $url, array_merge(['Content-Type: application/json'], $headers), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $timeoutSeconds);
        $data = json_decode($res['body'], true);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            $msg = is_array($data) ? (string) ($data['error']['message'] ?? $data['error']['type'] ?? $data['message'] ?? '') : '';
            throw new RuntimeException('HTTP ' . $res['status'] . ($res['error'] !== '' ? ' ' . $res['error'] : '') . ($msg !== '' ? ' ' . mb_substr($msg, 0, 160) : ''));
        }
        if (!is_array($data)) throw new RuntimeException('Response was not JSON.');
        return $data;
    }
}
