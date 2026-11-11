<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Support\Decimal;

/**
 * Calculs du profil financier (docs/03-formules-et-scoring.md §1 à §3 et §8). Fonctions pures, sans base ni HTTP.
 *
 *   Cd = max(0, RDc − RDi)                     (RDc et RDi sont saisis « par an »)
 *   C  = (CF + CP + CVa + CVg) × 12 ÷ D + Cd    total des charges annualisé
 *   Re = R × 12 ÷ D − C                        résultat estimé
 *   tv = CVg ÷ R                               taux de charges variables générales
 *   S  = (CF + CP) × 12 ÷ D + Cd               coûts de structure à couvrir par les heures
 *   H  = ETP × heures × facturabilité          heures facturables annuelles
 *   CH = S ÷ H                                 coût horaire complet (ou valeur forcée)
 */
final class FinancialCalculator
{
    /** Ce que le résultat estimé inclut et exclut (affiché à l'écran, docs/03 §2.3). */
    public const RESULT_INCLUDES = [
        'CA HT de la période (annualisé)',
        'Charges fixes déclarées',
        'Coût chargé des salariés',
        'Rémunération du dirigeant, cotisations comprises (sans double comptage)',
        'Frais attribuables aux projets et charges variables générales',
    ];

    public const RESULT_EXCLUDES = [
        'TVA',
        'Impôt sur les sociétés ou sur le revenu',
        'Charges et produits financiers et exceptionnels (sauf s\'ils sont saisis dans les charges fixes)',
        'Amortissements (sauf s\'ils sont saisis dans les charges fixes)',
        'Variations d\'encours et décalages de trésorerie',
    ];

    public function annualizedRevenue(FinancialProfileData $p): DerivedValue
    {
        $period = $p->period();
        $missing = $this->missingOf(['period' => $period, 'revenue' => $p->revenue]);
        if ($missing !== [] || $period === null || $p->revenue === null) {
            return DerivedValue::missing($missing);
        }

        return new DerivedValue($period->annualize($p->revenue), $period->isTwelveMonths() ? 'declared' : 'calculated');
    }

    public function ownerComplement(FinancialProfileData $p): DerivedValue
    {
        if ($p->ownerCompTotalCost === null) {
            return DerivedValue::missing(['owner_comp_total_cost']);
        }
        $included = $p->ownerCompIncludedAmount ?? Decimal::zero();

        return new DerivedValue($p->ownerCompTotalCost->sub($included)->max(Decimal::zero()), 'calculated');
    }

    public function totalCosts(FinancialProfileData $p): DerivedValue
    {
        $period = $p->period();
        $complement = $this->ownerComplement($p);
        $missing = $this->missingOf([
            'period' => $period, 'fixed_costs' => $p->fixedCosts, 'personnel_costs' => $p->personnelCosts,
            'variable_costs_attributable' => $p->variableCostsAttributable, 'variable_costs_general' => $p->variableCostsGeneral,
        ]);
        $missing = [...$missing, ...$complement->missing];
        if ($missing !== [] || $period === null || $complement->value === null || $p->fixedCosts === null || $p->personnelCosts === null
            || $p->variableCostsAttributable === null || $p->variableCostsGeneral === null) {
            return DerivedValue::missing($missing);
        }
        $periodCosts = $p->fixedCosts->add($p->personnelCosts)->add($p->variableCostsAttributable)->add($p->variableCostsGeneral);

        return new DerivedValue($period->annualize($periodCosts)->add($complement->value), 'calculated');
    }

    public function estimatedResult(FinancialProfileData $p): DerivedValue
    {
        $revenue = $this->annualizedRevenue($p);
        $costs = $this->totalCosts($p);
        if ($revenue->value === null || $costs->value === null) {
            return DerivedValue::missing([...$revenue->missing, ...$costs->missing]);
        }

        return new DerivedValue($revenue->value->sub($costs->value), 'calculated');
    }

    /** Taux de résultat = Re ÷ CA annualisé (non calculable si le CA est nul). */
    public function resultRate(FinancialProfileData $p): DerivedValue
    {
        $result = $this->estimatedResult($p);
        $revenue = $this->annualizedRevenue($p);
        if ($result->value === null || $revenue->value === null) {
            return DerivedValue::missing([...$result->missing, ...$revenue->missing]);
        }
        if ($revenue->value->isZero()) {
            return DerivedValue::missing(['revenue']);
        }

        return new DerivedValue($result->value->div($revenue->value), 'calculated');
    }

