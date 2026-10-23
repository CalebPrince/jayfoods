<?php

declare(strict_types=1);

/**
 * Jay foods invoice PDF: one built-in template with the real logo, the
 * contact details from Site content, and the logo's colours. Plain PHP, no
 * packages: Helvetica base fonts, filled shapes and one JPEG image. Amounts
 * show as "GHS" because the cedi sign is not in the PDF base fonts.
 */
final class InvoicePdf
{
    private const INK = [0.16, 0.19, 0.20];     // charcoal, as "Jay" in the logo
    private const MUTED = [0.40, 0.45, 0.43];
    private const ORANGE = [0.96, 0.42, 0.13];  // logo orange
    private const GREEN = [0.12, 0.48, 0.24];   // site green #1f7a3d
    private const TINT = [0.95, 0.98, 0.96];    // light green panel
    private const ROW = [0.98, 0.96, 0.93];     // warm row stripe
    private const LINE = [0.86, 0.89, 0.87];

    private const PAGE_W = 595.0;
    private const PAGE_H = 842.0;
    private const LEFT = 48.0;
    private const RIGHT = 547.0;

    /** Helvetica and Helvetica-Bold widths (per 1000) for the characters amounts and labels use. */
    private const W = ['0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556, '8' => 556, '9' => 556,
        '.' => 278, ',' => 278, ' ' => 278, '-' => 333, 'G' => 778, 'H' => 722, 'S' => 667, 'x' => 500];

    private array $pages = [];
    private string $ops = '';
    private float $y = 0;
    private array $footer = [];

