<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Finance\FinancialCalculator;
use App\Domain\Finance\FinancialProfileData;
use App\Domain\Finance\ReferencePeriod;
use App\Support\Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Cas de référence de docs/03-formules-et-scoring.md §1 à §3 et §8.
 */
final class FinancialCalculatorTest extends TestCase
{
    private FinancialCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new FinancialCalculator();
    }

    /** Profil de RES-1 et HC-1 (agence fictive « Lumen »). */
    private static function lumen(array $overrides = []): FinancialProfileData
    {
        $d = static fn (?string $v): ?Decimal => $v === null ? null : Decimal::of($v);
        $v = $overrides + [
            'periodStart' => '2025-01-01', 'periodEnd' => '2025-12-31', 'revenue' => '180000', 'fixedCosts' => '30000',
            'personnelCosts' => '60000', 'cva' => '12000', 'cvg' => '3600', 'ownerTotal' => '50000', 'ownerIncluded' => '0',
            'fte' => '2', 'hours' => null, 'rate' => null, 'override' => null,
        ];

        return new FinancialProfileData(
            periodStart: $v['periodStart'], periodEnd: $v['periodEnd'], revenue: $d($v['revenue']), fixedCosts: $d($v['fixedCosts']),
            personnelCosts: $d($v['personnelCosts']), variableCostsAttributable: $d($v['cva']), variableCostsGeneral: $d($v['cvg']),
            ownerCompTotalCost: $d($v['ownerTotal']), ownerCompIncludedAmount: $d($v['ownerIncluded']), productionFte: $d($v['fte']),
            annualHoursPerFte: $d($v['hours']), billableRate: $d($v['rate']), hourlyCostOverride: $d($v['override']),
        );
    }

    public function testPer1EighteenMonthFiscalYearIsAnnualised(): void
    {
        $period = ReferencePeriod::fromStrings('closed_fiscal_year', '2024-07-01', '2025-12-31');
        self::assertSame('18', $period->months->toString());
        self::assertSame('180000.00', $period->annualize(Decimal::of('270000'))->toFixed(2));
        self::assertSame('Exercice clos · 01/07/2024 → 31/12/2025 · 18 mois', $period->label());

        $revenue = $this->calc->annualizedRevenue(self::lumen(['periodStart' => '2024-07-01', 'periodEnd' => '2025-12-31', 'revenue' => '270000']));
        self::assertSame('180000.00', $revenue->value?->toFixed(2));
        self::assertSame('calculated', $revenue->nature, 'Un exercice ≠ 12 mois donne une valeur calculée (annualisée).');
    }

    public function testTwelveMonthPeriodAndPartialMonths(): void
    {
        self::assertSame('12', ReferencePeriod::fromStrings('closed_fiscal_year', '2025-01-01', '2025-12-31')->months->toString());
        self::assertSame('12', ReferencePeriod::fromStrings('trailing_12_months', '2025-11-01', '2026-10-31')->months->toString());
        // 45 jours : 45 ÷ 30,4375 = 1,478… → 1,5 mois.
        self::assertSame('1.5', ReferencePeriod::fromStrings('trailing_12_months', '2026-01-01', '2026-02-14')->months->toString());
    }

    public function testInvalidPeriodsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReferencePeriod::fromStrings('closed_fiscal_year', '2024-01-01', '2026-06-30'); // 30 mois
    }

    public function testRes1EstimatedResult(): void
    {
        $p = self::lumen();
        self::assertSame('50000.00', $this->calc->ownerComplement($p)->value?->toFixed(2));
        self::assertSame('155600.00', $this->calc->totalCosts($p)->value?->toFixed(2));
        self::assertSame('24400.00', $this->calc->estimatedResult($p)->value?->toFixed(2));
        self::assertSame('0.1356', $this->calc->resultRate($p)->value?->toFixed(4));
        self::assertSame('0.0200', $this->calc->generalVariableRate($p)->value?->toFixed(4));
    }

    public function testHc1HourlyCostWithDefaultAssumptions(): void
    {
        $p = self::lumen();
        $hours = $this->calc->billableHours($p);
        self::assertSame('2089.10', $hours->value?->toFixed(2));
        self::assertSame(['annual_hours_per_fte', 'billable_rate'], $hours->assumptions);

        $hourly = $this->calc->hourlyCost($p);
        self::assertSame('67.01', $hourly->value?->toFixed(2));
        self::assertSame('calculated', $hourly->nature);
        self::assertSame(['annual_hours_per_fte', 'billable_rate'], $hourly->assumptions, 'Calculé avec hypothèses.');
        self::assertSame('174.09', $this->calc->monthlyCapacity($p)->value?->toFixed(2));
    }

    public function testHc2OwnerSalaryAlreadyIncludedIsNotCountedTwice(): void
    {
        $p = self::lumen(['ownerIncluded' => '20000']);
        self::assertSame('30000.00', $this->calc->ownerComplement($p)->value?->toFixed(2));
        self::assertSame('57.44', $this->calc->hourlyCost($p)->value?->toFixed(2));
    }

    public function testHc3NoBillableHours(): void
    {
        $hourly = $this->calc->hourlyCost(self::lumen(['fte' => '0']));
        self::assertNull($hourly->value);
        self::assertSame(['no_billable_hours'], $hourly->missing);
    }

    public function testOwnerIncludedAboveTotalNeverProducesNegativeComplement(): void
    {
        self::assertSame('0', $this->calc->ownerComplement(self::lumen(['ownerIncluded' => '60000']))->value?->toString());
    }

    public function testHourlyCostOverrideIsAnAssumption(): void
    {
        $hourly = $this->calc->hourlyCost(self::lumen(['override' => '60']));
        self::assertSame('60.00', $hourly->value?->toFixed(2));
        self::assertSame('assumption', $hourly->nature);
    }

    public function testMissingDataIsNeverZero(): void
    {
        $result = $this->calc->estimatedResult(self::lumen(['fixedCosts' => null, 'cvg' => null]));
        self::assertNull($result->value);
        self::assertSame(['fixed_costs', 'variable_costs_general'], $result->missing);
        self::assertSame(['value' => null, 'nature' => 'calculated', 'assumptions' => [], 'missing' => ['fixed_costs', 'variable_costs_general']], $result->toArray());

        $rate = $this->calc->generalVariableRate(self::lumen(['revenue' => '0']));
        self::assertNull($rate->value, 'CA nul : taux non calculable (division par zéro).');
    }

    public function testApiFormatting(): void
    {
        $p = self::lumen();
        self::assertSame('24400.00', $this->calc->estimatedResult($p)->toArray()['value']);
        self::assertSame('13.56', $this->calc->resultRate($p)->toArray('percent')['value']);
    }

    public function testCompleteness(): void
    {
        $full = $this->calc->completeness(self::lumen(['hours' => '1607', 'rate' => '0.65']), ['identity_complete' => true, 'has_priced_service' => true]);
        self::assertSame(90, $full['rate']);
        self::assertSame(['revenue_target', 'recurring_share_declared'], $full['missing']);

        $empty = $this->calc->completeness(new FinancialProfileData(), ['identity_complete' => false, 'has_priced_service' => false]);
        self::assertSame(0, $empty['rate']);
        self::assertCount(13, $empty['missing']);
    }
}
