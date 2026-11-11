<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Support\Decimal;

/**
 * Pricing d'une prestation (docs/03-formules-et-scoring.md §4.2) — fonctions pures, BCMath, multiplier avant de diviser.
 *
 *   T = Σ h × (1 + a) ; C₀ = T × CH + Σ f ; CR(P) = C₀ + tv × P
 *   Prix plancher Pp = C₀ ÷ (1 − tv) ; prix recommandé Pr = C₀ ÷ (1 − m − tv), avec m + tv < 1
 *   Res(P) = P × (1 − tv) − C₀ ; marge sur prix de vente = Res ÷ P ; majoration sur coût = Res ÷ CR(P)
 *   Écart = (P − Pr) ÷ Pr ; manque à gagner annuel = (Pr − P) × volume, si P < Pr et volume déclaré
 */
final class PricingCalculator
{
    public const CALC_VERSION = '1.0';
    public const MAX_MARGIN = '0.90';
    public const MAX_CONTINGENCY = '1';

    public function calculate(ScenarioInput $in): PricingResult
    {
        $one = Decimal::of('1');
        if ($in->targetMargin->isNegative() || $in->targetMargin->compare(Decimal::of(self::MAX_MARGIN)) > 0) {
            throw new PricingException('invalid_target_margin');
        }
        if ($in->contingency->isNegative() || $in->contingency->compare(Decimal::of(self::MAX_CONTINGENCY)) > 0) {
            throw new PricingException('invalid_contingency');
        }
        if ($in->variableRate->isNegative() || $in->variableRate->compare($one) >= 0) {
            throw new PricingException('invalid_variable_rate');
        }
        if (!$in->hourlyCost->isPositive()) {
            throw new PricingException('hourly_cost_unavailable');
        }
        if ($in->targetMargin->add($in->variableRate)->compare($one) >= 0) {
            throw new PricingException('margin_plus_variable_rate_too_high');
        }

        $rawHours = array_reduce($in->hours, static fn (Decimal $c, Decimal $h): Decimal => $c->add($h), Decimal::zero());
        $direct = array_reduce($in->directCosts, static fn (Decimal $c, Decimal $f): Decimal => $c->add($f), Decimal::zero());
        $totalHours = $rawHours->mul($one->add($in->contingency));
        $timeCost = $totalHours->mul($in->hourlyCost);
        $base = $timeCost->add($direct);
        if (!$base->isPositive()) {
            throw new PricingException('empty_scenario');
        }

        $floor = $base->div($one->sub($in->variableRate));
        $recommended = $base->div($one->sub($in->targetMargin)->sub($in->variableRate));

        $alerts = [];
        if ($in->variableRate->isZero()) {
            $alerts[] = ['code' => 'variable_rate_zero', 'level' => 'info'];
        }
        $result = $fullCost = $margin = $markup = $gap = $shortfall = null;
        $price = $in->price;
        if ($price !== null && $price->isPositive()) {
            $result = $price->mul($one->sub($in->variableRate))->sub($base);
            $fullCost = $base->add($in->variableRate->mul($price));
            $margin = $result->div($price);
            $markup = $fullCost->isPositive() ? $result->div($fullCost) : null;
            $gap = $price->sub($recommended)->div($recommended);
            if ($price->compare($recommended) < 0 && $in->annualVolume !== null && $in->annualVolume > 0) {
                $shortfall = $recommended->sub($price)->mul(Decimal::of($in->annualVolume));
            }
            // Seuils évalués sur les valeurs exactes.
            if ($price->compare($floor) < 0) {
                $alerts[] = ['code' => 'price_below_floor', 'level' => 'danger'];
            } elseif ($price->compare($recommended) < 0) {
                $alerts[] = ['code' => 'price_below_recommended', 'level' => 'warning'];
            }
        } else {
            $price = null;
        }

        return new PricingResult($totalHours, $timeCost, $direct, $base, $floor, $recommended, $price, $result, $fullCost, $margin, $markup, $gap, $shortfall, $alerts);
    }

    /**
     * Arrondi commercial proposé À CÔTÉ du prix recommandé (jamais à sa place) : multiple supérieur du pas choisi.
     */
    public static function commercialRounding(Decimal $price, string $step): ?Decimal
    {
        if ($step === 'none' || !in_array($step, ['1', '10', '50', '100'], true)) {
            return null;
        }
        $s = Decimal::of($step);
        $units = Decimal::of(explode('.', $price->div($s)->toString())[0]);
        $candidate = $units->mul($s);

        return $candidate->compare($price) >= 0 ? $candidate : $candidate->add($s);
    }
}
