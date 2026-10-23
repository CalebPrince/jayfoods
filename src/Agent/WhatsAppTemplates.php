<?php

declare(strict_types=1);

/**
 * WhatsApp message templates for Jay foods, as in prince-web-app's
 * WhatsAppContentTemplateManager: each template is created in Twilio's
 * Content API, submitted to WhatsApp (Meta) for approval, and its approval
 * status is checked from the admin (Kafui, Templates).
 *
 * WhatsApp only lets a business send free text within 24 hours of the
 * customer's last message. Outside that window only an approved template
 * goes through, so these cover what Jay foods needs to send later: owner
 * alerts, order and payment updates, invoices, pickups, bookings and
 * re-order reminders.
 *
 * Template rules Meta enforces: a body may not start or end with a
 * variable, variables are numbered {{1}}, {{2}} ..., and a link must be a
 * button whose URL is fixed apart from a {{n}} at the end.
 */
final class WhatsAppTemplates
{
    private const CONTENT_API = 'https://content.twilio.com/v1/Content';

    /**
     * @return array<string, array{name:string,category:string,title:string,use:string,body:string,vars:array<string,string>,samples:array<string,string>,button?:array{text:string,path:string}}>
     */
    public static function definitions(): array
    {
        return [
            'owner_alert' => [
                'name' => 'jf_owner_alert', 'category' => 'UTILITY',
                'title' => 'Owner alert',
                'use' => 'Sent by Kafui to the owner numbers when it hands a chat over, so Ellen and Rufus are told on their own WhatsApp.',
                'body' => "Kafui update for Jay foods: {{1}}. Customer: {{2}}. Details: {{3}} Open the Kafui inbox in the admin to reply.",
                'vars' => ['1' => 'What happened', '2' => 'Customer', '3' => 'Details'],
                'samples' => ['1' => 'A customer asked for a person', '2' => 'Ama, +233 20 000 0000', '3' => 'Wants 150 bottles of orange juice for a church event on Saturday.'],
                'button' => ['text' => 'Open the inbox', 'path' => '/admin/index.html'],
            ],
            'order_confirmed' => [
                'name' => 'jf_order_confirmed', 'category' => 'UTILITY',
                'title' => 'Order confirmed',
                'use' => 'Sent automatically when staff confirm a Kafui WhatsApp order and the customer\'s 24 hours have passed. Staff can also send it from a chat.',
                'body' => "Hi {{1}}, Jay foods has confirmed your order {{2}}. Total: {{3}}. Delivery date: {{4}}. Reply here if anything needs changing.",
                'vars' => ['1' => 'Customer name', '2' => 'Order reference', '3' => 'Total', '4' => 'Delivery date'],
                'samples' => ['1' => 'Ama', '2' => 'JF-20261024-A1B2C3', '3' => 'GHS 50.00', '4' => 'Friday 24 October'],
            ],
            'payment_reminder' => [
                'name' => 'jf_payment_reminder', 'category' => 'UTILITY',
                'title' => 'Payment reminder',
                'use' => 'Staff send it from a chat when an order is waiting for payment.',
                'body' => "Hi {{1}}, your Jay foods order {{2}} for {{3}} is waiting for payment. Please pay by MoMo to {{4}}, then reply here so we can start on it.",
                'vars' => ['1' => 'Customer name', '2' => 'Order reference', '3' => 'Amount', '4' => 'MoMo number and name'],
                'samples' => ['1' => 'Ama', '2' => 'JF-20261024-A1B2C3', '3' => 'GHS 50.00', '4' => '0592813813 (Ellen Ayertey)'],
            ],
            'payment_received' => [
                'name' => 'jf_payment_received', 'category' => 'UTILITY',
                'title' => 'Payment received',
                'use' => 'Staff send it from a chat once a MoMo payment has come in.',
                'body' => "Hi {{1}}, thank you. Jay foods has received your payment of {{2}} for order {{3}}. We will be in touch about delivery.",
                'vars' => ['1' => 'Customer name', '2' => 'Amount', '3' => 'Order reference'],
                'samples' => ['1' => 'Ama', '2' => 'GHS 50.00', '3' => 'JF-20261024-A1B2C3'],
            ],
            'out_for_delivery' => [
                'name' => 'jf_out_for_delivery', 'category' => 'UTILITY',
                'title' => 'Out for delivery',
                'use' => 'Staff send it from a chat when the rider leaves.',
                'body' => "Hi {{1}}, your Jay foods order {{2}} is on its way with an {{3}} rider. Please have the rider's fee ready. Thank you for ordering with us.",
                'vars' => ['1' => 'Customer name', '2' => 'Order reference', '3' => 'Uber or Yango'],
                'samples' => ['1' => 'Ama', '2' => 'JF-20261024-A1B2C3', '3' => 'Uber'],
            ],
            'pickup_ready' => [
                'name' => 'jf_pickup_ready', 'category' => 'UTILITY',
                'title' => 'Ready for pickup',
                'use' => 'Staff send it from a chat when a pickup order is ready.',
                'body' => "Hi {{1}}, your Jay foods order {{2}} is ready for pickup on {{3}} at {{4}}. See you then.",
                'vars' => ['1' => 'Customer name', '2' => 'Order reference', '3' => 'Day', '4' => 'Place'],
                'samples' => ['1' => 'Ama', '2' => 'JF-20261024-A1B2C3', '3' => 'Friday', '4' => '268/6 Teshie Nungua Estates, Accra'],
            ],
            'invoice_ready' => [
                'name' => 'jf_invoice_ready', 'category' => 'UTILITY',
                'title' => 'Invoice ready',
                'use' => 'Staff send it from a chat to share an invoice after the 24 hours have passed. The button opens the invoice PDF.',
                'body' => "Hi {{1}}, your Jay foods invoice for order {{2}} is ready. Tap the button below to view it.",
                'vars' => ['1' => 'Customer name', '2' => 'Order reference', '3' => 'Invoice link code'],
                'samples' => ['1' => 'Ama', '2' => 'JF-20261024-A1B2C3', '3' => 'sample-invoice-code'],
                'button' => ['text' => 'View invoice', 'path' => '/api/v1/invoices/{{3}}'],
            ],
            'booking_confirmed' => [
                'name' => 'jf_booking_confirmed', 'category' => 'UTILITY',
                'title' => 'Booking confirmed',
                'use' => 'Staff send it from a chat once an event or bulk booking is agreed.',
                'body' => "Hi {{1}}, your Jay foods booking {{2}} for {{3}} is confirmed. We will message you here before the day with the final details.",
                'vars' => ['1' => 'Customer name', '2' => 'Booking reference', '3' => 'Date'],
                'samples' => ['1' => 'Ama', '2' => 'JB-20261024-9F8E7D', '3' => 'Saturday 14 November'],
            ],
            'bulk_quote' => [
                'name' => 'jf_bulk_quote', 'category' => 'UTILITY',
                'title' => 'Bulk quote',
                'use' => 'Ellen sends it from a chat with her quote for 100 bottles or more.',
                'body' => "Hi {{1}}, here is your Jay foods bulk quote: {{2}}, total {{3}}. To confirm, please pay the 50% deposit of {{4}} by MoMo and reply here.",
                'vars' => ['1' => 'Customer name', '2' => 'Items', '3' => 'Total', '4' => 'Deposit'],
                'samples' => ['1' => 'Ama', '2' => '120 bottles of Orange 350ml', '3' => 'GHS 1,200.00', '4' => 'GHS 600.00'],
            ],
            'feedback_request' => [
                'name' => 'jf_feedback_request', 'category' => 'MARKETING',
                'title' => 'Feedback request',
                'use' => 'Staff send it from a chat a day after delivery.',
                'body' => "Hi {{1}}, we hope you enjoyed your Jay foods order {{2}}. How was the juice? Reply here and tell us, we read every message.",
                'vars' => ['1' => 'Customer name', '2' => 'Order reference'],
                'samples' => ['1' => 'Ama', '2' => 'JF-20261024-A1B2C3'],
            ],
            'reorder_reminder' => [
                'name' => 'jf_reorder_reminder', 'category' => 'MARKETING',
                'title' => 'Re-order reminder',
                'use' => 'Staff send it from a chat to regular customers before a production day. Only to customers who agreed to hear from Jay foods.',
                'body' => "Hi {{1}}, fresh juice is being made this {{2}}. Would you like your usual order of {{3}}? Reply here and Kafui will take it.",
                'vars' => ['1' => 'Customer name', '2' => 'Day', '3' => 'Usual order'],
                'samples' => ['1' => 'Ama', '2' => 'Thursday', '3' => '12 bottles of Pineapple Ginger'],
            ],
            'new_flavour' => [
                'name' => 'jf_new_flavour', 'category' => 'MARKETING',
                'title' => 'Flavour back in stock',
                'use' => 'Staff send it from a chat when a seasonal juice (for example mango) is available again. Only to customers who agreed to hear from Jay foods.',
                'body' => "Hi {{1}}, {{2}} juice is now available at Jay foods. Reply here to order yours for next-day delivery.",
                'vars' => ['1' => 'Customer name', '2' => 'Flavour'],
                'samples' => ['1' => 'Ama', '2' => 'Mango'],
            ],
        ];
    }

