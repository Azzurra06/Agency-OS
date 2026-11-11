<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Formatage d'AFFICHAGE au format fr-FR (aucun calcul métier) : espace fine insécable entre les milliers,
 * virgule décimale, espace insécable avant l'unité (docs/07-ux-ui.md §2.8).
 */
final class Format
{
    private const THIN_NBSP = "\u{202F}";
    private const NBSP = "\u{00A0}";

    public static function number(Decimal|string|null $value, int $decimals = 2): string
    {
        if ($value === null) {
            return '—';
        }
        $fixed = ($value instanceof Decimal ? $value : Decimal::of($value))->toFixed($decimals);
        $negative = str_starts_with($fixed, '-');
        [$int, $dec] = array_pad(explode('.', ltrim($fixed, '-'), 2), 2, '');
        $int = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', self::THIN_NBSP, $int);

        return ($negative ? '−' : '') . $int . ($decimals > 0 ? ',' . $dec : '');
    }

    public static function money(Decimal|string|null $value, int $decimals = 2): string
    {
        return $value === null ? '—' : self::number($value, $decimals) . self::NBSP . '€';
    }

    /** Pourcentage fourni en points (ex. « 13.56 » → « 13,6 % »). */
    public static function percent(Decimal|string|null $points, int $decimals = 1): string
    {
        return $points === null ? '—' : self::number($points, $decimals) . self::NBSP . '%';
    }

    public static function hours(Decimal|string|null $value, int $decimals = 0): string
    {
        return $value === null ? '—' : self::number($value, $decimals) . self::NBSP . 'h';
    }

    public static function date(?string $isoDate): string
    {
        if ($isoDate === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $isoDate, $m)) {
            return '—';
        }

        return $m[3] . '/' . $m[2] . '/' . $m[1];
    }
}
