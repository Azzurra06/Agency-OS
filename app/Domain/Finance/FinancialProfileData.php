<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Support\Decimal;

/**
 * Données DÉCLARÉES du profil financier (valeurs en clair, après déchiffrement).
 * NULL = non renseigné (jamais assimilé à 0). Les taux sont des fractions (0,65 = 65 %).
 */
final class FinancialProfileData
{
    public const DEFAULT_ANNUAL_HOURS = '1607';
    public const DEFAULT_BILLABLE_RATE = '0.65';

    /** Champs suivis par le journal des modifications (docs/02-architecture.md §5.2). */
    public const TRACKED_FIELDS = [
        'period_type', 'period_start', 'period_end', 'revenue', 'fixed_costs', 'personnel_costs',
        'variable_costs_attributable', 'variable_costs_general', 'owner_comp_total_cost', 'owner_comp_included_amount',
        'production_fte', 'annual_hours_per_fte', 'billable_rate', 'hourly_cost_override', 'revenue_target',
        'revenue_target_year', 'recurring_share_declared',
    ];

    public function __construct(
        public readonly string $periodType = ReferencePeriod::CLOSED_FISCAL_YEAR,
        public readonly ?string $periodStart = null,
        public readonly ?string $periodEnd = null,
        public readonly ?Decimal $revenue = null,
        public readonly ?Decimal $fixedCosts = null,
        public readonly ?Decimal $personnelCosts = null,
        public readonly ?Decimal $variableCostsAttributable = null,
        public readonly ?Decimal $variableCostsGeneral = null,
        public readonly ?Decimal $ownerCompTotalCost = null,
        public readonly ?Decimal $ownerCompIncludedAmount = null,
        public readonly ?Decimal $productionFte = null,
        public readonly ?Decimal $annualHoursPerFte = null,
        public readonly bool $annualHoursIsAssumption = false,
        public readonly ?Decimal $billableRate = null,
        public readonly bool $billableRateIsAssumption = false,
        public readonly ?Decimal $hourlyCostOverride = null,
        public readonly ?Decimal $revenueTarget = null,
        public readonly ?int $revenueTargetYear = null,
        public readonly ?Decimal $recurringShareDeclared = null,
    ) {
    }

    public function period(): ?ReferencePeriod
    {
        if ($this->periodStart === null || $this->periodEnd === null) {
            return null;
        }

        return ReferencePeriod::fromStrings($this->periodType, $this->periodStart, $this->periodEnd);
    }

    /**
     * Valeurs comparables pour le journal (chaînes ou null).
     *
     * @return array<string, string|null>
     */
    public function trackedValues(): array
    {
        $d = static fn (?Decimal $v): ?string => $v?->toString();

        return [
            'period_type' => $this->periodType,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'revenue' => $d($this->revenue),
            'fixed_costs' => $d($this->fixedCosts),
            'personnel_costs' => $d($this->personnelCosts),
            'variable_costs_attributable' => $d($this->variableCostsAttributable),
            'variable_costs_general' => $d($this->variableCostsGeneral),
            'owner_comp_total_cost' => $d($this->ownerCompTotalCost),
            'owner_comp_included_amount' => $d($this->ownerCompIncludedAmount),
            'production_fte' => $d($this->productionFte),
            'annual_hours_per_fte' => $d($this->annualHoursPerFte),
            'billable_rate' => $d($this->billableRate),
            'hourly_cost_override' => $d($this->hourlyCostOverride),
            'revenue_target' => $d($this->revenueTarget),
            'revenue_target_year' => $this->revenueTargetYear === null ? null : (string) $this->revenueTargetYear,
            'recurring_share_declared' => $d($this->recurringShareDeclared),
        ];
    }
}
