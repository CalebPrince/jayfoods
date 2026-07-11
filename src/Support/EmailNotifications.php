<?php

declare(strict_types=1);

final class EmailNotifications
{
    public static function orderCreated(array $order, array $items): void
    {
        self::quietly(function () use ($order, $items): void {
            $mailer = new SmtpMailer();
            if (!$mailer->isConfigured()) return;
            $rows = self::itemRows($items);
            $total = self::money((int) $order['subtotal_pesewas']);
            $delivery = self::money((int) ($order['delivery_fee_pesewas'] ?? 0));
            $grand = self::money((int) ($order['total_pesewas'] ?? $order['subtotal_pesewas']));
            $ref = self::e($order['reference']);
            $html = self::layout("Order started: $ref", "<p>Hello " . self::e($order['customer_name']) . ",</p><p>We have received your order details and are awaiting verified payment.</p>$rows<p>Products: $total<br>Delivery: $delivery<br><b>Amount due: $grand</b></p><p>Reference: <b>$ref</b></p>");
            if (!empty($order['customer_email'])) $mailer->send($order['customer_email'], "Jay fooDs order awaiting payment — $ref", $html);
            if ($mailer->notificationEmail()) {
                $admin = self::layout("New unpaid order: $ref", '<p>An order was started by <b>' . self::e($order['customer_name']) . '</b> (' . self::e($order['customer_phone']) . ").</p>$rows<p>Products: $total<br>Delivery: $delivery<br><b>Awaiting payment: $grand</b></p>");
                $mailer->send($mailer->notificationEmail(), "New unpaid Jay fooDs order — $ref", $admin);
            }
        });
    }

    public static function statusChanged(array $order, string $status): void
    {
        if (empty($order['customer_email'])) return;
        self::quietly(function () use ($order, $status): void {
            $mailer = new SmtpMailer();
            if (!$mailer->isConfigured()) return;
            $ref = self::e($order['reference']);
            $label = ucfirst($status);
            $html = self::layout("Order $ref is $label", '<p>Hello ' . self::e($order['customer_name']) . ",</p><p>Your order status has changed to <b>$label</b>.</p><p>Reference: <b>$ref</b></p>");
            $mailer->send($order['customer_email'], "Order $ref update — $label", $html);
        });
    }

    public static function paymentConfirmed(array $order, array $items): void
    {
        self::quietly(function () use ($order, $items): void {
            $mailer = new SmtpMailer();
            if (!$mailer->isConfigured()) return;
            $ref = self::e($order['reference']);
            $rows = self::itemRows($items);
            $total = self::money((int) $order['subtotal_pesewas']);
            $delivery = self::money((int) ($order['delivery_fee_pesewas'] ?? 0));
            $grand = self::money((int) ($order['total_pesewas'] ?? $order['subtotal_pesewas']));
            $html = self::layout("Payment confirmed: $ref", '<p>Hello ' . self::e($order['customer_name']) . ",</p><p>Your Paystack payment has been verified successfully.</p>$rows<p>Products: $total<br>Delivery: $delivery<br><b>Amount paid: $grand</b></p><p>We will contact you with delivery updates.</p>");
            if (!empty($order['customer_email'])) $mailer->send($order['customer_email'], "Payment confirmed — $ref", $html);
            if ($mailer->notificationEmail()) {
                $admin = self::layout("Paid order: $ref", '<p>Payment has been verified for <b>' . self::e($order['customer_name']) . ".</b></p>$rows<p>Products: $total<br>Delivery: $delivery<br><b>Amount received: $grand</b></p>");
                $mailer->send($mailer->notificationEmail(), "Paid Jay fooDs order — $ref", $admin);
            }
        });
    }

    public static function contactReceived(array $message): void
    {
        self::quietly(function () use ($message): void {
            $mailer = new SmtpMailer();
            if (!$mailer->isConfigured() || !$mailer->notificationEmail()) return;
            $html = self::layout('New website message', '<p><b>From:</b> ' . self::e($message['name']) . '</p><p><b>Phone:</b> ' . self::e($message['phone']) . '</p><p><b>Email:</b> ' . self::e($message['email']) . '</p><p><b>Message:</b><br>' . nl2br(self::e($message['message'])) . '</p>');
            $mailer->send($mailer->notificationEmail(), 'New Jay fooDs website message', $html);
        });
    }

    private static function quietly(callable $send): void
    {
        try { $send(); } catch (Throwable $e) { error_log('[jayfoods email] ' . $e->getMessage()); }
    }

    private static function itemRows(array $items): string
    {
        $html = '<table style="width:100%;border-collapse:collapse"><tr><th align="left">Item</th><th>Qty</th><th align="right">Amount</th></tr>';
        foreach ($items as $item) $html .= '<tr><td style="padding:8px 0;border-top:1px solid #ddd">' . self::e($item['product_name']) . '</td><td align="center" style="border-top:1px solid #ddd">' . (int) $item['quantity'] . '</td><td align="right" style="border-top:1px solid #ddd">' . self::money((int) $item['line_total_pesewas']) . '</td></tr>';
        return $html . '</table>';
    }

    private static function layout(string $title, string $content): string { return '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#173c25"><div style="max-width:600px;margin:auto"><h2 style="color:#176b3a">' . self::e($title) . '</h2>' . $content . '<p style="color:#68756d;font-size:13px">Jay fooDs</p></div></body></html>'; }
    private static function money(int $p): string { return 'GH₵' . number_format($p / 100, 2); }
    private static function e(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
