<?php

declare(strict_types=1);

/** Staff and owner emails for Kafui, sent through the site's SMTP settings (cPanel mailbox or Gmail). */
final class KafuiNotify
{
    public static function order(array $order, array $lines, string $channel): void
    {
        $rows = '';
        foreach ($lines as $l) $rows .= '<tr><td>' . self::e($l['product_name']) . '</td><td align="center">' . (int) $l['quantity'] . '</td><td align="right">' . self::e(KafuiText::money((int) $l['line_total_pesewas'])) . '</td></tr>';
        self::staff('New Kafui order ' . $order['reference'] . ' to confirm',
            '<p>Kafui took an order on ' . self::e($channel) . '. It is <b>pending and unpaid</b> until you confirm it with the customer.</p>'
            . '<p><b>' . self::e($order['customer_name']) . '</b>, ' . self::e($order['customer_phone']) . '<br>'
            . self::e($order['fulfilment'] === 'pickup' ? 'Pickup' : $order['delivery_address'] . ', ' . $order['region']) . '<br>'
            . 'Wanted on: ' . self::e($order['requested_date'] ?: 'not given') . '</p>'
            . '<table style="width:100%;border-collapse:collapse">' . $rows . '</table>'
            . '<p>Delivery: ' . self::e(KafuiText::money((int) $order['delivery_fee_pesewas'])) . '<br><b>Total: ' . self::e(KafuiText::money((int) $order['total_pesewas'])) . '</b></p>'
            . ($order['notes'] !== '' ? '<p>Notes: ' . self::e($order['notes']) . '</p>' : ''));
        $items = implode(', ', array_map(static fn($l) => (int) $l['quantity'] . ' x ' . $l['product_name'], $lines));
        self::owners('New order ' . $order['reference'] . ' to confirm', $order['customer_name'] . ', +' . KafuiText::digits((string) $order['customer_phone']),
            $items . '. Total ' . KafuiText::money((int) $order['total_pesewas']) . ($order['requested_date'] ? ', for ' . $order['requested_date'] : '') . '.');
    }

    public static function booking(array $b): void
    {
        $what = $b['kind'] === 'callback' ? 'call-back request' : 'event booking';
        self::staff('New Kafui ' . $what . ' ' . $b['reference'],
            '<p>Kafui took a <b>' . $what . '</b>. Confirm or decline it in the admin under Kafui, Bookings.</p><p>'
            . self::e($b['customer_name']) . ', ' . self::e($b['customer_phone']) . '<br>'
            . ($b['event_date'] !== '' ? 'Date: ' . self::e($b['event_date'] . ' ' . $b['event_time']) . '<br>' : '')
            . ($b['venue'] !== '' ? 'Venue: ' . self::e($b['venue']) . '<br>' : '')
            . ($b['guests'] > 0 ? 'Guests: ' . (int) $b['guests'] . '<br>' : '')
            . ($b['juices'] !== '' ? 'Juices: ' . self::e($b['juices']) . '<br>' : '')
            . ($b['notes'] !== '' ? 'Notes: ' . self::e($b['notes']) : '') . '</p>');
        self::owners('New ' . $what . ' ' . $b['reference'], $b['customer_name'] . ', +' . KafuiText::digits((string) $b['customer_phone']),
            trim(($b['event_date'] !== '' ? 'Date ' . $b['event_date'] . '. ' : '') . ($b['guests'] > 0 ? (int) $b['guests'] . ' guests. ' : '') . ($b['juices'] !== '' ? $b['juices'] . '.' : '')) ?: 'See the admin.');
    }

    /** $alertOwners false: a system failure, which shows in the admin only (no WhatsApp alert). */
    public static function handoff(array $conversation, string $reason, bool $alertOwners = true): void
    {
        self::staff('A customer is waiting for a person',
            '<p>Kafui handed a ' . self::e($conversation['channel'] === 'web' ? 'website chat' : 'WhatsApp chat') . ' to staff.</p>'
            . '<p>Customer: ' . self::e($conversation['contact_name'] ?: 'not given') . ($conversation['phone'] !== '' ? ', +' . self::e($conversation['phone']) : '') . '</p>'
            . '<p>Reason: ' . self::e($reason) . '</p>'
            . '<p>Reply from the admin under Kafui. Kafui stays quiet in this chat until you hand it back.'
            . ($conversation['channel'] === 'whatsapp' ? ' WhatsApp lets you reply for 24 hours after the customer\'s last message.' : '') . '</p>');
        if (!$alertOwners || AgentSettings::isOwnerNumber((string) $conversation['phone'])) return; // system errors and an owner's own chat stay in the admin
        self::owners('A customer is waiting for a person',
            ($conversation['contact_name'] ?: 'Name not given') . ($conversation['phone'] !== '' && !KafuiStore::isWhatsAppId((string) $conversation['phone']) ? ', +' . $conversation['phone'] : '') . ($conversation['channel'] === 'web' ? ' (website chat)' : ''),
            rtrim($reason, '.') . '.');
    }

