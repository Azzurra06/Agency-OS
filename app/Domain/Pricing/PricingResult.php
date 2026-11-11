<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Support\Decimal;

/**
 * Résultats exacts d'un scénario. L'arrondi n'a lieu qu'en sortie (toArray), une seule fois (docs/03 §0.1).
 */
final class PricingResult
{
    /**
     * @param list<array{code: string, level: string}> $alerts
     */
    public function __construct(
        public readonly Decimal $totalHours,
        public readonly Decimal $timeCost,
        public readonly Decimal $directCosts,
        public readonly Decimal $baseCost,
        public readonly Decimal $floorPrice,
        public readonly Decimal $recommendedPrice,
        public readonly ?Decimal $price,
        public readonly ?Decimal $result,
        public readonly ?Decimal $fullCost,
        public readonly ?Decimal $marginOnPrice,
        public readonly ?Decimal $markupOnCost,
        public readonly ?Decimal $gapToRecommended,
        public readonly ?Decimal $annualShortfall,
        public readonly array $alerts,
    ) {
    }

    /**
     * Format de l'API : montants et heures à 2 décimales, taux en pourcentage à 2 décimales.
     *
     * @return array{total_hours: string, time_cost: string, direct_costs: string, base_cost: string, floor_price: string, recommended_price: string,
     *   at_price: array{price: string, result: string, full_cost: string, margin_on_price_pct: string|null, markup_on_cost_pct: string|null, gap_to_recommended_pct: string, annual_shortfall: string|null}|null,
     *   alerts: list<array{code: string, level: string}>}
     */
    public function toArray(): array
    {
        $pct = static fn (?Decimal $d): ?string => $d?->mul(Decimal::of('100'))->toFixed(2);
        $atPrice = null;
        if ($this->price !== null && $this->result !== null && $this->fullCost !== null && $this->gapToRecommended !== null) {
            $atPrice = [
                'price' => $this->price->toFixed(2),
                'result' => $this->result->toFixed(2),
                'full_cost' => $this->fullCost->toFixed(2),
                'margin_on_price_pct' => $pct($this->marginOnPrice),
                'markup_on_cost_pct' => $pct($this->markupOnCost),
                'gap_to_recommended_pct' => (string) $pct($this->gapToRecommended),
                'annual_shortfall' => $this->annualShortfall?->toFixed(2),
            ];
        }

        return [
            'total_hours' => $this->totalHours->toFixed(2),
            'time_cost' => $this->timeCost->toFixed(2),
            'direct_costs' => $this->directCosts->toFixed(2),
            'base_cost' => $this->baseCost->toFixed(2),
            'floor_price' => $this->floorPrice->toFixed(2),
            'recommended_price' => $this->recommendedPrice->toFixed(2),
            'at_price' => $atPrice,
            'alerts' => $this->alerts,
        ];
    }
}
