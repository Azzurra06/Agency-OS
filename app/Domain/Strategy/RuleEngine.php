<?php

declare(strict_types=1);

namespace App\Domain\Strategy;

use App\Support\Decimal;

/**
 * Moteur de règles du MVP (docs/03-formules-et-scoring.md §7, docs/05 §2) — déterministe, sans effet de bord.
 *
 * Priorité : base(sévérité) + bonus de dimension (seulement si la dimension est CALCULABLE dans le dernier diagnostic),
 * plafonnée à 100. Une donnée ancienne ou partielle n'augmente jamais la priorité. Les seuils sont évalués sur les
 * valeurs exactes ; les valeurs exactes et les seuils sont conservés dans la preuve (evidence).
 */
final class RuleEngine
{
    public const RULE_VERSION = 1;
    public const BASE = ['info' => 20, 'medium' => 45, 'high' => 70, 'critical' => 90];
    public const SEVERITY_RANK = ['info' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3];
    /** Dimension du diagnostic liée à chaque règle (D1 pos … D6 str). */
    private const DIMENSIONS = ['D1' => 'pos', 'D2' => 'acq', 'D3' => 'fin', 'D4' => 'org', 'D5' => 'ret', 'D6' => 'str'];

    /** @return list<Finding> */
    public function evaluate(Facts $f): array
    {
        $findings = [];
        $profile = $this->profileSource($f);
        $recurring = $this->recurringSource($f);
        $d = static fn (string $v): Decimal => Decimal::of($v);

        if ($f->estimatedResult !== null && $f->estimatedResult->isNegative()) {
            $findings[] = $this->finding($f, 'RESULT_NEGATIVE', 'org', 'critical', 'D3', [$profile],
                ['estimated_result' => $f->estimatedResult->toFixed(2)], ['result_min' => '0.00'], null);
        }

        $servicesWithoutPricing = [];
        foreach ($f->services as $service) {
            $sim = $service['simulation'];
            if ($sim === null) {
                $servicesWithoutPricing[] = $service['name'];
                continue;
            }
            $simSource = ['type' => 'pricing_simulation', 'ref' => $sim['public_id'], 'label' => $service['name'], 'version' => $sim['version'], 'nature' => 'frozen',
                'as_of' => substr($sim['finalized_at'], 0, 10), 'drift' => $sim['drift'],
                'freshness' => $sim['drift'] || $this->olderThan($sim['finalized_at'], $f->today, '-12 months') ? 'stale' : 'fresh'];
            $catalog = ['type' => 'catalog', 'label' => $service['name'], 'nature' => 'declared', 'freshness' => 'fresh'];
            $subject = 'service:' . $service['public_id'];
            if ($sim['drift']) {
                $findings[] = $this->finding($f, 'PRICING_DRIFT', $subject, 'info', 'D3', [$simSource], ['service' => $service['name']], ['drift_rate' => '5.00'], null, $service['name']);
            }
            $price = $service['price'];
            if ($price === null || !$price->isPositive()) {
                continue; // prix actuel absent : règle non évaluable (aucune recommandation sur une donnée absente)
            }
            $gap = $price->sub($sim['recommended'])->div($sim['recommended']);
            $missing = $service['volume'] === null ? ['annual_volume'] : [];
            $values = ['service' => $service['name'], 'current_price' => $price->toFixed(2), 'floor_price' => $sim['floor']->toFixed(2),
                'recommended_price' => $sim['recommended']->toFixed(2), 'price_gap_rate' => $gap->mul($d('100'))->toFixed(4), 'annual_volume' => $service['volume']];
            if ($price->compare($sim['floor']) < 0) {
                // Perte par vente = Pp − P après charges variables : (1 − tv) × (Pp − P).
                $loss = $sim['floor']->sub($price)->mul($d('1')->sub($sim['variable_rate']));
                $impact = $service['volume'] === null ? null : $loss->mul(Decimal::of($service['volume']));
                $findings[] = $this->finding($f, 'PRICE_BELOW_FLOOR', $subject, 'critical', 'D3', [$simSource, $catalog],
                    $values + ['loss_per_sale' => $loss->toFixed(2)], ['floor_price' => $sim['floor']->toFixed(2)], $impact, $service['name'], $missing);
            } elseif ($gap->compare($d('-0.10')) <= 0) {
                $severity = $gap->compare($d('-0.20')) <= 0 ? 'high' : 'medium';
                $impact = $service['volume'] === null ? null : $sim['recommended']->sub($price)->mul(Decimal::of($service['volume']));
                $findings[] = $this->finding($f, 'PRICE_UNDER', $subject, $severity, 'D3', [$simSource, $catalog],
                    $values, ['price_gap_medium' => '-10.00', 'price_gap_high' => '-20.00'], $impact, $service['name'], $missing);
            }
        }
        if ($servicesWithoutPricing !== []) {
            $findings[] = $this->finding($f, 'NO_PRICING', 'org', 'info', 'D3', [],
                ['services' => implode(', ', $servicesWithoutPricing), 'count' => count($servicesWithoutPricing)], [], null);
        }

        foreach ($f->offers as $offer) {
            if ($offer['monthly_result'] === null) {
                continue; // coût horaire non calculable : NO_HOURLY_COST prend le relais
            }
            $subject = 'offer:' . $offer['public_id'];
            $values = ['offer' => $offer['name'], 'monthly_result' => $offer['monthly_result']->toFixed(2), 'contracts' => $offer['contracts'],
                'margin_rate' => $offer['margin']?->mul($d('100'))->toFixed(4)];
            if ($offer['monthly_result']->isNegative()) {
                $impact = $offer['monthly_result']->negate()->mul(Decimal::of($offer['contracts']));
                $findings[] = $this->finding($f, 'MAINT_UNPROFITABLE', $subject, 'high', 'D5', [$profile, $recurring], $values, ['monthly_result_min' => '0.00'],
                    $offer['contracts'] > 0 ? $impact : null, $offer['name']);
            } elseif ($offer['margin'] !== null && $offer['margin']->compare($d('0.20')) < 0) {
                $findings[] = $this->finding($f, 'MAINT_LOW_MARGIN', $subject, 'medium', 'D5', [$profile, $recurring], $values, ['margin_min' => '20.00'], null, $offer['name']);
            }
        }

        if ($f->declaredShare !== null && $f->declaredShare->compare($d('0.20')) < 0) {
            $findings[] = $this->finding($f, 'RECURRING_SHARE_LOW', 'org', 'high', 'D5', [$profile],
                ['declared_share' => $f->declaredShare->mul($d('100'))->toFixed(2)], ['share_min' => '20.00'], null);
        }
        if ($f->offers === []) {
            $findings[] = $this->finding($f, 'NO_RECURRING_OFFER', 'org', 'high', 'D5', [], [], [], null);
        }
        if ($f->coverageGapPoints !== null && $f->coverageGapPoints->compare($d('10')) > 0) {
            $findings[] = $this->finding($f, 'RECURRING_DATA_GAP', 'org', 'info', null, [$profile, $recurring], [
                'coverage' => $f->coverage?->mul($d('100'))->toFixed(2), 'declared_share' => $f->declaredShare?->mul($d('100'))->toFixed(2),
                'gap_points' => $f->coverageGapPoints->toFixed(2),
            ], ['gap_max_points' => '10.00'], null);
        }
        if ($f->hourlyCost === null) {
            $findings[] = $this->finding($f, 'NO_HOURLY_COST', 'org', 'high', 'D3', [$profile], [], [], null);
        } elseif ($f->hourlyCostAssumptions !== []) {
            $findings[] = $this->finding($f, 'HOURLY_ASSUMPTIONS', 'org', 'info', 'D3', [$profile],
                ['hourly_cost' => $f->hourlyCost->toFixed(2), 'assumptions' => implode(',', $f->hourlyCostAssumptions)], [], null);
        }
        if ($f->revenueTarget !== null && $f->revenueAnnualized !== null && $f->revenueTarget->compare($f->revenueAnnualized->mul($d('1.3'))) > 0) {
            $findings[] = $this->finding($f, 'TARGET_GAP', 'org', 'medium', 'D6', [$profile], [
                'revenue_target' => $f->revenueTarget->toFixed(2), 'revenue_annualized' => $f->revenueAnnualized->toFixed(2),
                'growth_needed' => $f->revenueTarget->div($f->revenueAnnualized)->sub($d('1'))->mul($d('100'))->toFixed(2),
            ], ['growth_max' => '30.00'], $f->revenueTarget->sub($f->revenueAnnualized));
        }
        if ($f->monthlyCapacity !== null && $f->monthlyCapacity->isPositive()
            && $f->recurringHours->compare($f->monthlyCapacity->mul($d('0.30'))) > 0) {
            $findings[] = $this->finding($f, 'CAPACITY_RECURRING_HIGH', 'org', 'medium', 'D4', [$profile, $recurring], [
                'recurring_hours' => $f->recurringHours->toFixed(2), 'monthly_capacity' => $f->monthlyCapacity->toFixed(2),
                'capacity_use' => $f->recurringHours->div($f->monthlyCapacity)->mul($d('100'))->toFixed(2),
            ], ['capacity_max' => '30.00'], null);
        }

        $diag = $f->diagnostic;
        if ($diag === null) {
            $findings[] = $this->finding($f, 'NO_DIAGNOSTIC', 'org', 'info', null, [], [], [], null);
        } else {
            $diagSource = $this->diagnosticSource($f);
            foreach (self::DIMENSIONS as $dx => $code) {
                $dim = $diag['dimensions'][$code] ?? null;
                if ($dim === null || !$dim['scorable'] || $dim['score'] === null || $dim['score']->compare($d('40')) >= 0) {
                    continue; // une dimension non calculable n'est jamais un axe d'amélioration
                }
                $severity = $dim['score']->compare($d('25')) < 0 ? 'high' : 'medium';
                $findings[] = $this->finding($f, 'DIM_LOW_' . $dx, 'dimension:' . $code, $severity, $dx, [$diagSource],
                    ['dimension' => $dim['label'], 'score' => $dim['score']->toFixed(2)], ['score_min' => '40.00', 'score_high' => '25.00'], null, $dim['label']);
            }
            $unscorable = array_keys(array_filter($diag['dimensions'], static fn (array $x): bool => !$x['scorable']));
            if ($unscorable !== [] || $diag['reliability']->compare($d('0.75')) < 0) {
                $findings[] = $this->finding($f, 'DIAG_LOW_RELIABILITY', 'org', 'info', null, [$diagSource],
                    ['reliability' => $diag['reliability']->mul($d('100'))->toFixed(2), 'unscorable' => implode(',', $unscorable)], ['reliability_min' => '75.00'], null);
            }
        }
        if ($f->profileCompleteness < 60) {
            $findings[] = $this->finding($f, 'PROFILE_INCOMPLETE', 'org', 'info', null, [$profile], ['completeness' => $f->profileCompleteness], ['completeness_min' => '60'], null);
        }

        usort($findings, static fn (Finding $a, Finding $b): int => $b->priority <=> $a->priority ?: strcmp($a->ruleCode . $a->subjectKey, $b->ruleCode . $b->subjectKey));

        return $findings;
    }