    /**
     * Tells each owner number on WhatsApp, as Lisa does: the approved owner_alert
     * template when there is one (it arrives at any time), otherwise plain text,
     * which WhatsApp only delivers within 24 hours of that owner's last message.
     * Email still goes as well, so a failed WhatsApp alert is logged, not fatal.
     */
    public static function owners(string $what, string $customer, string $details): void
    {
        if (!WhatsAppSender::isConfigured()) return;
        $vars = ['1' => mb_substr($what, 0, 120), '2' => mb_substr($customer, 0, 120), '3' => mb_substr(preg_replace('/\s+/u', ' ', $details) ?? '', 0, 400)];
        $useTemplate = WhatsAppSender::provider() === 'twilio' && WhatsAppTemplates::isApproved('owner_alert');
        foreach (AgentSettings::ownerNumbers() as $o) {
            $conv = KafuiStore::byPhone((string) $o['number']);
            $to = $conv ? KafuiStore::replyAddress($conv) : (string) $o['number'];
            try {
                $sent = $useTemplate ? WhatsAppTemplates::send('owner_alert', $to, $vars)
                    : WhatsAppSender::sendText($to, '*' . $vars['1'] . "*\nCustomer: " . $vars['2'] . "\n" . $vars['3'] . "\nOpen the Kafui inbox in the admin to reply.");
                if (!$sent['ok']) AgentMonitor::event('owner_alert_failed', KafuiText::maskPhone((string) $o['number']) . ': ' . mb_substr((string) $sent['error'], 0, 100));
            } catch (Throwable $e) {
                error_log('[kafui owner alert] ' . $e->getMessage());
            }
        }
    }

    /**
     * When staff confirm a Kafui WhatsApp order, tell the customer: plain text
     * inside their 24 hours, the order_confirmed template after that.
     * Returns what happened, for the admin and the chat history.
     */
    public static function orderConfirmed(int $orderId): string
    {
        $s = Database::connection()->prepare('SELECT reference,customer_name,customer_phone,total_pesewas,requested_date,fulfilment,source FROM orders WHERE id=:id');
        $s->execute([':id' => $orderId]);
        $order = $s->fetch();
        if (!$order || $order['source'] !== 'kafui-whatsapp') return '';
        $conv = KafuiStore::byPhone((string) $order['customer_phone']);
        if (!$conv) return '';
        $date = $order['requested_date'] !== '' && ($t = strtotime($order['requested_date'])) ? date('l j F', $t) : 'to be confirmed';
        $vars = ['1' => trim(explode(' ', trim((string) $order['customer_name']))[0]) ?: 'there', '2' => (string) $order['reference'],
                 '3' => KafuiText::money((int) $order['total_pesewas']), '4' => $date . ($order['fulfilment'] === 'pickup' ? ' (pickup)' : '')];
        $text = WhatsAppTemplates::render('order_confirmed', $vars);
        if (KafuiStore::windowOpen($conv)) {
            $sent = WhatsAppSender::sendText(KafuiStore::replyAddress($conv), $text);
        } elseif (WhatsAppTemplates::isApproved('order_confirmed')) {
            $sent = WhatsAppTemplates::send('order_confirmed', KafuiStore::replyAddress($conv), $vars);
        } else {
            KafuiStore::add((int) $conv['id'], 'system', 'Order ' . $order['reference'] . ' confirmed. The customer was not messaged: their 24 hours have passed and the Order confirmed template is not approved yet.');
            return 'not_sent';
        }
        if (!$sent['ok']) {
            KafuiStore::add((int) $conv['id'], 'system', 'Order ' . $order['reference'] . ' confirmed, but WhatsApp did not take the message: ' . $sent['error']);
            return 'failed';
        }
        KafuiStore::add((int) $conv['id'], 'staff', $text, null, 'Jay foods');
        return 'sent';
    }

    /** CTL-AUTHZ-003: every owner-tool use is reported to the owner. */
    public static function ownerToolUsed(string $tool, string $phone): void
    {
        $to = AgentSettings::ownerEmail();
        if ($to === '') return;
        self::send($to, 'Kafui owner request: ' . $tool,
            '<p>Your owner number ' . self::e(KafuiText::maskPhone($phone)) . ' asked Kafui for <b>' . self::e(str_replace('_', ' ', $tool)) . '</b> at ' . self::e(gmdate('j M Y, H:i')) . ' (Accra time).</p>'
            . '<p>If this was not you, remove the number from the owner list in the admin (Kafui, Settings) and turn Kafui off.</p>');
    }

    public static function invoice(array $invoice, string $why): void
    {
        $to = AgentSettings::ownerEmail();
        if ($to === '') return;
        self::send($to, 'Invoice ready: ' . $invoice['reference'],
            '<p>' . self::e($why) . '</p><p><a href="' . self::e($invoice['url']) . '">Open the invoice for ' . self::e($invoice['reference']) . '</a> (total ' . self::e(KafuiText::money($invoice['total_pesewas'])) . '). The link works for ' . Invoices::LINK_HOURS . ' hours.</p>');
    }

    public static function alert(string $subject, string $text): void
    {
        $to = AgentSettings::alertEmail();
        if ($to === '') { error_log('[kafui alert] ' . $subject); return; }
        self::send($to, $subject, '<p>' . self::e($text) . '</p>');
    }

    private static function staff(string $subject, string $html): void
    {
        try { $to = (new SmtpMailer())->notificationEmail(); } catch (Throwable $e) { $to = ''; }
        if ($to === '') return;
        self::send($to, $subject, $html);
    }

    private static function send(string $to, string $subject, string $html): void
    {
        try {
            $mailer = new SmtpMailer();
            if (!$mailer->isConfigured()) return;
            $mailer->send($to, $subject, '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#173c25"><div style="max-width:600px;margin:auto"><h2 style="color:#176b3a">' . self::e($subject) . '</h2>' . $html . '<p style="color:#68756d;font-size:13px">Kafui, Jay foods</p></div></body></html>');
        } catch (Throwable $e) {
            error_log('[kafui email] ' . $e->getMessage());
            if (class_exists('ErrorLog', false)) ErrorLog::record('email', 'Kafui email "' . $subject . '" not sent: ' . $e->getMessage());
        }
    }

    private static function e(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
