<?php

declare(strict_types=1);

/**
 * Meta WhatsApp Cloud API: webhook signatures in, messages out. This is the
 * only provider-specific class, so another provider (Twilio) could replace it.
 */
final class WhatsAppCloud
{
    /** X-Hub-Signature-256 is "sha256=" + HMAC-SHA256 of the raw body with the app secret. */
    public static function verifySignature(string $rawBody, string $header, string $appSecret): bool
    {
        if ($appSecret === '' || !str_starts_with($header, 'sha256=')) return false;
        return hash_equals(hash_hmac('sha256', $rawBody, $appSecret), substr($header, 7));
    }

    public static function isConfigured(): bool
    {
        return AgentSettings::get('wa_phone_number_id') !== ''
            && AgentSettings::get('wa_graph_version') !== ''
            && AgentSettings::hasSecret('wa_access_token');
    }

    /** @return array{ok:bool,id:?string,error:?string} */
    public static function sendText(string $to, string $text): array
    {
        $text = self::clean($text);
        if ($text === '') return ['ok' => false, 'id' => null, 'error' => 'Empty message.'];
        return self::send($to, ['type' => 'text', 'text' => ['preview_url' => false, 'body' => mb_substr($text, 0, 4000)]]);
    }

    /** Sends a PDF Meta fetches from a public HTTPS link. */
    public static function sendDocument(string $to, string $link, string $filename, string $caption): array
    {
        return self::send($to, ['type' => 'document', 'document' => ['link' => $link, 'filename' => $filename, 'caption' => mb_substr(self::clean($caption), 0, 900)]]);
    }

    private static function send(string $to, array $message): array
    {
        $to = preg_replace('/\D+/', '', $to) ?? '';
        if (!preg_match('/^[1-9]\d{7,14}$/', $to)) return ['ok' => false, 'id' => null, 'error' => 'Invalid recipient number.'];
        if (!self::isConfigured()) return ['ok' => false, 'id' => null, 'error' => 'WhatsApp is not connected in the Kafui settings.'];
        $version = preg_replace('/[^v0-9.]/', '', AgentSettings::get('wa_graph_version')) ?? '';
        $phoneId = preg_replace('/\D+/', '', AgentSettings::get('wa_phone_number_id')) ?? '';
        $url = 'https://graph.facebook.com/' . $version . '/' . $phoneId . '/messages';
        $payload = array_merge(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to], $message);
        try {
            $data = AgentHttp::json('POST', $url, ['Authorization: Bearer ' . AgentSettings::secret('wa_access_token')], $payload, 20);
            return ['ok' => true, 'id' => isset($data['messages'][0]['id']) ? (string) $data['messages'][0]['id'] : null, 'error' => null];
        } catch (Throwable $e) {
            error_log('[kafui] WhatsApp send to ...' . substr($to, -4) . ' failed: ' . $e->getMessage());
            return ['ok' => false, 'id' => null, 'error' => $e->getMessage()];
        }
    }

    /** No control characters except new lines; no em dashes in customer text. */
    public static function clean(string $text): string
    {
        $text = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $text) ?? '';
        return trim(KafuiText::noDashes($text));
    }
}
