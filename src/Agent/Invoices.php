<?php

declare(strict_types=1);

/**
 * Invoice PDFs for Kafui. Files live in database/invoices/ (outside the web
 * root) under random names and are only served through a signed link that
 * expires after 72 hours. Housekeeping deletes them after 30 days.
 */
final class Invoices
{
    public const LINK_HOURS = 72;

    public static function dir(): string
    {
        $db = getenv('JAYFOODS_DB');
        $base = $db ? dirname($db) : dirname(__DIR__, 2) . '/database';
        $dir = $base . '/invoices';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        return $dir;
    }

    /** The invoice PDF for an order, built from the order and the Site content contact details. @return array{pdf:string,order:array} */
    public static function render(int $orderId): array
    {
        $db = Database::connection();
        $o = $db->prepare('SELECT * FROM orders WHERE id=:id');
        $o->execute([':id' => $orderId]);
        $order = $o->fetch();
        if (!$order) throw new RuntimeException('Order not found.');
        $items = $db->prepare('SELECT product_name,unit_price_pesewas,quantity,is_bulk,line_total_pesewas FROM order_items WHERE order_id=:id ORDER BY id');
        $items->execute([':id' => $orderId]);
        return ['pdf' => InvoicePdf::render($order, $items->fetchAll(), self::business()), 'order' => $order];
    }

    /** Jay foods contact details for invoices, from Site content (admin), so they stay in one place. @return array<string,string> */
    public static function business(): array
    {
        $c = [];
        foreach (SiteContentController::definitions() as $fields) foreach ($fields as $k => $meta) $c[$k] = $meta[1];
        foreach (Database::connection()->query('SELECT content_key,value FROM site_content')->fetchAll() as $r) {
            if (array_key_exists($r['content_key'], $c)) $c[$r['content_key']] = $r['value'];
        }
        $wa = KafuiText::digits((string) ($c['whatsapp_number'] ?? ''));
        if (preg_match('/^233(\d{2})(\d{3})(\d{4})$/', $wa, $m)) $wa = '+233 ' . $m[1] . ' ' . $m[2] . ' ' . $m[3];
        elseif ($wa !== '') $wa = '+' . $wa;
        $site = AgentSettings::siteUrl();
        return [
            'name'         => KafuiText::noDashes((string) ($c['headquarters_name'] ?? 'Jay foods Ghana')),
            'address'      => KafuiText::noDashes((string) ($c['contact_address'] ?? 'Accra, Ghana')),
            'phone'        => (string) ($c['telephone_display'] ?? ''),
            'whatsapp'     => $wa,
            'email'        => (string) ($c['contact_email'] ?? ''),
            'website'      => $site !== '' ? (string) preg_replace('#^https?://#', '', $site) : '',
            'payment_note' => AgentSettings::get('payment_note'),
            'logo'         => __DIR__ . '/assets/invoice-logo.jpg',
        ];
    }

    /** @return array{id:string,reference:string,url:string,filename:string,total_pesewas:int} */
    public static function create(int $orderId): array
    {
        $db = Database::connection();
        ['pdf' => $pdf, 'order' => $order] = self::render($orderId);

        $id = bin2hex(random_bytes(16));
        if (file_put_contents(self::dir() . '/' . $id . '.pdf', $pdf) === false) {
            throw new RuntimeException('Could not save the invoice file.');
        }
        $db->prepare('INSERT INTO agent_invoices(id,order_id) VALUES(:id,:o)')->execute([':id' => $id, ':o' => $orderId]);
        $token = Signer::sign('invoice', $id, time() + self::LINK_HOURS * 3600);
        return [
            'id' => $id,
            'reference' => (string) $order['reference'],
            'url' => AgentSettings::siteUrl() . '/api/v1/invoices/' . $token,
            'filename' => 'JayFoods-invoice-' . preg_replace('/[^A-Za-z0-9-]/', '', (string) $order['reference']) . '.pdf',
            'total_pesewas' => (int) $order['total_pesewas'],
        ];
    }

    /** GET /api/v1/invoices/{token} */
    public static function serve(string $token): void
    {
        $id = Signer::verify('invoice', $token);
        if ($id === null || !preg_match('/^[a-f0-9]{32}$/', $id)) { Response::json(['error' => 'This invoice link is invalid or has expired.'], 403); return; }
        $s = Database::connection()->prepare('SELECT i.id,o.reference FROM agent_invoices i JOIN orders o ON o.id=i.order_id WHERE i.id=:id');
        $s->execute([':id' => $id]);
        $row = $s->fetch();
        $path = self::dir() . '/' . $id . '.pdf';
        if (!$row || !is_file($path)) { Response::json(['error' => 'This invoice is no longer available.'], 404); return; }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="JayFoods-invoice-' . preg_replace('/[^A-Za-z0-9-]/', '', (string) $row['reference']) . '.pdf"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }
}
