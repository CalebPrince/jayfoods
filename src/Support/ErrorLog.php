<?php

declare(strict_types=1);

/**
 * Site-wide error log, read by the super admin under Error log in the admin.
 *
 * Sources:
 *   server    PHP warnings, uncaught exceptions and fatal errors
 *   api       requests that ended in a 5xx response
 *   outgoing  failed calls to other services (AI providers, Twilio, Meta, Paystack)
 *   email     email that could not be sent
 *   browser   script errors in visitors' browsers on the public pages
 *
 * The same error from the same place within an hour is counted on one row.
 * Keys, tokens and long numbers are masked before saving. Rows older than
 * 30 days are deleted by the nightly clean-up, and the table is capped.
 * Recording never throws: if the database is the problem, PHP's own log gets it.
 */
final class ErrorLog
{
    public const SOURCES = ['server', 'api', 'outgoing', 'email', 'browser'];
    public const KEEP_DAYS = 30;
    private const MAX_ROWS = 5000;
    private static bool $busy = false;
    private static bool $recordedThisRequest = false;

    public static function record(string $source, string $message, string $detail = '', ?string $path = null): void
    {
        if (self::$busy) return; // an error while saving an error must not loop
        self::$busy = true;
        try {
            if (!in_array($source, self::SOURCES, true)) $source = 'server';
            $message = mb_substr(self::clean($message), 0, 500);
            $detail = mb_substr(self::clean($detail), 0, 2000);
            $path = mb_substr(self::clean($path ?? (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)), 0, 300);
            if ($message === '') $message = '(no message)';
            $db = Database::connection();
            $s = $db->prepare("UPDATE error_log SET count=count+1, last_seen=datetime('now'), detail=:d
                WHERE id=(SELECT id FROM error_log WHERE source=:s AND message=:m AND path=:p AND last_seen>=datetime('now','-1 hour') ORDER BY id DESC LIMIT 1)");
            $s->execute([':s' => $source, ':m' => $message, ':p' => $path, ':d' => $detail]);
            if ($s->rowCount() === 0) {
                $db->prepare('INSERT INTO error_log(source,message,detail,path) VALUES(:s,:m,:d,:p)')
                   ->execute([':s' => $source, ':m' => $message, ':d' => $detail, ':p' => $path]);
            }
            self::$recordedThisRequest = true;
        } catch (Throwable $e) {
            error_log('[jayfoods error log] could not save: ' . $e->getMessage() . ' | original: ' . $source . ' ' . $message);
        } finally {
            self::$busy = false;
        }
    }

    /** A failed call to another service. The query string is dropped: some services put keys there. */
    public static function outgoing(string $method, string $url, int $status, string $error, string $body = ''): void
    {
        $parts = parse_url($url) ?: [];
        $where = ($parts['host'] ?? '?') . ($parts['path'] ?? '');
        $reason = $error !== '' ? $error : 'HTTP ' . $status;
        $data = json_decode($body, true);
        $said = is_array($data) ? (string) ($data['error']['message'] ?? $data['message'] ?? (is_string($data['error'] ?? null) ? $data['error'] : '')) : '';
        self::record('outgoing', $method . ' ' . $where . ' failed: ' . $reason, $said !== '' ? $said : mb_substr($body, 0, 300), $where);
    }

    /** Hooks for the web front controller: warnings, uncaught exceptions, fatal errors, and 5xx API responses. */
    public static function install(): void
    {
        set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
            if (!(error_reporting() & $no)) return false; // silenced with @
            self::record('server', $msg, basename($file) . ':' . $line);
            return false; // PHP's own logging carries on as before
        });
        set_exception_handler(static function (Throwable $e): void {
            self::record('server', get_class($e) . ': ' . $e->getMessage(), basename($e->getFile()) . ':' . $e->getLine());
            if (!headers_sent()) Response::json(['error' => 'Something went wrong on our side. Please try again.'], 500);
        });
        register_shutdown_function(static function (): void {
            $last = error_get_last();
            if ($last && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::record('server', $last['message'], basename($last['file']) . ':' . $last['line']);
            }
            $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            $code = (int) http_response_code();
            if (!self::$recordedThisRequest && str_starts_with($path, '/api/') && $code >= 500) {
                self::record('api', ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . $path . ' answered ' . $code, '', $path);
            }
        });
    }

    /** GET /api/v1/admin/errors (super admin) */
    public static function index(): void
    {
        $source = (string) ($_GET['source'] ?? '');
        $where = in_array($source, self::SOURCES, true) ? ' WHERE source=:s' : '';
        $s = Database::connection()->prepare('SELECT id,source,message,detail,path,count,first_seen,last_seen FROM error_log' . $where . ' ORDER BY last_seen DESC, id DESC LIMIT 300');
        $s->execute($where ? [':s' => $source] : []);
        $counts = Database::connection()->query("SELECT source, SUM(count) AS n FROM error_log WHERE last_seen>=datetime('now','-1 day') GROUP BY source")->fetchAll(PDO::FETCH_KEY_PAIR);
        Response::json(['data' => array_map(static fn($r) => $r + ['count' => (int) $r['count']], $s->fetchAll()), 'last_24h' => array_map('intval', $counts ?: [])]);
    }

    /** DELETE /api/v1/admin/errors (super admin): clear the log, or one row with ?id= */
    public static function clear(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id > 0) Database::connection()->prepare('DELETE FROM error_log WHERE id=:id')->execute([':id' => $id]);
        else Database::connection()->exec('DELETE FROM error_log');
        Response::json(['data' => ['ok' => true]]);
    }

    /** POST /api/v1/client-errors: a public page reports a script error. Small, rate-limited, never echoed back. */
    public static function fromBrowser(): void
    {
        $ip = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
        $in = json_decode((string) file_get_contents('php://input', false, null, 0, 4096), true);
        if (!is_array($in) || !AgentMonitor::allow('client-error:' . $ip, 20, 60)) { http_response_code(204); return; }
        $page = (string) parse_url((string) ($in['page'] ?? ''), PHP_URL_PATH);
        $where = (string) ($in['where'] ?? '');
        self::record('browser', (string) ($in['message'] ?? ''), mb_substr($where, 0, 300) . ' | ' . mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), $page !== '' ? $page : '/');
        http_response_code(204);
    }

    /** Nightly clean-up. */
    public static function prune(): int
    {
        $db = Database::connection();
        $n = $db->exec("DELETE FROM error_log WHERE last_seen < datetime('now','-" . self::KEEP_DAYS . " days')") ?: 0;
        $n += $db->exec('DELETE FROM error_log WHERE id NOT IN (SELECT id FROM error_log ORDER BY id DESC LIMIT ' . self::MAX_ROWS . ')') ?: 0;
        return $n;
    }

    /** Masks keys, tokens, emails and phone numbers. */
    public static function clean(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = preg_replace('/(Bearer|Basic)\s+\S+/i', '$1 [hidden]', $text) ?? $text;
        $text = preg_replace('/\b(sk|pk|rk|gsk|xai)[-_][A-Za-z0-9_\-]{8,}/', '[key]', $text) ?? $text;
        $text = preg_replace('/[A-Za-z0-9_\-]{32,}/', '[token]', $text) ?? $text;
        $text = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $text) ?? $text;
        $text = preg_replace('/\+?\d[\d ]{8,}\d/', '[number]', $text) ?? $text;
        return trim($text);
    }
}
