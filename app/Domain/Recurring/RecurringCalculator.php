<?php

declare(strict_types=1);

namespace App\Domain\Recurring;

use App\Support\Decimal;
use InvalidArgumentException;

/**
 * Revenus récurrents (docs/03-formules-et-scoring.md §5) — fonctions pures, BCMath, valeurs exactes ;
 * l'arrondi n'a lieu qu'en sortie.
 *
 *   pₘ = prix (mensuel) ou prix ÷ 12 (annuel) ; cₘ = hₘ × CH + eₘ + tv × pₘ
 *   résultat = pₘ − cₘ ; marge sur prix de vente = résultat ÷ pₘ ; prix plancher mensuel = (hₘ × CH + eₘ) ÷ (1 − tv)
 *   MRR contractualisé = Σ pₘ × contrats ; ARR = MRR × 12 ; couverture = ARR ÷ CA annualisé
 *   Projection : Nₜ = Nₜ₋₁ × (1 − churn) + nouveaux ; MRRₜ = Σ Nₜ × pₘ ; heuresₜ = Σ Nₜ × hₘ
 */
final class RecurringCalculator
{
    public const CALC_VERSION = '1.0';
    public const GAP_ALERT_POINTS = '10';

    public static function monthlyPrice(Decimal $price, string $billingPeriod): Decimal
    {
        return match ($billingPeriod) {
            'monthly' => $price,
            'annual' => $price->div(Decimal::of('12')),
            default => throw new InvalidArgumentException('Périodicité inconnue.'),
        };
    }

    /**
     * Économie d'un contrat (par mois). Sans coût horaire, seul le prix mensuel est disponible.
     *
     * @return array{monthly_price: Decimal, monthly_cost: Decimal|null, monthly_result: Decimal|null, margin_on_price: Decimal|null, floor_price: Decimal|null}
     */
    public function offer(Decimal $price, string $billingPeriod, Decimal $hoursPerMonth, Decimal $externalCosts, ?Decimal $hourlyCost, Decimal $variableRate): array
    {
        $pm = self::monthlyPrice($price, $billingPeriod);
        if ($hourlyCost === null) {
            return ['monthly_price' => $pm, 'monthly_cost' => null, 'monthly_result' => null, 'margin_on_price' => null, 'floor_price' => null];
        }
        $direct = $hoursPerMonth->mul($hourlyCost)->add($externalCosts);
        $cost = $direct->add($variableRate->mul($pm));
        $result = $pm->sub($cost);

        return [
            'monthly_price' => $pm,
            'monthly_cost' => $cost,
            'monthly_result' => $result,
            'margin_on_price' => $pm->isPositive() ? $result->div($pm) : null,
            'floor_price' => $direct->div(Decimal::of('1')->sub($variableRate)),
        ];
    }

    /**
     * MRR et ARR contractualisés (contrats réellement signés), couverture du CA et heures nécessaires.
     *
     * @param list<array{monthly_price: Decimal, contracts: int, hours_per_month: Decimal}> $offers
     * @return array{mrr: Decimal, arr: Decimal, coverage: Decimal|null, gap_points: Decimal|null, gap_alert: bool, hours: Decimal, capacity_use: Decimal|null}
     */
    public function contracted(array $offers, ?Decimal $annualizedRevenue, ?Decimal $declaredShare, ?Decimal $monthlyCapacity): array
    {
        $mrr = Decimal::zero();
        $hours = Decimal::zero();
        foreach ($offers as $o) {
            $mrr = $mrr->add($o['monthly_price']->mul(Decimal::of($o['contracts'])));
            $hours = $hours->add($o['hours_per_month']->mul(Decimal::of($o['contracts'])));
        }
        $arr = $mrr->mul(Decimal::of('12'));
        $coverage = $annualizedRevenue !== null && $annualizedRevenue->isPositive() ? $arr->div($annualizedRevenue) : null;
        $gap = null;
        if ($coverage !== null && $declaredShare !== null) {
            $diff = $coverage->sub($declaredShare)->mul(Decimal::of('100'));
            $gap = $diff->isNegative() ? $diff->negate() : $diff;
        }

        return [
            'mrr' => $mrr,
            'arr' => $arr,
            'coverage' => $coverage,
            'gap_points' => $gap,
            'gap_alert' => $gap !== null && $gap->compare(Decimal::of(self::GAP_ALERT_POINTS)) > 0,
            'hours' => $hours,
            'capacity_use' => $monthlyCapacity !== null && $monthlyCapacity->isPositive() ? $hours->div($monthlyCapacity) : null,
        ];
    }

    /**
     * Projection mois par mois (valeurs exactes ; les clients restent décimaux).
     *
     * @param array<string, array{monthly_price: Decimal, hours_per_month: Decimal, starting: Decimal, new_per_month: Decimal, churn: Decimal}> $entries
     * @return array{months: list<array{month: int, clients: array<string, Decimal>, total_clients: Decimal, mrr: Decimal, hours: Decimal, capacity_use: Decimal|null}>, cumulative: Decimal, cumulative_6: Decimal}
     */
    public function project(array $entries, int $horizon, ?Decimal $monthlyCapacity): array
    {
        if (!in_array($horizon, [6, 12], true)) {
            throw new InvalidArgumentException('Horizon de 6 ou 12 mois.');
        }
        $one = Decimal::of('1');
        $clients = array_map(static fn (array $e): Decimal => $e['starting'], $entries);
        $months = [];
        $cumulative = Decimal::zero();
        $cumulative6 = Decimal::zero();
        for ($t = 1; $t <= $horizon; $t++) {
            $mrr = Decimal::zero();
            $hours = Decimal::zero();
            $total = Decimal::zero();
            foreach ($entries as $key => $e) {
                $clients[$key] = $clients[$key]->mul($one->sub($e['churn']))->add($e['new_per_month']);
                $mrr = $mrr->add($clients[$key]->mul($e['monthly_price']));
                $hours = $hours->add($clients[$key]->mul($e['hours_per_month']));
                $total = $total->add($clients[$key]);
            }
            $cumulative = $cumulative->add($mrr);
            if ($t <= 6) {
                $cumulative6 = $cumulative6->add($mrr);
            }
            $months[] = [
                'month' => $t, 'clients' => $clients, 'total_clients' => $total, 'mrr' => $mrr, 'hours' => $hours,
                'capacity_use' => $monthlyCapacity !== null && $monthlyCapacity->isPositive() ? $hours->div($monthlyCapacity) : null,
            ];
        }

        return ['months' => $months, 'cumulative' => $cumulative, 'cumulative_6' => $cumulative6];
    }
}
