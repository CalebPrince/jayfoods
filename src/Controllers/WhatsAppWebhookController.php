<?php

declare(strict_types=1);

/**
 * Meta WhatsApp Cloud API webhook for Kafui.
 *   GET  /api/v1/whatsapp/webhook  verify()   Meta's subscribe handshake
 *   POST /api/v1/whatsapp/webhook  receive()  signed message events
 *
 * Unsigned or badly signed requests get 401 and nothing is stored
 * (CTL-API-001). Each message id is stored once, Meta gets its 200 straight
 * away, and the agent runs after the response is sent (CTL-API-002).
 */
final class WhatsAppWebhookController
{
    private const MAX_BODY = 262144;

    public function verify(): void
    {
        if (WhatsAppSender::provider() !== 'meta') { http_response_code(403); return; }
        $mode = (string) ($_GET['hub_mode'] ?? '');
        $token = (string) ($_GET['hub_verify_token'] ?? '');
        $challenge = (string) ($_GET['hub_challenge'] ?? '');
        $expected = AgentSettings::secret('wa_verify_token');
        if ($mode !== 'subscribe' || $expected === '' || $token === '' || !hash_equals($expected, $token) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $challenge)) {
            http_response_code(403);
            return;
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo $challenge;
    }

    public function receive(): void
    {
        // Only the chosen provider's webhook takes messages (Twilio is the default).
        if (WhatsAppSender::provider() !== 'meta') { http_response_code(200); return; }
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if (strlen($raw) > self::MAX_BODY) { http_response_code(413); return; }
        $secret = AgentSettings::secret('wa_app_secret');
        if ($secret === '') { http_response_code(503); return; }
        if (!WhatsAppCloud::verifySignature($raw, (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''), $secret)) {
            AgentMonitor::event('signature_failed', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            http_response_code(401);
            return;
        }

        $queued = self::store(json_decode($raw, true));
        self::finishResponse();
        foreach ($queued as $job) self::answer($job['conversation'], $job['message_id'], $job['text']);
        if (random_int(1, 50) === 1) AgentMonitor::housekeeping();
    }

    /**
     * Stores every new text message in a verified payload.
     * @return list<array{conversation:array,message_id:int,text:string}>
     */
    public static function store(mixed $payload): array
    {
        $queued = [];
        if (!is_array($payload) || ($payload['object'] ?? '') !== 'whatsapp_business_account') return $queued;
        $ourNumberId = AgentSettings::get('wa_phone_number_id');
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                if (($change['field'] ?? '') !== 'messages') continue;
                if ($ourNumberId !== '' && (string) ($value['metadata']['phone_number_id'] ?? '') !== $ourNumberId) continue;
                $names = [];
                foreach ((array) ($value['contacts'] ?? []) as $c) $names[(string) ($c['wa_id'] ?? '')] = (string) ($c['profile']['name'] ?? '');
                foreach ((array) ($value['messages'] ?? []) as $m) {
                    $from = KafuiText::digits((string) ($m['from'] ?? ''));
                    $id = KafuiText::field($m['id'] ?? '', 200);
                    if ($from === '' || $id === '') continue;
                    $text = mb_substr(self::textOf($m), 0, 1000);
                    $conv = KafuiStore::findOrCreate('whatsapp', $from, KafuiText::field($names[$from] ?? '', 80), $from);
                    $messageId = KafuiStore::add((int) $conv['id'], 'customer', $text, $id);
                    if ($messageId === null) continue; // Meta retry of a message we already have.
                    $queued[] = ['conversation' => $conv, 'message_id' => $messageId, 'text' => $text];
                }
            }
        }
        return $queued;
    }

    /** Runs Kafui for one stored message and sends the reply. */
    public static function answer(array $conversation, int $messageId, string $text): void
    {
        try {
            $result = Kafui::respond($conversation, $messageId, $text);
        } catch (Throwable $e) {
            error_log('[kafui] respond failed: ' . $e->getMessage());
            $result = ['reply' => Kafui::failText(), 'document' => null, 'handed_off' => true];
            KafuiStore::setMode((int) $conversation['id'], 'staff', true);
        }
        $to = KafuiStore::replyAddress(KafuiStore::get((int) $conversation['id']) ?? $conversation);
        if ($result['reply'] !== null) {
            $sent = WhatsAppSender::sendText($to, $result['reply']);
            if (!$sent['ok']) $sent = WhatsAppSender::sendText($to, $result['reply']);
            if ($sent['ok']) {
                KafuiStore::add((int) $conversation['id'], $result['sender'] ?? 'kafui', $result['reply']);
            } else {
                AgentMonitor::event('send_failed', KafuiText::maskPhone($to));
                KafuiStore::add((int) $conversation['id'], 'system', 'Reply could not be sent: ' . mb_substr((string) $sent['error'], 0, 160));
                KafuiStore::flag((int) $conversation['id']);
            }
        }
        if (!empty($result['document'])) {
            $doc = $result['document'];
            $sent = WhatsAppSender::sendDocument($to, $doc['link'], $doc['filename'], $doc['caption']);
            KafuiStore::add((int) $conversation['id'], $sent['ok'] ? 'kafui' : 'system', $sent['ok'] ? '[sent ' . $doc['filename'] . ']' : 'Invoice PDF could not be sent: ' . mb_substr((string) $sent['error'], 0, 160));
        }
    }

    /** What the customer sent, as text. Media is not downloaded; Kafui asks for text instead. */
    private static function textOf(array $m): string
    {
        return match ((string) ($m['type'] ?? '')) {
            'text' => KafuiText::field($m['text']['body'] ?? '', 1000),
            'button' => KafuiText::field($m['button']['text'] ?? '', 200),
            'interactive' => KafuiText::field($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? '', 200),
            'location' => '[shared a location' . (isset($m['location']['name']) ? ': ' . KafuiText::field($m['location']['name'], 100) : '') . (isset($m['location']['address']) ? ', ' . KafuiText::field($m['location']['address'], 160) : '') . ']',
            'image' => '[sent a photo' . (isset($m['image']['caption']) ? ': ' . KafuiText::field($m['image']['caption'], 300) : '') . '. Kafui cannot see photos.]',
            'audio' => '[sent a voice note. Kafui cannot listen to voice notes; please ask them to type.]',
            'video' => '[sent a video. Kafui cannot watch videos.]',
            'document' => '[sent a document. Kafui cannot open documents.]',
            'sticker' => '[sent a sticker]',
            'reaction' => '[reacted ' . KafuiText::field($m['reaction']['emoji'] ?? '', 10) . ']',
            default => '[sent a message Kafui cannot read]',
        };
    }

    /** Sends the 200 to Meta or Twilio now and keeps running, so slow AI replies never cause retries. */
    public static function finishResponse(string $body = '{"ok":true}', string $type = 'application/json'): void
    {
        ignore_user_abort(true);
        @set_time_limit(Kafui::BUDGET_SECONDS * 2 + 30);
        http_response_code(200);
        header('Content-Type: ' . $type);
        if (function_exists('fastcgi_finish_request')) { echo $body; fastcgi_finish_request(); return; }
        if (function_exists('litespeed_finish_request')) { echo $body; litespeed_finish_request(); return; } // cPanel hosts on LiteSpeed
        header('Connection: close');
        header('Content-Length: ' . strlen($body));
        echo $body;
        while (ob_get_level() > 0) @ob_end_flush();
        flush();
    }
}
