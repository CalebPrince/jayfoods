<?php

declare(strict_types=1);

/**
 * Counters, alert emails and clean-up for Kafui (baseline CTL-LOG-002,
 * CTL-DATA-001, MON-001 to MON-005).
 */
final class AgentMonitor
{
    public const TRANSCRIPT_DAYS = 90;
    public const INVOICE_DAYS = 30;

    public static function event(string $type, string $detail = ''): void
    {
        try {
            Database::connection()->prepare('INSERT INTO agent_events(type,detail) VALUES(:t,:d)')->execute([':t' => $type, ':d' => mb_substr($detail, 0, 300)]);
            self::checkThresholds($type);
        } catch (Throwable $e) {
            error_log('[kafui] could not record event ' . $type . ': ' . $e->getMessage());
        }
    }

    public static function action(?int $conversationId, string $role, string $tool, array $args, string $status, string $detail = ''): void
    {
        Database::connection()->prepare('INSERT INTO agent_actions(conversation_id,role,tool,args_json,status,detail) VALUES(:c,:r,:t,:a,:s,:d)')
            ->execute([':c' => $conversationId, ':r' => $role, ':t' => $tool, ':a' => mb_substr((string) json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 2000), ':s' => $status, ':d' => mb_substr($detail, 0, 300)]);
    }

    public static function count(string $type, string $window): int
    {
        $s = Database::connection()->prepare("SELECT COUNT(*) FROM agent_events WHERE type=:t AND created_at>=datetime('now',:w)");
        $s->execute([':t' => $type, ':w' => $window]);
        return (int) $s->fetchColumn();
    }

    /** The last AI provider errors, so a failure shows its cause in the admin. @return list<array{at:string,detail:string}> */
    public static function recentProviderErrors(int $limit = 8): array
    {
        $s = Database::connection()->prepare("SELECT created_at AS at, type || ': ' || detail AS detail FROM agent_events WHERE type IN ('provider_error','provider_empty') AND created_at >= datetime('now','-1 day') ORDER BY id DESC LIMIT " . max(1, $limit));
        $s->execute();
        return $s->fetchAll();
    }

    /** @return array<string,int> */
    public static function counters(): array
    {
        $db = Database::connection();
        return [
            'signature_failed_24h' => self::count('signature_failed', '-1 day'),
            'provider_failed_24h'  => self::count('provider_failed', '-1 day'),
            'reply_replaced_24h'   => self::count('reply_replaced', '-1 day'),
            'rate_limited_24h'     => self::count('rate_limited', '-1 day'),
            'handoffs_24h'         => (int) $db->query("SELECT COUNT(*) FROM agent_actions WHERE tool='handoff' AND status='ok' AND created_at>=datetime('now','-1 day')")->fetchColumn(),
            'owner_tools_24h'      => (int) $db->query("SELECT COUNT(*) FROM agent_actions WHERE role='owner' AND tool IN ('sales_summary','list_orders','list_bookings','create_invoice') AND created_at>=datetime('now','-1 day')")->fetchColumn(),
            'kafui_orders_today'   => (int) $db->query("SELECT COUNT(*) FROM orders WHERE source LIKE 'kafui%' AND created_at>=date('now')")->fetchColumn(),
            'needs_attention'      => (int) $db->query('SELECT COUNT(*) FROM conversations WHERE needs_attention=1')->fetchColumn(),
            'unread'               => (int) $db->query("SELECT COALESCE(SUM(unread),0) FROM conversations WHERE channel!='test'")->fetchColumn(),
        ];
    }

    private static function checkThresholds(string $type): void
    {
        if ($type === 'signature_failed' && self::count('signature_failed', '-1 hour') > 10) {
            self::alertOnce('signature_failed', '-1 hour', 'Kafui: many rejected WhatsApp webhook requests',
                'More than 10 webhook requests with a bad signature reached the site in the last hour. Someone may be probing the endpoint, or the app secret in the Kafui settings no longer matches Meta. See SECURITY.md, incident steps.');
        }
        if ($type === 'provider_failed' && self::count('provider_failed', '-30 minutes') >= 3) {
            self::alertOnce('provider_failed', '-1 hour', 'Kafui cannot reach any AI provider',
                'Every AI provider failed for three messages in a row. Customers are getting the "a person will reply" message and their chats are flagged in the Kafui inbox. Check the provider keys, models and spend limits.');
        }
        if ($type === 'reply_replaced' && self::count('reply_replaced', '-1 day') > 5) {
            self::alertOnce('reply_replaced', '-1 day', 'Kafui replaced several replies with wrong prices',
                'More than 5 replies today mentioned an amount that is not on the price list, and were replaced before sending. Check the agent log and the chosen models.');
        }
    }

