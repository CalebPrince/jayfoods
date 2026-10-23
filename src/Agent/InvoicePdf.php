<?php

declare(strict_types=1);

/**
 * A plain one-file PDF writer for order invoices: built-in Helvetica, text
 * and lines only, no packages. Amounts are shown as "GHS" because the cedi
 * sign is not in the PDF base fonts.
 */
final class InvoicePdf
{
    private array $pages = [];
    private string $ops = '';
    private float $y = 0;

    /** @param array<string,mixed> $order @param list<array<string,mixed>> $items */
    public static function render(array $order, array $items, array $business): string
    {
        $pdf = new self();
        $pdf->newPage();
        $pdf->text(50, 790, 20, true, (string) ($business['name'] ?? 'Jay foods'));
        $pdf->text(50, 772, 9, false, (string) ($business['address'] ?? ''));
        $pdf->text(50, 760, 9, false, (string) ($business['phone'] ?? ''));
        $pdf->text(400, 790, 16, true, 'INVOICE');
        $pdf->text(400, 772, 10, false, 'Order ' . $order['reference']);
        $pdf->text(400, 758, 10, false, 'Date ' . substr((string) $order['created_at'], 0, 10));
        $pdf->line(50, 745, 545, 745, 1.5);

        $pdf->y = 722;
        foreach ([
            ['Customer', (string) $order['customer_name']],
            ['Phone', (string) $order['customer_phone']],
            ['Deliver to', trim((string) $order['delivery_address'] . ', ' . (string) $order['region'], ', ')],
            ['Order status', ucfirst((string) $order['status'])],
            ['Payment', (string) $order['payment_status'] === 'paid' ? 'Paid' : 'Not paid yet'],
        ] as [$label, $value]) {
            $pdf->text(50, $pdf->y, 10, true, $label);
            $pdf->text(150, $pdf->y, 10, false, mb_substr($value, 0, 80));
            $pdf->y -= 15;
        }

        $pdf->y -= 12;
        $pdf->tableHead();
        foreach ($items as $item) {
            if ($pdf->y < 120) { $pdf->newPage(); $pdf->y = 790; $pdf->tableHead(); }
            $name = (string) $item['product_name'] . (!empty($item['is_bulk']) ? ' (bulk price)' : '');
            $pdf->text(50, $pdf->y, 10, false, mb_substr($name, 0, 52));
            $pdf->text(340, $pdf->y, 10, false, (string) (int) $item['quantity']);
            $pdf->right(470, $pdf->y, 10, false, self::money((int) $item['unit_price_pesewas']));
            $pdf->right(545, $pdf->y, 10, false, self::money((int) $item['line_total_pesewas']));
            $pdf->y -= 16;
        }
        $pdf->line(50, $pdf->y + 6, 545, $pdf->y + 6, 0.5);
        $pdf->y -= 10;
        $totals = [['Subtotal', (int) $order['subtotal_pesewas'], false]];
        if ((int) ($order['discount_pesewas'] ?? 0) > 0) $totals[] = ['Discount', -(int) $order['discount_pesewas'], false];
        $totals[] = ['Delivery', (int) $order['delivery_fee_pesewas'], false];
        $totals[] = ['Total', (int) $order['total_pesewas'], true];
        foreach ($totals as [$label, $amount, $bold]) {
            $pdf->text(380, $pdf->y, $bold ? 12 : 10, $bold, $label);
            $pdf->right(545, $pdf->y, $bold ? 12 : 10, $bold, ($amount < 0 ? '-' : '') . self::money(abs($amount)));
            $pdf->y -= $bold ? 20 : 15;
        }
        $pdf->text(50, 60, 8, false, 'Jay foods, ' . $order['reference'] . '. Prices from the Jay foods price list on the order date.');
        return $pdf->output();
    }

    private static function money(int $p): string { return 'GHS ' . number_format($p / 100, 2); }

    private function tableHead(): void
    {
        $this->text(50, $this->y, 10, true, 'Item');
        $this->text(340, $this->y, 10, true, 'Qty');
        $this->right(470, $this->y, 10, true, 'Unit price');
        $this->right(545, $this->y, 10, true, 'Amount');
        $this->line(50, $this->y - 5, 545, $this->y - 5, 0.5);
        $this->y -= 20;
    }

    private function newPage(): void
    {
        if ($this->ops !== '') $this->pages[] = $this->ops;
        $this->ops = '';
    }

    private function text(float $x, float $y, float $size, bool $bold, string $s): void
    {
        $this->ops .= sprintf("BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $y, self::esc($s));
    }

    /** Right-aligned text using an average Helvetica glyph width (good enough for digits). */
    private function right(float $xRight, float $y, float $size, bool $bold, string $s): void
    {
        $width = strlen(self::latin($s)) * $size * ($bold ? 0.58 : 0.54);
        $this->text($xRight - $width, $y, $size, $bold, $s);
    }

    private function line(float $x1, float $y1, float $x2, float $y2, float $w): void
    {
        $this->ops .= sprintf("%.2F w %.2F %.2F m %.2F %.2F l S\n", $w, $x1, $y1, $x2, $y2);
    }

    private static function latin(string $s): string
    {
        $s = KafuiText::noDashes($s);
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        return $converted === false ? (preg_replace('/[^\x20-\x7E]/', '?', $s) ?? '') : $converted;
    }

    private static function esc(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], self::latin($s));
    }

    private function output(): string
    {
        $this->newPage();
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        foreach ($this->pages as $content) {
            $pageId = $n++; $contentId = $n++;
            $kids[] = $pageId . ' 0 R';
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
            $objects[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) $out .= sprintf("%010d 00000 n \n", $offset);
        $out .= 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $out;
    }
}