    /** Taux de charges variables générales tv = CVg ÷ R (sans dimension : pas d'annualisation nécessaire). */
    public function generalVariableRate(FinancialProfileData $p): DerivedValue
    {
        if ($p->revenue === null || $p->variableCostsGeneral === null) {
            return DerivedValue::missing($this->missingOf(['revenue' => $p->revenue, 'variable_costs_general' => $p->variableCostsGeneral]));
        }
        if ($p->revenue->isZero()) {
            return DerivedValue::missing(['revenue']);
        }

        return new DerivedValue($p->variableCostsGeneral->div($p->revenue), 'calculated');
    }

    public function billableHours(FinancialProfileData $p): DerivedValue
    {
        if ($p->productionFte === null) {
            return DerivedValue::missing(['production_fte']);
        }
        $assumptions = [];
        $hours = $p->annualHoursPerFte ?? Decimal::of(FinancialProfileData::DEFAULT_ANNUAL_HOURS);
        if ($p->annualHoursPerFte === null || $p->annualHoursIsAssumption) {
            $assumptions[] = 'annual_hours_per_fte';
        }
        $rate = $p->billableRate ?? Decimal::of(FinancialProfileData::DEFAULT_BILLABLE_RATE);
        if ($p->billableRate === null || $p->billableRateIsAssumption) {
            $assumptions[] = 'billable_rate';
        }

        return new DerivedValue($p->productionFte->mul($hours)->mul($rate), 'calculated', $assumptions);
    }

    public function hourlyCost(FinancialProfileData $p): DerivedValue
    {
        if ($p->hourlyCostOverride !== null) {
            return new DerivedValue($p->hourlyCostOverride, 'assumption', ['hourly_cost_override']);
        }
        $period = $p->period();
        $complement = $this->ownerComplement($p);
        $hours = $this->billableHours($p);
        $missing = [...$this->missingOf(['period' => $period, 'fixed_costs' => $p->fixedCosts, 'personnel_costs' => $p->personnelCosts]), ...$complement->missing, ...$hours->missing];
        if ($missing !== [] || $period === null || $complement->value === null || $hours->value === null || $p->fixedCosts === null || $p->personnelCosts === null) {
            return DerivedValue::missing($missing);
        }
        if (!$hours->value->isPositive()) {
            return DerivedValue::missing(['no_billable_hours']);
        }
        $structure = $period->annualize($p->fixedCosts->add($p->personnelCosts))->add($complement->value);

        return new DerivedValue($structure->div($hours->value), 'calculated', $hours->assumptions);
    }

    /** Capacité mensuelle = heures facturables ÷ 12. */
    public function monthlyCapacity(FinancialProfileData $p): DerivedValue
    {
        $hours = $this->billableHours($p);

        return $hours->value === null ? $hours : new DerivedValue($hours->value->div(Decimal::of('12')), 'calculated', $hours->assumptions);
    }

    /**
     * Complétude pondérée sur 100 (docs/03 §8). 0 saisi volontairement compte comme renseigné.
     *
     * @param array{identity_complete: bool, has_priced_service: bool} $context
     * @return array{rate: int, missing: list<string>}
     */
    public function completeness(FinancialProfileData $p, array $context): array
    {
        $criteria = [
            'period' => [10, $p->periodStart !== null && $p->periodEnd !== null],
            'revenue' => [15, $p->revenue !== null],
            'fixed_costs' => [10, $p->fixedCosts !== null],
            'personnel_costs' => [10, $p->personnelCosts !== null],
            'owner_comp_total_cost' => [10, $p->ownerCompTotalCost !== null],
            'production_fte' => [10, $p->productionFte !== null],
            'annual_hours_per_fte' => [5, $p->annualHoursPerFte !== null],
            'billable_rate' => [5, $p->billableRate !== null],
            'variable_costs' => [5, $p->variableCostsAttributable !== null && $p->variableCostsGeneral !== null],
            'revenue_target' => [5, $p->revenueTarget !== null],
            'recurring_share_declared' => [5, $p->recurringShareDeclared !== null],
            'services' => [5, $context['has_priced_service']],
            'identity' => [5, $context['identity_complete']],
        ];
        $rate = 0;
        $missing = [];
        foreach ($criteria as $field => [$weight, $ok]) {
            if ($ok) {
                $rate += $weight;
            } else {
                $missing[] = $field;
            }
        }

        return ['rate' => $rate, 'missing' => $missing];
    }

    /**
     * @param array<string, mixed> $values
     * @return list<string>
     */
    private function missingOf(array $values): array
    {
        return array_keys(array_filter($values, static fn ($v): bool => $v === null));
    }
}