    /** Bonus de dimension : arrondi(10 × (1 − Sd ÷ 100)), seulement si la dimension est calculable. */
    public static function priority(string $severity, ?Decimal $dimensionScore): int
    {
        $bonus = $dimensionScore === null ? 0 : (int) Decimal::of('10')->mul(Decimal::of('1')->sub($dimensionScore->div(Decimal::of('100'))))->round(0)->toFixed(0);

        return min(100, self::BASE[$severity] + max(0, $bonus));
    }

    /**
     * @param list<array<string, mixed>> $sources
     * @param array<string, string|int|null> $values
     * @param array<string, string> $thresholds
     * @param list<string> $missing
     */
    private function finding(Facts $f, string $code, string $subject, string $severity, ?string $dimension, array $sources, array $values, array $thresholds, ?Decimal $impact, ?string $label = null, array $missing = []): Finding
    {
        $dimScore = null;
        $dimCode = $dimension === null ? null : self::DIMENSIONS[$dimension];
        if ($dimCode !== null && $f->diagnostic !== null) {
            $dim = $f->diagnostic['dimensions'][$dimCode] ?? null;
            if ($dim !== null && $dim['scorable']) {
                $dimScore = $dim['score'];
                $merged = false;
                foreach ($sources as $i => $source) {
                    if (($source['type'] ?? null) === 'diagnostic') {
                        $sources[$i]['dimensions_used'] = [$dimCode];
                        $merged = true;
                    }
                }
                if (!$merged) {
                    $sources[] = $this->diagnosticSource($f) + ['dimensions_used' => [$dimCode]];
                }
            }
        }
        $stale = [];
        foreach ($sources as $source) {
            if (($source['freshness'] ?? 'fresh') === 'stale') {
                $stale[] = is_string($source['type'] ?? null) ? $source['type'] : 'unknown';
            }
            if (($source['type'] ?? null) === 'diagnostic' && ($source['status'] ?? null) === 'partial') {
                $missing[] = 'diagnostic_partial';
            }
        }
        $missing = array_values(array_unique($missing));
        $stale = array_values(array_unique($stale));
        $quality = match (true) {
            $missing !== [] && $stale !== [] => 'partial_stale',
            $missing !== [] => 'partial',
            $stale !== [] => 'stale',
            default => 'ok',
        };
        $evidence = ['sources' => array_values(array_unique($sources, SORT_REGULAR)), 'values' => $values, 'thresholds' => $thresholds, 'missing' => $missing, 'stale' => $stale];
        if ($label !== null) {
            $evidence['subject'] = $label;
        }

        return new Finding($code, $subject, $severity, $dimension, self::priority($severity, $dimScore), $evidence, $impact, $quality);
    }

