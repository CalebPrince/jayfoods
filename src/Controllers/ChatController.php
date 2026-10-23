<?php

declare(strict_types=1);

/**
 * Website live chat with Kafui, the same agent as WhatsApp.
 *   POST /api/v1/chat/session   start a signed, anonymous 24-hour session
 *   POST /api/v1/chat/message   send a message, get Kafui's reply
 *   GET  /api/v1/chat/history   messages after an id (staff replies arrive this way)
 *
 * Visitors are anonymous, so they only ever get customer tools (CTL-AUTHZ-002).
 */
final class ChatController
{
    private const SESSION_HOURS = 24;

    public function session(): void
    {
        if (!AgentSettings::enabled()) { Response::json(['error' => 'Kafui is resting right now.', 'whatsapp' => self::whatsappNumber()], 503); return; }
        if (!AgentMonitor::allow('chat-session:' . self::ip(), 10, 10)) { Response::json(['error' => 'Too many new chats. Try again in a few minutes.'], 429); return; }
        $sid = bin2hex(random_bytes(16));
        KafuiStore::findOrCreate('web', $sid);
        Response::json(['data' => [
            'session' => Signer::sign('chat', $sid, time() + self::SESSION_HOURS * 3600),
            'greeting' => "Hi, I'm Kafui, Jay foods' automatic assistant. I can help you order fresh juice, book juice for an event or check an order. You can ask for a person any time.",
            'whatsapp' => self::whatsappNumber(),
        ]]);
    }

    public function message(): void
    {
        $raw = (string) file_get_contents('php://input', false, null, 0, 4097);
        if (strlen($raw) > 4096) { Response::json(['error' => 'That message is too long.'], 413); return; }
        $in = json_decode($raw, true);
        $conv = self::conversation(is_array($in) ? (string) ($in['session'] ?? '') : '');
        if ($conv === null) { Response::json(['error' => 'Your chat has expired. Start a new one.'], 401); return; }
        $text = KafuiText::field(is_array($in) ? ($in['text'] ?? '') : '', 1001);
        if ($text === '') { Response::json(['error' => 'Type a message first.'], 422); return; }
        if (mb_strlen($text) > 1000) { Response::json(['error' => 'Please keep messages under 1,000 characters.'], 422); return; }
        if (!AgentSettings::enabled() && $conv['mode'] !== 'staff') { Response::json(['error' => 'Kafui is resting right now.', 'whatsapp' => self::whatsappNumber()], 503); return; }
        if (!AgentMonitor::allow('chat-ip:' . self::ip(), 60, 10)) { Response::json(['error' => 'Too many messages. Try again in a few minutes.'], 429); return; }

        @set_time_limit(Kafui::BUDGET_SECONDS + 30);
        $messageId = KafuiStore::add((int) $conv['id'], 'customer', $text);
        $result = ['reply' => null, 'handed_off' => false];
        try {
            $result = Kafui::respond($conv, (int) $messageId, $text);
        } catch (Throwable $e) {
            error_log('[kafui] web respond failed: ' . $e->getMessage());
            KafuiStore::setMode((int) $conv['id'], 'staff', true);
            $result = ['reply' => Kafui::FAIL_TEXT, 'handed_off' => true];
        }
        if ($result['reply'] !== null) KafuiStore::add((int) $conv['id'], $result['sender'] ?? 'kafui', $result['reply']);
        if (random_int(1, 50) === 1) AgentMonitor::housekeeping();
        $fresh = KafuiStore::get((int) $conv['id']);
        Response::json(['data' => ['messages' => self::messagesAfter((int) $conv['id'], (int) $messageId), 'mode' => $fresh['mode'] ?? 'kafui']]);
    }

    public function history(): void
    {
        $conv = self::conversation((string) ($_GET['session'] ?? ''));
        if ($conv === null) { Response::json(['error' => 'Your chat has expired. Start a new one.'], 401); return; }
        if (!AgentMonitor::allow('chat-poll:' . self::ip(), 240, 10)) { Response::json(['error' => 'Slow down.'], 429); return; }
        Response::json(['data' => ['messages' => self::messagesAfter((int) $conv['id'], max(0, (int) ($_GET['after'] ?? 0))), 'mode' => $conv['mode']]]);
    }

    private static function conversation(string $token): ?array
    {
        $sid = Signer::verify('chat', $token);
        if ($sid === null || !preg_match('/^[a-f0-9]{32}$/', $sid)) return null;
        $conv = KafuiStore::byKey('web', $sid);
        return $conv ?: null;
    }

    /** Messages a visitor may see: theirs, Kafui's and staff replies (staff names are not shown). */
    private static function messagesAfter(int $conversationId, int $afterId): array
    {
        $s = Database::connection()->prepare("SELECT id,sender,body,created_at FROM conversation_messages WHERE conversation_id=:c AND id>:a AND sender IN ('customer','kafui','staff','system') ORDER BY id LIMIT 100");
        $s->execute([':c' => $conversationId, ':a' => $afterId]);
        return array_map(static fn($m) => ['id' => (int) $m['id'], 'from' => $m['sender'] === 'system' ? 'kafui' : $m['sender'], 'text' => $m['body'], 'at' => $m['created_at']], $s->fetchAll());
    }

    private static function whatsappNumber(): string
    {
        $v = Database::connection()->query("SELECT value FROM site_content WHERE content_key='whatsapp_number'")->fetchColumn();
        return KafuiText::digits($v !== false ? (string) $v : '233246328461');
    }

    private static function ip(): string { return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64); }
}