    /** Everything the admin needs for the Templates tab. @return list<array<string,mixed>> */
    public static function all(): array
    {
        $rows = [];
        foreach (Database::connection()->query('SELECT * FROM agent_templates')->fetchAll() as $r) $rows[$r['template_key']] = $r;
        $out = [];
        foreach (self::definitions() as $key => $d) {
            $r = $rows[$key] ?? null;
            $sid = (string) ($r['content_sid'] ?? '');
            $out[] = [
                'key' => $key, 'name' => $d['name'], 'title' => $d['title'], 'category' => $d['category'], 'use' => $d['use'],
                'body' => $d['body'], 'vars' => $d['vars'], 'button' => isset($d['button']) ? ['text' => $d['button']['text'], 'url' => self::buttonUrl($d)] : null,
                'content_sid' => $sid !== '' ? $sid : null,
                'status' => $sid === '' ? 'not_created' : ((string) ($r['status'] ?? '') ?: 'pending'),
                'rejection_reason' => ($r['rejection_reason'] ?? '') !== '' ? $r['rejection_reason'] : null,
                'checked_at' => $r['updated_at'] ?? null,
            ];
        }
        return $out;
    }

    public static function status(string $key): string
    {
        $s = Database::connection()->prepare('SELECT content_sid,status FROM agent_templates WHERE template_key=:k');
        $s->execute([':k' => $key]);
        $r = $s->fetch();
        return $r && $r['content_sid'] !== '' ? ((string) $r['status'] ?: 'pending') : 'not_created';
    }

