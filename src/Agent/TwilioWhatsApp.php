<?php

declare(strict_types=1);

/**
 * Twilio WhatsApp sender for Kafui, ported from Lisa's TwilioClient
 * (prince-web-app). Twilio holds the Meta connection: the number is
 * registered as a Twilio WhatsApp sender, inbound messages arrive signed
 * with X-Twilio-Signature, and replies go out through Twilio's REST API.
 */
final class TwilioWhatsApp
{
    private const API_BASE = 'https://api.twilio.com/2010-04-01';
    /** Twilio caps one WhatsApp body at 1,600 characters; longer replies go out in parts. */
    private const MAX_BODY = 1500;

    public static function isConfigured(): bool
    {
        return trim(AgentSettings::get('twilio_account_sid')) !== ''
            && AgentSettings::hasSecret('twilio_auth_token')
            && self::senderDigits() !== '';
    }

    /** The WhatsApp sender number, digits only with country code. */
    public static function senderDigits(): string
    {
        return KafuiText::digits(AgentSettings::get('twilio_whatsapp_number'));
    }

    /** The exact URL Twilio signs: the address saved as the sender's incoming-message webhook. */
    public static function webhookUrl(): string
    {
        $base = AgentSettings::siteUrl();
        if ($base === '') {
            $base = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        return $base . '/api/v1/whatsapp/twilio';
    }

    /**
     * X-Twilio-Signature: base64 HMAC-SHA1, keyed on the auth token, of the
     * webhook URL followed by every POST parameter as key then value, sorted by key.
     * @param array<string,mixed> $params the raw POST fields
     */
    public static function verifySignature(string $url, array $params, string $signature, string $authToken): bool
    {
        $signature = trim($signature);
        if ($authToken === '' || $signature === '') return false;
        ksort($params, SORT_STRING);
        $data = $url;
        foreach ($params as $key => $value) {
            if (is_array($value) || is_object($value)) continue;
            $data .= $key . (string) $value;
        }
        return hash_equals(base64_encode(hash_hmac('sha1', $data, $authToken, true)), $signature);
    }

    /** @return array{ok:bool,id:?string,error:?string} */
    public static function sendText(string $to, string $text): array
    {
        $text = WhatsAppCloud::clean($text);
        if ($text === '') return ['ok' => false, 'id' => null, 'error' => 'Empty message.'];
        $last = ['ok' => false, 'id' => null, 'error' => 'Nothing sent.'];
        foreach (self::parts($text) as $part) {
            $last = self::create($to, ['Body' => $part]);
            if (!$last['ok']) return $last;
        }
        return $last;
    }

    /** Sends a PDF Twilio fetches from a public HTTPS link. */
    public static function sendDocument(string $to, string $link, string $filename, string $caption): array
    {
        if (!preg_match('#^https://#i', $link)) return ['ok' => false, 'id' => null, 'error' => 'The document link must be HTTPS (set the live site address in Kafui settings).'];
        return self::create($to, ['MediaUrl' => $link, 'Body' => mb_substr(WhatsAppCloud::clean($caption), 0, self::MAX_BODY)]);
    }

    /** Splits on blank lines, then lines, then words, so every part stays under the limit. @return list<string> */
    public static function parts(string $text): array
    {
        if (mb_strlen($text) <= self::MAX_BODY) return [$text];
        $parts = [];
        $current = '';
        foreach (preg_split('/(\n\n|\n| )/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $piece) {
            if (mb_strlen($current . $piece) > self::MAX_BODY && trim($current) !== '') {
                $parts[] = trim($current);
                $current = '';
            }
            $current .= $piece;
            while (mb_strlen($current) > self::MAX_BODY) {
                $parts[] = mb_substr($current, 0, self::MAX_BODY);
                $current = mb_substr($current, self::MAX_BODY);
            }
        }
        if (trim($current) !== '') $parts[] = trim($current);
        return $parts;
    }

    /** @return array{ok:bool,id:?string,error:?string} */
    private static function create(string $to, array $fields): array
    {
        // An exact address from Twilio (whatsapp:+..., possibly a WhatsApp user ID) is used as it is.
        if (preg_match('/^whatsapp:\+?[0-9A-Za-z._-]{5,40}$/', $to)) {
            $address = $to;
        } else {
            $digits = KafuiText::digits($to);
            if (!preg_match('/^[1-9]\d{7,19}$/', $digits)) return ['ok' => false, 'id' => null, 'error' => 'Invalid recipient number.'];
            $address = 'whatsapp:+' . $digits;
        }
        $to = KafuiText::digits($address);
        if (!self::isConfigured()) return ['ok' => false, 'id' => null, 'error' => 'Twilio is not connected in the Kafui settings.'];
        $sid = preg_replace('/[^A-Za-z0-9]/', '', AgentSettings::get('twilio_account_sid')) ?? '';
        $fields = array_merge(['To' => $address, 'From' => 'whatsapp:+' . self::senderDigits()], $fields);
        if (($fields['Body'] ?? null) === '') unset($fields['Body']);
        $res = AgentHttp::request('POST', self::API_BASE . '/Accounts/' . $sid . '/Messages.json', [
            'Authorization: Basic ' . base64_encode($sid . ':' . AgentSettings::secret('twilio_auth_token')),
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query($fields), 20);
        $data = json_decode($res['body'], true);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            $msg = is_array($data) ? trim((isset($data['code']) ? '[' . $data['code'] . '] ' : '') . (string) ($data['message'] ?? '')) : '';
            $error = 'HTTP ' . $res['status'] . ($res['error'] !== '' ? ' ' . $res['error'] : '') . ($msg !== '' ? ' ' . mb_substr($msg, 0, 200) : '');
            error_log('[kafui] Twilio send to ...' . substr($to, -4) . ' failed: ' . $error);
            return ['ok' => false, 'id' => null, 'error' => $error];
        }
        return ['ok' => true, 'id' => is_array($data) && isset($data['sid']) ? (string) $data['sid'] : null, 'error' => null];
    }
}
