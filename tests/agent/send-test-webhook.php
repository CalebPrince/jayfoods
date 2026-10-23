<?php

declare(strict_types=1);

/**
 * Local test path: posts a WhatsApp message to a running JayFoods site,
 * signed exactly as Meta signs it, so the whole webhook path can be tried
 * without a phone.
 *
 *   php tests/agent/send-test-webhook.php --url=http://localhost:8010 --secret=APP_SECRET --from=233200000002 --text="Hi, how much is orange juice?" [--phone-id=PHONE_NUMBER_ID] [--bad-signature]
 *
 * --secret is the Meta app secret saved in the admin (Kafui, Settings).
 * --phone-id must match the saved phone number ID when one is saved.
 * Kafui's reply goes out through the Graph API, so with a real token it is
 * delivered to --from. Use your own number, or watch the Kafui inbox.
 */

$opts = getopt('', ['url:', 'secret:', 'from:', 'text:', 'phone-id::', 'bad-signature']);
foreach (['url', 'secret', 'from', 'text'] as $k) {
    if (empty($opts[$k])) { fwrite(STDERR, "Missing --$k. See the comment at the top of this file.\n"); exit(2); }
}
$payload = json_encode([
    'object' => 'whatsapp_business_account',
    'entry' => [[
        'id' => 'TEST',
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => 'TEST', 'phone_number_id' => (string) ($opts['phone-id'] ?? '')],
                'contacts' => [['profile' => ['name' => 'Local test'], 'wa_id' => $opts['from']]],
                'messages' => [[
                    'from' => $opts['from'], 'id' => 'wamid.LOCALTEST.' . bin2hex(random_bytes(8)), 'timestamp' => (string) time(),
                    'type' => 'text', 'text' => ['body' => $opts['text']],
                ]],
            ],
        ]],
    ]],
], JSON_UNESCAPED_SLASHES);

$signature = 'sha256=' . hash_hmac('sha256', $payload, isset($opts['bad-signature']) ? 'wrong-secret' : $opts['secret']);
$ch = curl_init(rtrim($opts['url'], '/') . '/api/v1/whatsapp/webhook');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . $signature],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
]);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP $status " . (is_string($body) ? $body : curl_error($ch)) . "\n";
echo $status === 200 ? "Accepted. Open the admin, Kafui, Inbox to see the message and Kafui's reply.\n" : "Rejected (expected for --bad-signature).\n";