    public static function isApproved(string $key): bool { return self::status($key) === 'approved'; }

    /** Create the content in Twilio and submit it to WhatsApp for approval. @return array<string,mixed> */
    public static function submit(string $key): array
    {
        $d = self::definition($key);
        $current = self::row($key);
        if ($current && $current['content_sid'] !== '') {
            if (self::exists($current['content_sid'])) throw new RuntimeException('This template is already in Twilio. Check its status instead, or delete it to rebuild it.');
            self::save($key, '', '', '');
        }
        if (isset($d['button']) && AgentSettings::siteUrl() === '') throw new RuntimeException('Set the Live site address in Kafui, Settings first: the button links to it.');

        $types = isset($d['button'])
            ? ['twilio/call-to-action' => ['body' => $d['body'], 'actions' => [['type' => 'URL', 'title' => $d['button']['text'], 'url' => self::buttonUrl($d)]]]]
            : ['twilio/text' => ['body' => $d['body']]];
        $created = self::api('POST', self::CONTENT_API, ['friendly_name' => $d['name'], 'language' => 'en', 'variables' => $d['samples'], 'types' => $types]);
        $sid = (string) ($created['sid'] ?? '');
        if (!preg_match('/^HX[0-9a-fA-F]{32}$/', $sid)) throw new RuntimeException((string) ($created['message'] ?? 'Twilio did not create the template.'));
        // Keep the SID before asking for approval, so a failed approval call can be retried.
        self::save($key, $sid, 'pending', '');
        $approval = self::api('POST', self::CONTENT_API . '/' . $sid . '/ApprovalRequests/whatsapp', ['name' => $d['name'], 'category' => $d['category']]);
        self::save($key, $sid, strtolower((string) ($approval['status'] ?? 'pending')), '');
        return self::one($key);
    }

    /** Ask Twilio where approval has got to. @return array<string,mixed> */
    public static function refresh(string $key): array
    {
        self::definition($key);
        $r = self::row($key);
        if (!$r || $r['content_sid'] === '') return self::one($key);
        try {
            $res = self::api('GET', self::CONTENT_API . '/' . $r['content_sid'] . '/ApprovalRequests');
        } catch (RuntimeException $e) {
            if (!self::exists($r['content_sid'])) { self::save($key, '', '', ''); return self::one($key); }
            throw $e;
        }
        $status = self::find($res, 'status') ?: 'pending';
        self::save($key, $r['content_sid'], strtolower($status), strtolower($status) === 'rejected' ? (string) (self::find($res, 'rejection_reason') ?? '') : '');
        return self::one($key);
    }

    /** Delete the content in Twilio (for example after a rejection) so it can be rebuilt. @return array<string,mixed> */
    public static function delete(string $key): array
    {
        self::definition($key);
        $r = self::row($key);
        if ($r && $r['content_sid'] !== '') {
            $res = AgentHttp::request('DELETE', self::CONTENT_API . '/' . $r['content_sid'], self::auth(), null, 20);
            if ($res['status'] >= 300 && $res['status'] !== 404) throw new RuntimeException('Twilio refused to delete the template (HTTP ' . $res['status'] . ').');
        }
        self::save($key, '', '', '');
        return self::one($key);
    }