    /**
     * @param array<string,mixed> $order row from orders
     * @param list<array<string,mixed>> $items rows from order_items
     * @param array<string,string> $biz name, address, phone, whatsapp, email, website, payment_note, logo (path to a JPEG)
     */
    public static function render(array $order, array $items, array $biz): string
    {
        $pdf = new self();
        $pdf->footer = $biz;
        $logo = is_file((string) ($biz['logo'] ?? '')) ? (string) file_get_contents((string) $biz['logo']) : '';
        $pdf->newPage();

        // Header: orange rule, logo and contacts on the left, invoice facts on the right.
        $pdf->rect(0, self::PAGE_H - 8, self::PAGE_W, 8, self::ORANGE);
        $top = self::PAGE_H - 40;
        if ($logo !== '') $pdf->image(self::LEFT, $top - 66, 88, 66);
        $cx = $logo !== '' ? self::LEFT + 102 : self::LEFT;
        $pdf->text($cx, $top - 10, 13, true, (string) ($biz['name'] ?? 'Jay foods Ghana'), self::INK);
        $line = $top - 25;
        foreach ([
            (string) ($biz['address'] ?? ''),
            trim(($biz['phone'] ?? '') !== '' ? 'Call ' . $biz['phone'] : '') . (($biz['whatsapp'] ?? '') !== '' ? (($biz['phone'] ?? '') !== '' ? '   ' : '') . 'WhatsApp ' . $biz['whatsapp'] : ''),
            trim((string) ($biz['email'] ?? '') . (($biz['email'] ?? '') !== '' && ($biz['website'] ?? '') !== '' ? '   ' : '') . (string) ($biz['website'] ?? '')),
        ] as $l) {
            if (trim($l) === '') continue;
            $pdf->text($cx, $line, 8.5, false, mb_substr($l, 0, 90), self::MUTED);
            $line -= 12;
        }

        $pdf->right(self::RIGHT, $top - 14, 26, true, 'INVOICE', self::ORANGE);
        $facts = [
            ['Invoice no.', 'INV-' . $order['reference']],
            ['Order', (string) $order['reference']],
            ['Date', self::date((string) $order['created_at'])],
        ];
        $fy = $top - 34;
        foreach ($facts as [$k, $v]) {
            $pdf->text(380, $fy, 8.5, false, $k, self::MUTED);
            $pdf->right(self::RIGHT, $fy, 9, true, $v, self::INK);
            $fy -= 13;
        }
        $paid = (string) $order['payment_status'] === 'paid';
        $pill = $paid ? 'PAID' : 'NOT PAID YET';
        $pw = self::width($pill, 8, true) + 16;
        $pdf->rect(self::RIGHT - $pw, $fy - 5, $pw, 15, $paid ? self::GREEN : self::ORANGE);
        $pdf->text(self::RIGHT - $pw + 8, $fy, 8, true, $pill, [1, 1, 1]);

        // Bill to panel.
        $pdf->y = $top - 112;
        $panelH = 58;
        $pdf->rect(self::LEFT, $pdf->y - $panelH, self::RIGHT - self::LEFT, $panelH, self::TINT);
        $pdf->rect(self::LEFT, $pdf->y - $panelH, 3, $panelH, self::GREEN);
        $pdf->text(self::LEFT + 14, $pdf->y - 15, 7.5, true, 'BILL TO', self::GREEN);
        $pdf->text(self::LEFT + 14, $pdf->y - 30, 11, true, mb_substr((string) $order['customer_name'], 0, 45), self::INK);
        $pdf->text(self::LEFT + 14, $pdf->y - 44, 9, false, (string) $order['customer_phone'], self::MUTED);
        $pickup = (string) ($order['fulfilment'] ?? '') === 'pickup';
        $pdf->text(300, $pdf->y - 15, 7.5, true, $pickup ? 'PICKUP' : 'DELIVER TO', self::GREEN);
        $where = $pickup ? 'Pickup' : trim((string) $order['delivery_address'] . ', ' . (string) $order['region'], ', ');
        foreach (self::wrap($where, 48, 2) as $i => $w) $pdf->text(300, $pdf->y - 30 - $i * 12, 9.5, $i === 0, $w, self::INK);
        if (!empty($order['requested_date'])) $pdf->text(300, $pdf->y - 54 + ($pickup ? 12 : 0), 8.5, false, 'Wanted on ' . self::date((string) $order['requested_date']), self::MUTED);
        $pdf->y -= $panelH + 26;

        // Items.
        $pdf->tableHead();
        $i = 0;
        foreach ($items as $item) {
            if ($pdf->y < 190) { $pdf->newPage(); $pdf->y = self::PAGE_H - 70; $pdf->tableHead(); }
            if ($i++ % 2 === 1) $pdf->rect(self::LEFT, $pdf->y - 6, self::RIGHT - self::LEFT, 20, self::ROW);
            $name = (string) $item['product_name'];
            $pdf->text(self::LEFT + 10, $pdf->y, 9.5, false, mb_substr($name, 0, 48), self::INK);
            if (!empty($item['is_bulk'])) $pdf->text(self::LEFT + 10 + self::width(mb_substr($name, 0, 48), 9.5, false) + 6, $pdf->y, 7.5, true, 'BULK PRICE', self::ORANGE);
            $pdf->right(370, $pdf->y, 9.5, false, (string) (int) $item['quantity'], self::INK);
            $pdf->right(455, $pdf->y, 9.5, false, self::money((int) $item['unit_price_pesewas']), self::INK);
            $pdf->right(self::RIGHT - 10, $pdf->y, 9.5, true, self::money((int) $item['line_total_pesewas']), self::INK);
            $pdf->y -= 20;
        }
        $pdf->line(self::LEFT, $pdf->y + 8, self::RIGHT, $pdf->y + 8, 0.6, self::LINE);

        // Totals.
        $pdf->y -= 10;
        $totalsTop = $pdf->y;
        $rows = [['Subtotal', (int) $order['subtotal_pesewas']]];
        if ((int) ($order['discount_pesewas'] ?? 0) > 0) $rows[] = ['Discount' . (($order['promo_code'] ?? '') !== '' ? ' (' . $order['promo_code'] . ')' : ''), -(int) $order['discount_pesewas']];
        $rows[] = [$pickup ? 'Pickup' : 'Delivery', (int) $order['delivery_fee_pesewas']];
        foreach ($rows as [$label, $amount]) {
            $pdf->text(360, $pdf->y, 9.5, false, $label, self::MUTED);
            $pdf->right(self::RIGHT - 10, $pdf->y, 9.5, false, ($amount < 0 ? '-' : '') . self::money(abs($amount)), self::INK);
            $pdf->y -= 16;
        }
        $pdf->y -= 8;
        $pdf->rect(350, $pdf->y - 9, self::RIGHT - 350, 26, self::GREEN);
        $pdf->text(360, $pdf->y, 11, true, 'Total', [1, 1, 1]);
        $pdf->right(self::RIGHT - 10, $pdf->y, 12, true, self::money((int) $order['total_pesewas']), [1, 1, 1]);

        // Payment note.
        $note = $paid ? 'Paid in full. Thank you!' : (string) ($biz['payment_note'] ?? '');
        if ($note !== '') {
            $ny = $totalsTop - 8;
            $pdf->text(self::LEFT, $ny + 8, 7.5, true, 'PAYMENT', self::GREEN);
            foreach (self::wrap($note, 55, 3) as $k => $w) $pdf->text(self::LEFT, $ny - 6 - $k * 11, 8.5, false, $w, self::MUTED);
        }
        return $pdf->output($logo);
    }

    // ------------------------------------------------------------------ layout pieces

