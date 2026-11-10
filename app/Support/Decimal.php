<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use DivisionByZeroError;

/**
 * Nombre décimal exact, immuable, construit sur BCMath.
 *
 * Politique (docs/03-formules-et-scoring.md §0.1) :
 *  - aucune valeur flottante n'est acceptée (constructeur limité aux chaînes et entiers) ;
 *  - précision interne de 12 décimales pour chaque opération ;
 *  - arrondi unique en sortie, au plus proche, moitié éloignée de zéro ;
 *  - « -0 » est normalisé en « 0 ».
 */
final class Decimal
{
    public const INTERNAL_SCALE = 12;

    private const PATTERN = '/^-?\d+(\.\d+)?$/';

    /**
     * @param numeric-string $value
     */
    private function __construct(private readonly string $value)
    {
    }

    public static function of(string|int $value): self
    {
        $string = is_int($value) ? (string) $value : trim($value);
        if (!preg_match(self::PATTERN, $string) || !is_numeric($string)) {
            throw new InvalidArgumentException('Valeur décimale invalide.');
        }

        return new self(self::normalize(bcadd($string, '0', self::INTERNAL_SCALE)));
    }

    public static function zero(): self
    {
        return new self('0');
    }

    public function add(self $other): self
    {
        return new self(self::normalize(bcadd($this->value, $other->value, self::INTERNAL_SCALE)));
    }

    public function sub(self $other): self
    {
        return new self(self::normalize(bcsub($this->value, $other->value, self::INTERNAL_SCALE)));
    }

    public function mul(self $other): self
    {
        return new self(self::normalize(bcmul($this->value, $other->value, self::INTERNAL_SCALE)));
    }

    public function div(self $other): self
    {
        if ($other->isZero()) {
            throw new DivisionByZeroError('Division par zéro.');
        }

        return new self(self::normalize(bcdiv($this->value, $other->value, self::INTERNAL_SCALE)));
    }

    public function negate(): self
    {
        return self::zero()->sub($this);
    }

    public function compare(self $other): int
    {
        return bccomp($this->value, $other->value, self::INTERNAL_SCALE);
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->value, '0', self::INTERNAL_SCALE) === 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->value, '0', self::INTERNAL_SCALE) < 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->value, '0', self::INTERNAL_SCALE) > 0;
    }

    public function min(self $other): self
    {
        return $this->compare($other) <= 0 ? $this : $other;
    }

    public function max(self $other): self
    {
        return $this->compare($other) >= 0 ? $this : $other;
    }

    /**
     * Arrondi au plus proche, moitié éloignée de zéro (2,675 → 2,68 ; −2,345 → −2,35).
     */
    public function round(int $scale): self
    {
        if ($scale < 0 || $scale > self::INTERNAL_SCALE) {
            throw new InvalidArgumentException('Échelle d\'arrondi invalide.');
        }
        $half = bcdiv('5', bcpow('10', (string) ($scale + 1)), $scale + 1);
        $shifted = $this->isNegative()
            ? bcsub($this->value, $half, self::INTERNAL_SCALE)
            : bcadd($this->value, $half, self::INTERNAL_SCALE);

        return new self(self::normalize(bcadd($shifted, '0', $scale)));
    }

    /**
     * Représentation à échelle fixe, après arrondi (ex. toFixed(2) → "3970.59").
     */
    public function toFixed(int $scale): string
    {
        $rounded = $this->round($scale)->value;
        $formatted = bcadd($rounded, '0', $scale);

        return self::isNegativeZero($formatted) ? ltrim($formatted, '-') : $formatted;
    }

    /**
     * Valeur exacte, sans zéros superflus.
     */
    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * @return numeric-string
     */
    private static function normalize(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        if ($value === '' || $value === '-' || self::isNegativeZero($value)) {
            return '0';
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('Résultat décimal invalide.');
        }

        return $value;
    }

    private static function isNegativeZero(string $value): bool
    {
        return str_starts_with($value, '-') && trim(substr($value, 1), '0.') === '';
    }
}
