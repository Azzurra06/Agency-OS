<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Recurring\RecurringCalculator;
use App\Support\Decimal;
use PHPUnit\Framework\TestCase;

/**
 * Cas REC-1 à REC-5 (docs/03-formules-et-scoring.md §5).
 */
final class RecurringCalculatorTest extends TestCase
{
    private static function d(string $v): Decimal
    {
        return Decimal::of($v);
    }

    public function testRec1MonthlyOffer(): void
    {
        $o = (new RecurringCalculator())->offer(self::d('150'), 'monthly', self::d('1.5'), self::d('10'), self::d('60'), self::d('0.02'));
        self::assertSame('103.00', $o['monthly_cost']?->toFixed(2));
        self::assertSame('47.00', $o['monthly_result']?->toFixed(2));
        self::assertSame('31.3', $o['margin_on_price']?->mul(self::d('100'))->toFixed(1));
        self::assertSame('102.04', $o['floor_price']?->toFixed(2));
    }

    public function testRec2AnnualOfferIsUnprofitable(): void
    {
        $o = (new RecurringCalculator())->offer(self::d('1200'), 'annual', self::d('1.5'), self::d('10'), self::d('60'), self::d('0.02'));
        self::assertSame('100.00', $o['monthly_price']->toFixed(2));
        self::assertSame('-2.00', $o['monthly_result']?->toFixed(2));
        self::assertTrue($o['monthly_result']?->isNegative());
    }

    public function testOfferWithoutHourlyCost(): void
    {
        $o = (new RecurringCalculator())->offer(self::d('150'), 'monthly', self::d('1.5'), self::d('10'), null, self::d('0.02'));
        self::assertSame('150.00', $o['monthly_price']->toFixed(2));
        self::assertNull($o['monthly_result']);
    }

    public function testRec3AndRec5ContractedRevenueCoverageAndCapacity(): void
    {
        $offers = [
            ['monthly_price' => self::d('150'), 'contracts' => 12, 'hours_per_month' => self::d('1.5')],
            ['monthly_price' => self::d('100'), 'contracts' => 5, 'hours_per_month' => self::d('1.5')],
        ];
        $c = (new RecurringCalculator())->contracted($offers, self::d('180000'), self::d('0.30'), self::d('2089.1')->div(self::d('12')));
        self::assertSame('2300.00', $c['mrr']->toFixed(2));
        self::assertSame('27600.00', $c['arr']->toFixed(2));
        self::assertSame('15.3', $c['coverage']?->mul(self::d('100'))->toFixed(1));
        self::assertSame('14.7', $c['gap_points']?->toFixed(1));
        self::assertTrue($c['gap_alert']);
        self::assertSame('25.50', $c['hours']->toFixed(2));
        self::assertSame('14.6', $c['capacity_use']?->mul(self::d('100'))->toFixed(1));

        $noAlert = (new RecurringCalculator())->contracted($offers, self::d('180000'), self::d('0.25'), null);
        self::assertFalse($noAlert['gap_alert'], '9,7 points : sous le seuil de 10.');
        self::assertNull($noAlert['capacity_use']);
        self::assertNull((new RecurringCalculator())->contracted($offers, null, null, null)['coverage']);
    }

    public function testRec4Projection(): void
    {
        $entries = ['a' => ['monthly_price' => self::d('150'), 'hours_per_month' => self::d('1.5'), 'starting' => self::d('10'), 'new_per_month' => self::d('2'), 'churn' => self::d('0.03')]];
        $p = (new RecurringCalculator())->project($entries, 12, null);
        $m = $p['months'];
        self::assertSame('11.70', $m[0]['total_clients']->toFixed(2));
        self::assertSame('1755.00', $m[0]['mrr']->toFixed(2));
        self::assertSame('13.35', $m[1]['total_clients']->toFixed(2));
        self::assertSame('2002.35', $m[1]['mrr']->toFixed(2));
        self::assertSame('19.46', $m[5]['total_clients']->toFixed(2), '19,4649… : le tableau de 03 indiquait 19,47 par erreur d\'arrondi (corrigé).');
        self::assertSame('2919.74', $m[5]['mrr']->toFixed(2));
        self::assertSame('27.35', $m[11]['total_clients']->toFixed(2));
        self::assertSame('4102.34', $m[11]['mrr']->toFixed(2));
        self::assertSame('14095.14', $p['cumulative_6']->toFixed(2));
        self::assertSame('35857.68', $p['cumulative']->toFixed(2));
        self::assertCount(6, (new RecurringCalculator())->project($entries, 6, null)['months']);
    }
}
