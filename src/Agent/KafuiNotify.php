<?php

declare(strict_types=1);

/** Staff and owner emails for Kafui, sent through the site's existing Gmail SMTP. */
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
    }

    public static function handoff(array $conversation, string $reason): void
    {
        self::staff('A customer is waiting for a person',
            '<p>Kafui handed a ' . self::e($conversation['channel'] === 'web' ? 'website chat' : 'WhatsApp chat') . ' to staff.</p>'
            . '<p>Customer: ' . self::e($conversation['contact_name'] ?: 'not given') . ($conversation['phone'] !== '' ? ', +' . self::e($conversation['phone']) : '') . '</p>'
            . '<p>Reason: ' . self::e($reason) . '</p>'
            . '<p>Reply from the admin under Kafui. Kafui stays quiet in this chat until you hand it back.'
            . ($conversation['channel'] === 'whatsapp' ? ' WhatsApp lets you reply for 24 hours after the customer\'s last message.' : '') . '</p>');
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
        }
    }

    private static function e(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
