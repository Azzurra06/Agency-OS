<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Identifiant public non prédictible (128 bits aléatoires, base32 de Crockford, 26 caractères).
 *
 * Défense en profondeur uniquement (décision D7) : il n'est JAMAIS une mesure d'isolation.
 * Contrairement à un ULID, il ne contient pas d'horodatage (ne révèle pas la date de création).
 */
final class PublicId
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    public static function generate(): string
    {
        $bytes = random_bytes(16);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $bits = str_pad($bits, 130, '0', STR_PAD_LEFT);
        $id = '';
        foreach (str_split($bits, 5) as $chunk) {
            $id .= self::ALPHABET[(int) bindec($chunk)];
        }

        return $id;
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
