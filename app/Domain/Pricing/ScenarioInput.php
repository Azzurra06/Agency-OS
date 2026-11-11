<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Support\Decimal;

/**
 * Entrées d'un scénario de prix (docs/03 §4.1). Taux en fractions (0,30 = 30 %), valeurs exactes.
 */
final class ScenarioInput
{
    /**
     * @param list<Decimal> $hours lignes de temps
     * @param list<Decimal> $directCosts frais directs
     */
    public function __construct(
        public readonly Decimal $hourlyCost,
        public readonly Decimal $contingency,
        public readonly Decimal $targetMargin,
        public readonly Decimal $variableRate,
        public readonly array $hours,
        public readonly array $directCosts,
        public readonly ?Decimal $price = null,
        public readonly ?int $annualVolume = null,
    ) {
    }
}
