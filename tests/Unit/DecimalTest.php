<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Decimal;
use DivisionByZeroError;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    /** @return iterable<string, array{string, int, string}> */
    public static function roundingCases(): iterable
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/decimal-cases.json'), true, 8, JSON_THROW_ON_ERROR);
        foreach ($data['rounding'] as $case) {
            yield $case['value'] . ' → ' . $case['expected'] => [$case['value'], $case['scale'], $case['expected']];
        }
    }

    #[DataProvider('roundingCases')]
    public function testRoundsHalfAwayFromZero(string $value, int $scale, string $expected): void
    {
        self::assertSame($expected, Decimal::of($value)->toFixed($scale));
    }

    public function testAdditionIsExact(): void
    {
        self::assertSame('0.3', Decimal::of('0.1')->add(Decimal::of('0.2'))->toString());
    }

    public function testThirdsRoundTripWithoutIntermediateRounding(): void
    {
        $third = Decimal::of('100')->div(Decimal::of('3'));
        self::assertSame('33.33', $third->toFixed(2));
        self::assertSame('100.00', $third->mul(Decimal::of('3'))->toFixed(2));
    }

    public function testMultiplyBeforeDivideForAnnualisation(): void
    {
        // Cas PER-1 : 270 000 € sur 18 mois → 180 000,00 € annualisés.
        $annualised = Decimal::of('270000')->mul(Decimal::of('12'))->div(Decimal::of('18'));
        self::assertSame('180000.00', $annualised->toFixed(2));
    }

    public function testLargeValuesStayExact(): void
    {
        self::assertSame('999899999900.01', Decimal::of('99999999.99')->mul(Decimal::of('9999'))->toFixed(2));
    }

    public function testDivisionByZeroIsExplicit(): void
    {
        $this->expectException(DivisionByZeroError::class);
        Decimal::of('1')->div(Decimal::zero());
    }

    public function testNegativeZeroIsNormalised(): void
    {
        self::assertSame('0', Decimal::of('-0.000')->toString());
        self::assertFalse(Decimal::of('-0.0')->isNegative());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidInputs(): iterable
    {
        yield 'virgule décimale' => ['12,5'];
        yield 'notation scientifique' => ['1e3'];
        yield 'vide' => [''];
        yield 'texte' => ['abc'];
        yield 'double point' => ['1.2.3'];
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidStrings(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::of($input);
    }

    public function testComparisons(): void
    {
        $a = Decimal::of('2755.10');
        $b = Decimal::of('3970.59');
        self::assertSame(-1, $a->compare($b));
        self::assertTrue($b->max($a)->equals($b));
        self::assertTrue(Decimal::of('-2')->isNegative());
        self::assertTrue(Decimal::of('2.000')->equals(Decimal::of('2')));
    }

    public function testPricingReferenceCasePr1(): void
    {
        // docs/03 §4.3, cas PR-1 : C0 = 40 h × 60 + 300 = 2 700 ; tv = 0,02 ; m = 0,30 ; P = 3 200.
        $c0 = Decimal::of('40')->mul(Decimal::of('60'))->add(Decimal::of('300'));
        $tv = Decimal::of('0.02');
        $m = Decimal::of('0.30');
        $one = Decimal::of('1');
        self::assertSame('2755.10', $c0->div($one->sub($tv))->toFixed(2));
        self::assertSame('3970.59', $c0->div($one->sub($m)->sub($tv))->toFixed(2));
        $result = Decimal::of('3200')->mul($one->sub($tv))->sub($c0);
        self::assertSame('436.00', $result->toFixed(2));
        self::assertSame('0.1363', $result->div(Decimal::of('3200'))->toFixed(4));
    }
}