    /** @return array<string, mixed> */
    private function profileSource(Facts $f): array
    {
        $stale = ($f->periodEnd !== null && $this->olderThan($f->periodEnd, $f->today, '-15 months'))
            || ($f->profileUpdatedAt !== null && $this->olderThan($f->profileUpdatedAt, $f->today, '-12 months'));

        return ['type' => 'financial_profile', 'nature' => 'declared', 'as_of' => $f->profileUpdatedAt === null ? null : substr($f->profileUpdatedAt, 0, 10),
            'period' => $f->periodLabel, 'freshness' => $stale ? 'stale' : 'fresh'];
    }

    /** @return array<string, mixed> */
    private function recurringSource(Facts $f): array
    {
        $stale = $f->contractsUpdatedAt !== null && $this->olderThan($f->contractsUpdatedAt, $f->today, '-6 months');

        return ['type' => 'recurring_contracts', 'nature' => 'declared', 'as_of' => $f->contractsUpdatedAt === null ? null : substr($f->contractsUpdatedAt, 0, 10),
            'freshness' => $stale ? 'stale' : 'fresh'];
    }

    /** @return array<string, mixed> */
    private function diagnosticSource(Facts $f): array
    {
        $diag = $f->diagnostic;
        if ($diag === null) {
            return [];
        }

        return ['type' => 'diagnostic', 'ref' => $diag['public_id'], 'nature' => 'frozen', 'as_of' => substr($diag['completed_at'], 0, 10),
            'status' => $diag['completeness'], 'freshness' => $this->olderThan($diag['completed_at'], $f->today, '-12 months') ? 'stale' : 'fresh'];
    }

    private function olderThan(string $date, \DateTimeImmutable $today, string $modifier): bool
    {
        return substr($date, 0, 10) < $today->modify($modifier)->format('Y-m-d');
    }
}
