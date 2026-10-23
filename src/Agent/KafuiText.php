<?php

declare(strict_types=1);

/** Small text helpers shared by Kafui's tools and replies. */
final class KafuiText
{
    /** Client rule: no em dashes in anything customers read. */
    public static function noDashes(string $text): string
    {
        $text = preg_replace('/\s*[—–]\s*/u', ', ', $text) ?? $text;
        return str_replace([', ,', ',,'], ',', $text);
    }

    public static function money(int $pesewas): string
    {
        return 'GHS ' . number_format($pesewas / 100, 2);
    }

    /**
     * Every money amount written in a reply, in pesewas. Matches "GHS 20",
     * "GH₵17.00", "GH¢ 1,200", "20 cedis" and "20.00 GHS".
     * @return list<int>
     */
    public static function moneyAmounts(string $text): array
    {
        $cur = '(?:GH₵|GH¢|GHS|GHC|₵|¢|cedis?)';
        $num = '(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,2}))?';
        $out = [];
        if (preg_match_all('/' . $cur . '\s?' . $num . '/iu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $out[] = self::toPesewas($x[1], $x[2] ?? '');
        }
        if (preg_match_all('/(?<![\d.,])' . $num . '\s?' . $cur . '/iu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $out[] = self::toPesewas($x[1], $x[2] ?? '');
        }
        return array_values(array_unique($out));
    }

    private static function toPesewas(string $whole, string $fraction): int
    {
        $whole = str_replace(',', '', $whole);
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        return (int) $whole * 100 + (int) $fraction;
    }

    public static function digits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    /** Ghana numbers: 0XXXXXXXXX and 233XXXXXXXXX compare equal on the last 9 digits. */
    public static function samePhone(string $a, string $b): bool
    {
        $a = self::digits($a); $b = self::digits($b);
        return strlen($a) >= 9 && strlen($b) >= 9 && substr($a, -9) === substr($b, -9);
    }

    public static function maskPhone(string $phone): string
    {
        $d = self::digits($phone);
        return $d === '' ? '' : '...' . substr($d, -4);
    }

    /** Trimmed, control characters removed, capped. */
    public static function field(mixed $value, int $max): string
    {
        $s = is_scalar($value) ? (string) $value : '';
        $s = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s) ?? '';
        return mb_substr(trim($s), 0, $max);
    }
}
