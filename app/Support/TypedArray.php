<?php

declare(strict_types=1);

namespace App\Support;

use UnexpectedValueException;

/**
 * Lecture typée de tableaux non typés (lignes de base de données, $_SERVER, $_SESSION).
 * Une valeur d'un type inattendu provoque une exception plutôt qu'une conversion silencieuse.
 */
final class TypedArray
{
    /**
     * @param array<mixed> $data
     */
    public static function string(array $data, string $key, ?string $default = null): string
    {
        $value = $data[$key] ?? $default;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        throw new UnexpectedValueException(sprintf('Valeur texte attendue pour « %s ».', $key));
    }

    /**
     * @param array<mixed> $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        return ($data[$key] ?? null) === null ? null : self::string($data, $key);
    }

    /**
     * @param array<mixed> $data
     */
    public static function int(array $data, string $key, ?int $default = null): int
    {
        $value = $data[$key] ?? $default;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }
        throw new UnexpectedValueException(sprintf('Valeur entière attendue pour « %s ».', $key));
    }

    /**
     * @param array<mixed> $data
     */
    public static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? false;

        return $value === true || $value === 1 || $value === '1';
    }
}
