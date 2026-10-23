<?php

declare(strict_types=1);

/**
 * Twilio WhatsApp webhook for Kafui ("A message comes in" on the Twilio
 * WhatsApp sender), as Lisa's twilioWebhook in prince-web-app.
 *   POST /api/v1/whatsapp/twilio
 *
 * Twilio signs each request with X-Twilio-Signature; anything unsigned or
 * badly signed gets 403 and nothing is stored (CTL-API-001). Each MessageSid
 * is stored once, Twilio gets empty TwiML straight away, and Kafui runs after
 * the response is sent (CTL-API-002). Only active when Twilio is the chosen provider.
 */
final class TwilioWebhookController
{
    public function receive(): void
    {
        if (WhatsAppSender::provider() !== 'twilio') { self::emptyTwiml(); return; }
        $token = AgentSettings::secret('twilio_auth_token');
        if ($token === '') { http_response_code(503); return; }
        $url = TwilioWhatsApp::webhookUrl();
        if (!TwilioWhatsApp::verifySignature($url, $_POST, (string) ($_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? ''), $token)) {
            AgentMonitor::event('signature_failed', 'twilio ' . (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            error_log('[kafui] Twilio webhook rejected: signature check failed for ' . $url . ' (the URL in Twilio must match the Live site address exactly)');
            http_response_code(403);
            return;
        }

        $queued = self::store($_POST);
        WhatsAppWebhookController::finishResponse('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 'text/xml; charset=UTF-8');
        foreach ($queued as $job) WhatsAppWebhookController::answer($job['conversation'], $job['message_id'], $job['text']);
        if (random_int(1, 50) === 1) AgentMonitor::housekeeping();
    }

    /**
     * Stores one verified inbound message.
     * @param array<string,mixed> $post
     * @return list<array{conversation:array,message_id:int,text:string}>
     */
    public static function store(array $post): array
    {
        $sid = KafuiText::field($post['MessageSid'] ?? '', 64);
        $from = KafuiText::digits((string) ($post['From'] ?? ''));
        $to = KafuiText::digits((string) ($post['To'] ?? ''));
        if ($sid === '' || $from === '' || !str_starts_with((string) ($post['From'] ?? ''), 'whatsapp:')) return [];
        $ours = TwilioWhatsApp::senderDigits();
        if ($ours !== '' && $to !== $ours) return [];

        $text = KafuiText::field($post['Body'] ?? '', 1000);
        $media = (int) ($post['NumMedia'] ?? 0);
        if ($media > 0) {
            $type = (string) ($post['MediaContentType0'] ?? '');
            $what = str_starts_with($type, 'image/') ? 'a photo' : (str_starts_with($type, 'audio/') ? 'a voice note' : (str_starts_with($type, 'video/') ? 'a video' : 'a document'));
            $text = trim('[sent ' . $what . '. Kafui cannot open attachments; please ask them to type.] ' . $text);
        }
        if (isset($post['Latitude'], $post['Longitude']) && $text === '') {
            $text = '[shared a location' . (isset($post['Address']) ? ': ' . KafuiText::field($post['Address'], 160) : '') . ']';
        }
        if ($text === '') $text = '[sent a message Kafui cannot read]';

        $conv = KafuiStore::findOrCreate('whatsapp', $from, KafuiText::field($post['ProfileName'] ?? '', 80), $from);
        // Reply to exactly what Twilio sent: WhatsApp may give a user ID instead of a phone number.
        $raw = trim((string) $post['From']);
        if (preg_match('/^whatsapp:\+?[0-9A-Za-z._-]{5,40}$/', $raw) && ($conv['reply_to'] ?? '') !== $raw) {
            Database::connection()->prepare('UPDATE conversations SET reply_to=:r WHERE id=:id')->execute([':r' => $raw, ':id' => $conv['id']]);
            $conv['reply_to'] = $raw;
        }
        $messageId = KafuiStore::add((int) $conv['id'], 'customer', mb_substr($text, 0, 1000), $sid);
        if ($messageId === null) return []; // Twilio retry of a message we already have.
        return [['conversation' => $conv, 'message_id' => $messageId, 'text' => $text]];
    }

    private static function emptyTwiml(): void
    {
        header('Content-Type: text/xml; charset=UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
    }
}