    private static function alertOnce(string $key, string $window, string $subject, string $text): void
    {
        if (self::count('alert_sent:' . $key, $window) > 0) return;
        Database::connection()->prepare('INSERT INTO agent_events(type,detail) VALUES(:t,:d)')->execute([':t' => 'alert_sent:' . $key, ':d' => $subject]);
        KafuiNotify::alert($subject, $text);
    }

    /** Daily clean-up: run from cron (database/kafui-cleanup.php) and occasionally on requests. */
    public static function housekeeping(): array
    {
        $db = Database::connection();
        $days = '-' . self::TRANSCRIPT_DAYS . ' days';
        $old = $db->prepare('SELECT id FROM conversations WHERE last_message_at < datetime(\'now\',:d)');
        $old->execute([':d' => $days]);
        $ids = array_map('intval', array_column($old->fetchAll(), 'id'));
        foreach ($ids as $id) {
            $db->prepare('DELETE FROM conversation_messages WHERE conversation_id=:id')->execute([':id' => $id]);
            $db->prepare('DELETE FROM agent_quotes WHERE conversation_id=:id')->execute([':id' => $id]);
            $db->prepare('UPDATE bookings SET conversation_id=NULL WHERE conversation_id=:id')->execute([':id' => $id]);
            $db->prepare('DELETE FROM conversations WHERE id=:id')->execute([':id' => $id]);
        }
        $db->prepare("DELETE FROM agent_actions WHERE created_at < datetime('now',:d)")->execute([':d' => $days]);
        $db->prepare("DELETE FROM agent_events WHERE created_at < datetime('now',:d)")->execute([':d' => $days]);
        $db->exec("DELETE FROM agent_rate_events WHERE created_at < datetime('now','-2 days')");
        $db->exec("DELETE FROM agent_quotes WHERE created_at < datetime('now','-7 days') AND order_id IS NULL");

        $inv = $db->prepare('SELECT id FROM agent_invoices WHERE created_at < datetime(\'now\',:d)');
        $inv->execute([':d' => '-' . self::INVOICE_DAYS . ' days']);
        $files = 0;
        foreach (array_column($inv->fetchAll(), 'id') as $id) {
            if (preg_match('/^[a-f0-9]{32}$/', (string) $id)) @unlink(Invoices::dir() . '/' . $id . '.pdf');
            $db->prepare('DELETE FROM agent_invoices WHERE id=:id')->execute([':id' => $id]);
            $files++;
        }

        // MON-005: agent orders today against the 7-day average.
        $today = (int) $db->query("SELECT COUNT(*) FROM orders WHERE source LIKE 'kafui%' AND created_at>=date('now')")->fetchColumn();
        $week = (int) $db->query("SELECT COUNT(*) FROM orders WHERE source LIKE 'kafui%' AND created_at>=date('now','-7 days') AND created_at<date('now')")->fetchColumn();
        if ($today > 3 && $today > 3 * ($week / 7)) {
            self::alertOnce('orders_spike', '-1 day', 'Unusually many Kafui orders today', "Kafui has taken $today orders today, more than three times the daily average of the last week. Check them in the admin for fake orders.");
        }
        $errors = class_exists('ErrorLog', false) ? ErrorLog::prune() : 0;
        return ['conversations_deleted' => count($ids), 'invoices_deleted' => $files, 'error_log_deleted' => $errors];
    }

    /** Sliding-window limit. Returns true when the call is allowed (and records it). */
    public static function allow(string $key, int $max, int $minutes): bool
    {
        $db = Database::connection();
        $s = $db->prepare("SELECT COUNT(*) FROM agent_rate_events WHERE rate_key=:k AND created_at>=datetime('now',:m)");
        $s->execute([':k' => $key, ':m' => '-' . $minutes . ' minutes']);
        if ((int) $s->fetchColumn() >= $max) return false;
        $db->prepare('INSERT INTO agent_rate_events(rate_key) VALUES(:k)')->execute([':k' => $key]);
        return true;
    }
}
