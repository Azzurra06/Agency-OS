<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Finance\FinancialCalculator;
use App\Domain\Finance\FinancialProfileData;
use App\Domain\Finance\ReferencePeriod;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\OrgId;
use App\Infrastructure\Repositories\FinancialProfileRepository;
use App\Infrastructure\Repositories\OrganizationRepository;
use App\Infrastructure\Repositories\ServiceRepository;
use App\Support\Decimal;
use App\Support\Validator;
use InvalidArgumentException;

/**
 * Profil financier : validation (liste blanche, décimaux en chaînes, pourcentages convertis par le serveur),
 * enregistrement, journal des modifications importantes et valeurs dérivées avec leur nature.
 */
final class FinancialProfileService
{
    private const MONEY = ['nullable', 'decimal', 'min:0', 'max:99999999.99'];

    /** @var array<string, list<string>> */
    public const RULES = [
        'period_type' => ['enum:closed_fiscal_year,trailing_12_months'],
        'period_start' => ['nullable', 'date'],
        'period_end' => ['nullable', 'date'],
        'revenue' => self::MONEY,
        'fixed_costs' => self::MONEY,
        'personnel_costs' => self::MONEY,
        'variable_costs_attributable' => self::MONEY,
        'variable_costs_general' => self::MONEY,
        'owner_comp_total_cost' => self::MONEY,
        'owner_comp_included_amount' => self::MONEY,
        'production_fte' => ['nullable', 'decimal', 'min:0', 'max:500'],
        'annual_hours_per_fte' => ['nullable', 'decimal', 'min:100', 'max:3000'],
        'annual_hours_is_assumption' => ['bool'],
        'billable_rate' => ['nullable', 'percent', 'min:1', 'max:100'],
        'billable_rate_is_assumption' => ['bool'],
        'hourly_cost_override' => ['nullable', 'decimal', 'min:1', 'max:10000'],
        'revenue_target' => self::MONEY,
        'revenue_target_year' => ['nullable', 'int', 'min:2000', 'max:2100'],
        'recurring_share_declared' => ['nullable', 'percent', 'min:0', 'max:100'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly FinancialProfileRepository $profiles,
        private readonly OrganizationRepository $organizations,
        private readonly ServiceRepository $services,
        private readonly FinancialCalculator $calculator = new FinancialCalculator(),
    ) {
    }

    public function get(OrgId $org): FinancialProfileData
    {
        return $this->profiles->find($org) ?? new FinancialProfileData();
    }

    /**
     * Met à jour les champs fournis (les autres sont conservés) et journalise les changements réels.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function update(OrgId $org, array $input, ?int $userId, string $origin = 'settings'): FinancialProfileData
    {
        $validator = Validator::make($input, self::RULES);
        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }
        $v = $validator->validated();
        $current = $this->get($org);
        $pick = static fn (string $key, mixed $fallback): mixed => array_key_exists($key, $v) ? $v[$key] : $fallback;
        $decimal = static fn (string $key, ?Decimal $fallback): ?Decimal => array_key_exists($key, $v) ? self::asDecimal($v[$key]) : $fallback;

        $next = new FinancialProfileData(
            periodType: self::asString($pick('period_type', null)) ?? $current->periodType,
            periodStart: self::asString($pick('period_start', $current->periodStart)),
            periodEnd: self::asString($pick('period_end', $current->periodEnd)),
            revenue: $decimal('revenue', $current->revenue),
            fixedCosts: $decimal('fixed_costs', $current->fixedCosts),
            personnelCosts: $decimal('personnel_costs', $current->personnelCosts),
            variableCostsAttributable: $decimal('variable_costs_attributable', $current->variableCostsAttributable),
            variableCostsGeneral: $decimal('variable_costs_general', $current->variableCostsGeneral),
            ownerCompTotalCost: $decimal('owner_comp_total_cost', $current->ownerCompTotalCost),
            ownerCompIncludedAmount: $decimal('owner_comp_included_amount', $current->ownerCompIncludedAmount),
            productionFte: $decimal('production_fte', $current->productionFte),
            annualHoursPerFte: $decimal('annual_hours_per_fte', $current->annualHoursPerFte),
            annualHoursIsAssumption: (bool) $pick('annual_hours_is_assumption', $current->annualHoursIsAssumption),
            billableRate: $decimal('billable_rate', $current->billableRate),
            billableRateIsAssumption: (bool) $pick('billable_rate_is_assumption', $current->billableRateIsAssumption),
            hourlyCostOverride: $decimal('hourly_cost_override', $current->hourlyCostOverride),
            revenueTarget: $decimal('revenue_target', $current->revenueTarget),
            revenueTargetYear: self::asInt($pick('revenue_target_year', $current->revenueTargetYear)),
            recurringShareDeclared: $decimal('recurring_share_declared', $current->recurringShareDeclared),
        );
        $this->assertCoherent($next);

        $before = $current->trackedValues();
        $after = $next->trackedValues();
        $changes = [];
        foreach (FinancialProfileData::TRACKED_FIELDS as $field) {
            if (!self::sameValue($before[$field] ?? null, $after[$field] ?? null)) {
                $changes[$field] = [$before[$field] ?? null, $after[$field] ?? null];
            }
        }
        $this->db->transaction(function () use ($org, $next, $userId, $origin, $changes): void {
            $this->profiles->save($org, $next, $userId);
            if ($changes !== []) {
                $this->profiles->appendChanges($org, $origin, $userId, $changes);
            }
        });

        return $next;
    }

    /**
     * Valeurs dérivées au format de l'API (docs/05 §1.3).
     *
     * @return array<string, mixed>
     */
    public function derived(OrgId $org): array
    {
        $p = $this->get($org);
        $period = null;
        try {
            $period = $p->period();
        } catch (InvalidArgumentException) {
        }
        $identity = $this->organizations->get($org);

        return [
            'period' => $period === null ? null : [
                'type' => $period->type, 'start' => $period->start->format('Y-m-d'), 'end' => $period->end->format('Y-m-d'),
                'months' => $period->months->toString(), 'label' => $period->label(),
            ],
            'revenue_annualized' => $this->calculator->annualizedRevenue($p)->toArray(),
            'total_costs' => $this->calculator->totalCosts($p)->toArray(),
            'owner_complement' => $this->calculator->ownerComplement($p)->toArray(),
            'estimated_result' => $this->calculator->estimatedResult($p)->toArray() + [
                'rate_pct' => $this->calculator->resultRate($p)->toArray('percent')['value'],
                'includes' => FinancialCalculator::RESULT_INCLUDES,
                'excludes' => FinancialCalculator::RESULT_EXCLUDES,
            ],
            'general_variable_rate' => $this->calculator->generalVariableRate($p)->toArray('percent'),
            'billable_hours' => $this->calculator->billableHours($p)->toArray(),
            'hourly_cost' => $this->calculator->hourlyCost($p)->toArray(),
            'monthly_capacity' => $this->calculator->monthlyCapacity($p)->toArray(),
            'revenue_target' => $this->targetProgress($p),
            'recurring_share_declared' => $p->recurringShareDeclared === null ? null : [
                'value' => $p->recurringShareDeclared->mul(Decimal::of('100'))->toFixed(2), 'nature' => 'declared',
            ],
            'completeness' => $this->calculator->completeness($p, [
                'identity_complete' => $identity['activity_type'] !== null && $identity['team_size'] !== null && $identity['legal_status'] !== null,
                'has_priced_service' => $this->services->hasPricedService($org),
            ]),
        ];
    }

    /**
     * Données saisies, sérialisées pour les formulaires (taux en pourcentage, décimaux en chaînes).
     *
     * @return array<string, string|bool|int|null>
     */
    public function formValues(OrgId $org): array
    {
        $p = $this->get($org);
        $money = static fn (?Decimal $d): ?string => $d?->toFixed(2);
        $pct = static fn (?Decimal $d): ?string => $d?->mul(Decimal::of('100'))->toFixed(2);

        return [
            'period_type' => $p->periodType, 'period_start' => $p->periodStart, 'period_end' => $p->periodEnd,
            'revenue' => $money($p->revenue), 'fixed_costs' => $money($p->fixedCosts), 'personnel_costs' => $money($p->personnelCosts),
            'variable_costs_attributable' => $money($p->variableCostsAttributable), 'variable_costs_general' => $money($p->variableCostsGeneral),
            'owner_comp_total_cost' => $money($p->ownerCompTotalCost), 'owner_comp_included_amount' => $money($p->ownerCompIncludedAmount),
            'production_fte' => $money($p->productionFte), 'annual_hours_per_fte' => $money($p->annualHoursPerFte),
            'annual_hours_is_assumption' => $p->annualHoursIsAssumption, 'billable_rate' => $pct($p->billableRate),
            'billable_rate_is_assumption' => $p->billableRateIsAssumption, 'hourly_cost_override' => $money($p->hourlyCostOverride),
            'revenue_target' => $money($p->revenueTarget), 'revenue_target_year' => $p->revenueTargetYear,
            'recurring_share_declared' => $pct($p->recurringShareDeclared),
        ];
    }

    /**
     * Objectif déclaré comparé au CA de référence annualisé : progression (plafonnée à 100 %) et écart restant.
     *
     * @return array{value: string, year: int|null, nature: string, progress_pct: string|null, gap: string|null}|null
     */
    private function targetProgress(FinancialProfileData $p): ?array
    {
        if ($p->revenueTarget === null) {
            return null;
        }
        $revenue = $this->calculator->annualizedRevenue($p)->value;
        $progress = null;
        $gap = null;
        if ($revenue !== null && $p->revenueTarget->isPositive()) {
            $progress = $revenue->div($p->revenueTarget)->mul(Decimal::of('100'))->min(Decimal::of('100'))->toFixed(0);
            $gap = $p->revenueTarget->sub($revenue)->max(Decimal::zero())->toFixed(2);
        }

        return ['value' => $p->revenueTarget->toFixed(2), 'year' => $p->revenueTargetYear, 'nature' => 'declared', 'progress_pct' => $progress, 'gap' => $gap];
    }

    private function assertCoherent(FinancialProfileData $p): void
    {
        $errors = [];
        if (($p->periodStart === null) !== ($p->periodEnd === null)) {
            $errors[$p->periodStart === null ? 'period_start' : 'period_end'] = 'required';
        }
        if ($p->periodStart !== null && $p->periodEnd !== null) {
            try {
                $p->period();
            } catch (InvalidArgumentException) {
                $errors['period_end'] = 'invalid_period';
            }
        }
        if ($p->ownerCompIncludedAmount !== null && $p->ownerCompTotalCost !== null
            && $p->ownerCompIncludedAmount->compare($p->ownerCompTotalCost) > 0) {
            $errors['owner_comp_included_amount'] = 'exceeds_owner_total';
        }
        if ($p->ownerCompIncludedAmount !== null && $p->fixedCosts !== null && $p->personnelCosts !== null
            && $p->ownerCompIncludedAmount->compare($p->fixedCosts->add($p->personnelCosts)) > 0) {
            $errors['owner_comp_included_amount'] = 'exceeds_declared_costs';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    private static function sameValue(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return Decimal::of($a)->equals(Decimal::of($b));
        }

        return $a === $b;
    }

    private static function asDecimal(mixed $value): ?Decimal
    {
        return $value instanceof Decimal ? $value : null;
    }

    private static function asString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function asInt(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