    /** Send an approved template to a customer. @param array<string,string> $vars keyed "1", "2", ... */
    public static function send(string $key, string $to, array $vars): array
    {
        $d = self::definition($key);
        $r = self::row($key);
        if (!$r || $r['content_sid'] === '' || $r['status'] !== 'approved') return ['ok' => false, 'id' => null, 'error' => 'The "' . $d['title'] . '" template is not approved yet.'];
        $clean = [];
        foreach (array_keys($d['vars']) as $n) {
            $v = trim(preg_replace('/\s+/u', ' ', KafuiText::noDashes((string) ($vars[$n] ?? ''))) ?? '');
            if ($v === '') return ['ok' => false, 'id' => null, 'error' => 'Fill in "' . $d['vars'][$n] . '".'];
            $clean[(string) $n] = mb_substr($v, 0, 300);
        }
        return TwilioWhatsApp::sendTemplate($to, $r['content_sid'], $clean);
    }

    /** The message as the customer reads it, for the chat history. */
    public static function render(string $key, array $vars): string
    {
        $d = self::definition($key);
        $body = $d['body'];
        foreach ($vars as $n => $v) $body = str_replace('{{' . $n . '}}', (string) $v, $body);
        return $body;
    }

    // ------------------------------------------------------------------ helpers

    private static function definition(string $key): array
    {
        $all = self::definitions();
        if (!isset($all[$key])) throw new RuntimeException('Unknown template.');
        return $all[$key];
    }

    private static function buttonUrl(array $d): string
    {
        return AgentSettings::siteUrl() . $d['button']['path'];
    }

    private static function one(string $key): array
    {
        foreach (self::all() as $t) if ($t['key'] === $key) return $t;
        throw new RuntimeException('Unknown template.');
    }

    private static function row(string $key): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM agent_templates WHERE template_key=:k');
        $s->execute([':k' => $key]);
        return $s->fetch() ?: null;
    }

    private static function save(string $key, string $sid, string $status, string $reason): void
    {
        Database::connection()->prepare("INSERT INTO agent_templates(template_key,content_sid,status,rejection_reason,updated_at) VALUES(:k,:s,:st,:r,datetime('now'))
            ON CONFLICT(template_key) DO UPDATE SET content_sid=excluded.content_sid,status=excluded.status,rejection_reason=excluded.rejection_reason,updated_at=datetime('now')")
            ->execute([':k' => $key, ':s' => $sid, ':st' => $status, ':r' => mb_substr($reason, 0, 500)]);
    }

    private static function exists(string $sid): bool
    {
        try { self::api('GET', self::CONTENT_API . '/' . $sid); return true; } catch (RuntimeException $e) { return false; }
    }

    /** Twilio nests the WhatsApp verdict differently by response; walk it rather than assume one shape. */
    private static function find(array $res, string $field): ?string
    {
        if (isset($res['whatsapp'][$field]) && is_scalar($res['whatsapp'][$field])) return (string) $res['whatsapp'][$field];
        if (isset($res[$field]) && is_scalar($res[$field]) && (string) $res[$field] !== '') return (string) $res[$field];
        foreach ($res as $v) if (is_array($v) && ($found = self::find($v, $field)) !== null) return $found;
        return null;
    }

    /** @return list<string> */
    private static function auth(): array
    {
        $sid = trim(AgentSettings::get('twilio_account_sid'));
        $token = AgentSettings::secret('twilio_auth_token');
        if (!preg_match('/^AC[0-9a-fA-F]{32}$/', $sid) || $token === '') throw new RuntimeException('Save the Twilio Account SID and auth token in Kafui, Settings first.');
        return ['Authorization: Basic ' . base64_encode($sid . ':' . $token), 'Accept: application/json'];
    }

    private static function api(string $method, string $url, ?array $payload = null): array
    {
        $headers = self::auth();
        if ($payload !== null) $headers[] = 'Content-Type: application/json';
        $res = AgentHttp::request($method, $url, $headers, $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, 30);
        $data = json_decode($res['body'], true);
        if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($data)) {
            error_log('[kafui] Twilio Content API ' . $method . ' ' . $url . ' failed: HTTP ' . $res['status'] . ' ' . mb_substr($res['body'], 0, 300));
            throw new RuntimeException(is_array($data) && isset($data['message']) ? (string) $data['message'] : 'Twilio did not accept the request (HTTP ' . $res['status'] . ').');
        }
        return $data;
    }
}