    private function tableHead(): void
    {
        $this->rect(self::LEFT, $this->y - 7, self::RIGHT - self::LEFT, 22, self::INK);
        $white = [1, 1, 1];
        $this->text(self::LEFT + 10, $this->y, 8, true, 'ITEM', $white);
        $this->right(370, $this->y, 8, true, 'QTY', $white);
        $this->right(455, $this->y, 8, true, 'UNIT PRICE', $white);
        $this->right(self::RIGHT - 10, $this->y, 8, true, 'AMOUNT', $white);
        $this->y -= 24;
    }

    private function newPage(): void
    {
        if ($this->ops !== '') $this->pages[] = $this->ops;
        $this->ops = '';
        // Footer on every page.
        $this->line(self::LEFT, 58, self::RIGHT, 58, 0.6, self::LINE);
        $this->text(self::LEFT, 44, 9, true, 'Thank you for choosing ' . ($this->footer['name'] ?? 'Jay foods') . '.', self::INK);
        $contact = array_filter([$this->footer['phone'] ?? '', $this->footer['whatsapp'] ?? '' ? 'WhatsApp ' . $this->footer['whatsapp'] : '', $this->footer['website'] ?? '']);
        $this->text(self::LEFT, 31, 7.5, false, implode('   ', $contact), self::MUTED);
        $this->right(self::RIGHT, 44, 7.5, false, 'Prices from the Jay foods price list on the order date.', self::MUTED);
        $this->rect(0, 0, self::PAGE_W, 6, self::GREEN);
    }

    // ------------------------------------------------------------------ drawing

    private function text(float $x, float $y, float $size, bool $bold, string $s, array $rgb): void
    {
        $this->ops .= sprintf("%.3F %.3F %.3F rg BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $rgb[0], $rgb[1], $rgb[2], $bold ? 'F2' : 'F1', $size, $x, $y, self::esc($s));
    }

    private function right(float $xRight, float $y, float $size, bool $bold, string $s, array $rgb): void
    {
        $this->text($xRight - self::width($s, $size, $bold), $y, $size, $bold, $s, $rgb);
    }

    private function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->ops .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $rgb[0], $rgb[1], $rgb[2], $x, $y, $w, $h);
    }

    private function line(float $x1, float $y1, float $x2, float $y2, float $w, array $rgb): void
    {
        $this->ops .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n", $rgb[0], $rgb[1], $rgb[2], $w, $x1, $y1, $x2, $y2);
    }

    private function image(float $x, float $y, float $w, float $h): void
    {
        $this->ops .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q\n", $w, $h, $x, $y);
    }

    // ------------------------------------------------------------------ text helpers

    private static function width(string $s, float $size, bool $bold): float
    {
        $total = 0;
        foreach (str_split(self::latin($s)) as $ch) {
            $total += self::W[$ch] ?? (ctype_upper($ch) ? ($bold ? 722 : 690) : ($bold ? 590 : 540));
        }
        return $total * $size / 1000;
    }

    /** @return list<string> */
    private static function wrap(string $s, int $chars, int $maxLines): array
    {
        $lines = explode("\n", wordwrap(trim($s), $chars, "\n", true));
        if (count($lines) > $maxLines) { $lines = array_slice($lines, 0, $maxLines); $lines[$maxLines - 1] = rtrim($lines[$maxLines - 1], ' .,') . '...'; }
        return $lines;
    }

    private static function money(int $p): string { return 'GHS ' . number_format($p / 100, 2); }

    private static function date(string $s): string
    {
        $t = strtotime($s . (strlen($s) > 10 ? ' UTC' : ''));
        return $t ? gmdate('j M Y', $t) : $s;
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

    // ------------------------------------------------------------------ file

    private function output(string $jpeg): string
    {
        if ($this->ops !== '') $this->pages[] = $this->ops;
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $xobject = '';
        if ($jpeg !== '' && ($info = @getimagesizefromstring($jpeg)) && ($info[2] ?? 0) === IMAGETYPE_JPEG) {
            $objects[5] = '<< /Type /XObject /Subtype /Image /Width ' . $info[0] . ' /Height ' . $info[1] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($jpeg) . " >>\nstream\n" . $jpeg . "\nendstream";
            $xobject = ' /XObject << /Im1 5 0 R >>';
        }
        $kids = [];
        $n = 6;
        foreach ($this->pages as $content) {
            $pageId = $n++; $contentId = $n++;
            $kids[] = $pageId . ' 0 R';
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>' . $xobject . ' >> /Contents ' . $contentId . ' 0 R >>';
            $objects[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $max = max(array_keys($objects));
        for ($id = 1; $id <= $max; $id++) {
            if (!isset($objects[$id])) { $objects[$id] = 'null'; }
            $offsets[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $objects[$id] . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $max; $id++) $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
        $out .= 'trailer << /Size ' . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $out;
    }
}
