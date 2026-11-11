<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Pricing\PricingCalculator;
use App\Domain\Pricing\PricingException;
use App\Domain\Pricing\ScenarioInput;
use App\Support\Decimal;
use PHPUnit\Framework\TestCase;

/**
 * Cas PR-1 à PR-4 (docs/03-formules-et-scoring.md §4.3).
 */
final class PricingCalculatorTest extends TestCase
{
    private static function pr1(string $contingency = '0', string $margin = '0.30', string $tv = '0.02', ?string $price = '3200'): ScenarioInput
    {
        return new ScenarioInput(
            Decimal::of('60'), Decimal::of($contingency), Decimal::of($margin), Decimal::of($tv),
            [Decimal::of('40')], [Decimal::of('300')], $price === null ? null : Decimal::of($price), 8,
        );
    }

    public function testPr1(): void
    {
        $r = (new PricingCalculator())->calculate(self::pr1())->toArray();
        self::assertSame('2700.00', $r['base_cost']);
        self::assertSame('2755.10', $r['floor_price']);
        self::assertSame('3970.59', $r['recommended_price']);
        self::assertNotNull($r['at_price']);
        self::assertSame('436.00', $r['at_price']['result']);
        self::assertSame('2764.00', $r['at_price']['full_cost']);
        self::assertSame('13.63', $r['at_price']['margin_on_price_pct']);
        self::assertSame('15.77', $r['at_price']['markup_on_cost_pct']);
        self::assertSame('-19.41', $r['at_price']['gap_to_recommended_pct']);
        self::assertSame('6164.71', $r['at_price']['annual_shortfall']);
        self::assertSame([['code' => 'price_below_recommended', 'level' => 'warning']], $r['alerts']);
    }

    public function testPr2Contingency(): void
    {
        $r = (new PricingCalculator())->calculate(self::pr1('0.10'))->toArray();
        self::assertSame('44.00', $r['total_hours']);
        self::assertSame('2940.00', $r['base_cost']);
        self::assertSame('3000.00', $r['floor_price']);
        self::assertSame('4323.53', $r['recommended_price']);
    }

    public function testPr3MarginPlusVariableRateTooHigh(): void
    {
        $this->expectExceptionObject(new PricingException('margin_plus_variable_rate_too_high'));
        (new PricingCalculator())->calculate(self::pr1('0', '0.70', '0.35'));
    }

    public function testPr4CoherenceAtRecommendedPrice(): void
    {
        $calc = new PricingCalculator();
        $exact = $calc->calculate(self::pr1(price: null))->recommendedPrice;
        $at = $calc->calculate(new ScenarioInput(Decimal::of('60'), Decimal::zero(), Decimal::of('0.30'), Decimal::of('0.02'), [Decimal::of('40')], [Decimal::of('300')], $exact, 8));
        self::assertSame('0.3000', $at->marginOnPrice?->toFixed(4));
        self::assertSame('0.4286', $at->markupOnCost?->toFixed(4));
        self::assertNull($at->annualShortfall);
        self::assertSame([], $at->alerts);
    }

    public function testAlertsAndEdgeCases(): void
    {
        $calc = new PricingCalculator();
        self::assertSame('price_below_floor', $calc->calculate(self::pr1(price: '2000'))->alerts[0]['code']);
        self::assertSame('variable_rate_zero', $calc->calculate(self::pr1(tv: '0'))->alerts[0]['code']);
        self::assertNull($calc->calculate(self::pr1(price: null))->toArray()['at_price']);
        foreach ([['0.95', 'invalid_target_margin'], ['-0.01', 'invalid_target_margin']] as [$m, $code]) {
            try {
                $calc->calculate(self::pr1('0', $m));
                self::fail('Exception attendue');
            } catch (PricingException $e) {
                self::assertSame($code, $e->reason);
            }
        }
        $this->expectExceptionObject(new PricingException('empty_scenario'));
        $calc->calculate(new ScenarioInput(Decimal::of('60'), Decimal::zero(), Decimal::of('0.3'), Decimal::zero(), [], []));
    }

    public function testCommercialRoundingIsSuggestedNextToExactPrice(): void
    {
        $p = Decimal::of('3970.59');
        self::assertSame('3980', PricingCalculator::commercialRounding($p, '10')?->toString());
        self::assertSame('4000', PricingCalculator::commercialRounding($p, '50')?->toString());
        self::assertSame('3971', PricingCalculator::commercialRounding($p, '1')?->toString());
        self::assertSame('4000', PricingCalculator::commercialRounding(Decimal::of('4000'), '100')?->toString(), 'Déjà rond : inchangé.');
        self::assertNull(PricingCalculator::commercialRounding($p, 'none'));
    }

    public function testFrozenResultsAreReproducible(): void
    {
        // Recalculer à partir des entrées figées et de la même calc_version redonne exactement les mêmes résultats.
        $calc = new PricingCalculator();
        self::assertSame($calc->calculate(self::pr1('0.10'))->toArray(), $calc->calculate(self::pr1('0.10'))->toArray());
    }
}
