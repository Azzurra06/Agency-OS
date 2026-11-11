<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Support\Decimal;

/**
 * Valeur dérivée avec sa nature (docs/05-architecture-detaillee.md §1) :
 * calculated | assumption | declared, liste des hypothèses utilisées et des champs manquants.
 */
final class DerivedValue
{
    /**
     * @param list<string> $assumptions
     * @param list<string> $missing
     */
    public function __construct(
        public readonly ?Decimal $value,
        public readonly string $nature,
        public readonly array $assumptions = [],
        public readonly array $missing = [],
    ) {
    }

    /**
     * @param list<string> $missing
     */
    public static function missing(array $missing): self
    {
        return new self(null, 'calculated', [], array_values(array_unique($missing)));
    }

    public function isAvailable(): bool
    {
        return $this->value !== null;
    }

    /**
     * Format de l'API : décimaux en chaînes (montants à 2 décimales, taux en pourcentage à 2 décimales).
     *
     * @return array{value: string|null, nature: string, assumptions: list<string>, missing: list<string>}
     */
    public function toArray(string $kind = 'amount'): array
    {
        $value = null;
        if ($this->value !== null) {
            $value = $kind === 'percent'
                ? $this->value->mul(Decimal::of('100'))->toFixed(2)
                : $this->value->toFixed($kind === 'ratio' ? 4 : 2);
        }

        return ['value' => $value, 'nature' => $this->nature, 'assumptions' => $this->assumptions, 'missing' => $this->missing];
    }
}
