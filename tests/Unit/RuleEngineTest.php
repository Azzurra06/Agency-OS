<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Strategy\Facts;
use App\Domain\Strategy\Finding;
use App\Domain\Strategy\RuleCatalog;
use App\Domain\Strategy\RuleEngine;
use App\Support\Decimal;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Cas RULE-1 à RULE-4 (docs/03-formules-et-scoring.md §7), priorité, qualité des données, catalogue.
 */
final class RuleEngineTest extends TestCase
{
    /** Prix plancher et prix recommandé EXACTS de PR-1 (C₀ = 2 700, tv = 2 %, marge 30 %). */
    private static function pr1Simulation(bool $drift = false, string $finalizedAt = '2026-11-12 09:00:00'): array
    {
        return ['public_id' => 'SIM', 'version' => 1, 'finalized_at' => $finalizedAt, 'floor' => Decimal::of('2700')->div(Decimal::of('0.98')),
            'recommended' => Decimal::of('2700')->div(Decimal::of('0.68')), 'variable_rate' => Decimal::of('0.02'), 'drift' => $drift];
    }

    private static function facts(array $overrides = []): Facts
    {
        $base = [
            'today' => new DateTimeImmutable('2026-11-12'), 'estimatedResult' => Decimal::of('24400'), 'hourlyCost' => Decimal::of('60'),
            'hourlyCostAssumptions' => [], 'revenueAnnualized' => Decimal::of('180000'), 'revenueTarget' => null, 'declaredShare' => Decimal::of('0.25'),
            'profileCompleteness' => 100, 'profileUpdatedAt' => '2026-11-10 10:00:00', 'periodEnd' => '2025-12-31', 'periodLabel' => 'Exercice 2025',
            'services' => [['public_id' => 'SVC', 'name' => 'Site vitrine', 'price' => Decimal::of('3200'), 'volume' => 8, 'simulation' => self::pr1Simulation()]],
            'offers' => [['public_id' => 'OFF', 'name' => 'Maintenance', 'contracts' => 12, 'monthly_result' => Decimal::of('47'), 'margin' => Decimal::of('0.3133')]],
            'coverage' => Decimal::of('0.24'), 'coverageGapPoints' => Decimal::of('1'), 'recurringHours' => Decimal::of('18'), 'monthlyCapacity' => Decimal::of('174.09'),
            'contractsUpdatedAt' => '2026-11-01 10:00:00', 'diagnostic' => null,
        ];

        return new Facts(...array_merge($base, $overrides));
    }

    /** @return array<string, Finding> */
    private static function byCode(Facts $facts): array
    {
        $out = [];
        foreach ((new RuleEngine())->evaluate($facts) as $f) {
            $out[$f->ruleCode] = $f;
        }

        return $out;
    }

    private static function diagnostic(string $finScore, bool $scorable = true): array
    {
        $dims = [];
        foreach (['pos' => '53.33', 'acq' => '66.67', 'fin' => $finScore, 'org' => '75.00', 'ret' => '80.00', 'str' => '45.00'] as $code => $score) {
            $dims[$code] = ['label' => $code, 'scorable' => $code !== 'fin' || $scorable, 'score' => $code === 'fin' && !$scorable ? null : Decimal::of($score)];
        }

        return ['public_id' => 'DIAG', 'completed_at' => '2026-11-01 10:00:00', 'completeness' => $scorable ? 'complete' : 'partial', 'reliability' => Decimal::of('0.9667'), 'dimensions' => $dims];
    }

    public function testRule1PriceUnderMediumWithQuantifiedImpact(): void
    {
        $f = self::byCode(self::facts())['PRICE_UNDER'];
        self::assertSame('service:SVC', $f->subjectKey);
        self::assertSame('medium', $f->severity);
        self::assertSame(45, $f->priority);
        self::assertSame('6164.71', $f->impactAmount?->toFixed(2));
        self::assertSame('-19.4074', $f->evidence['values']['price_gap_rate'], 'Valeur EXACTE dans la preuve.');
        self::assertSame(['price_gap_medium' => '-10.00', 'price_gap_high' => '-20.00'], $f->evidence['thresholds']);
        self::assertSame('ok', $f->dataQuality);
        self::assertSame('pricing_simulation', $f->evidence['sources'][0]['type']);
        $text = RuleCatalog::describe('PRICE_UNDER', $f->evidence, $f->impactAmount?->toFixed(2));
        self::assertStringContainsString('19,4', $text['explanation']);
        self::assertStringContainsString("6\u{202F}164,71", $text['impact_text']);
        self::assertGreaterThanOrEqual(2, count($text['actions']));
        self::assertLessThanOrEqual(5, count($text['actions']));
    }

    public function testRule2DimensionBonusOnlyForScorableDimension(): void
    {
        self::assertSame(51, self::byCode(self::facts(['diagnostic' => self::diagnostic('40.00')]))['PRICE_UNDER']->priority, 'arrondi(10 × 0,6) = 6.');
        self::assertSame(45, self::byCode(self::facts(['diagnostic' => self::diagnostic('40.00', false)]))['PRICE_UNDER']->priority, 'Dimension non calculable : pas de bonus.');
        self::assertSame(100, RuleEngine::priority('critical', Decimal::of('0')), 'Plafond à 100.');
    }

    public function testRule3ConditionNoLongerTrue(): void
    {
        $services = [['public_id' => 'SVC', 'name' => 'Site vitrine', 'price' => Decimal::of('4000'), 'volume' => 8, 'simulation' => self::pr1Simulation()]];
        self::assertArrayNotHasKey('PRICE_UNDER', self::byCode(self::facts(['services' => $services])));
    }

    public function testRule4DraftOnlyGivesNoPricing(): void
    {
        $services = [['public_id' => 'SVC', 'name' => 'Site vitrine', 'price' => Decimal::of('3200'), 'volume' => 8, 'simulation' => null]];
        $codes = self::byCode(self::facts(['services' => $services]));
        self::assertArrayNotHasKey('PRICE_UNDER', $codes);
        self::assertSame('info', $codes['NO_PRICING']->severity);
    }

    public function testSeverityLevelsAndDataQuality(): void
    {
        $engine = static fn (string $price, ?int $volume = 8, string $finalizedAt = '2026-11-12 09:00:00', bool $drift = false): array => self::byCode(self::facts(['services' => [
            ['public_id' => 'SVC', 'name' => 'Site vitrine', 'price' => Decimal::of($price), 'volume' => $volume, 'simulation' => self::pr1Simulation($drift, $finalizedAt)],
        ]]));
        self::assertSame('high', $engine('3150')['PRICE_UNDER']->severity, 'Écart ≤ −20 %.');
        $floor = $engine('2700')['PRICE_BELOW_FLOOR'];
        self::assertSame('critical', $floor->severity);
        self::assertSame('432.00', $floor->impactAmount?->toFixed(2), '(1 − 0,02) × (2 755,10 − 2 700) = 54 € par vente × 8.');
        $noVolume = $engine('3200', null)['PRICE_UNDER'];
        self::assertSame('partial', $noVolume->dataQuality);
        self::assertNull($noVolume->impactAmount, 'Sans volume : impact non chiffré.');
        self::assertSame(45, $noVolume->priority, 'Une donnée partielle n\'augmente jamais la priorité.');
        $old = $engine('3200', 8, '2025-06-01 10:00:00')['PRICE_UNDER'];
        self::assertSame('stale', $old->dataQuality);
        self::assertSame(['pricing_simulation'], $old->evidence['stale']);
        $drifted = $engine('3200', 8, '2026-11-12 09:00:00', true);
        self::assertArrayHasKey('PRICING_DRIFT', $drifted);
        self::assertSame('stale', $drifted['PRICE_UNDER']->dataQuality);
    }

    public function testOrganisationRules(): void
    {
        $codes = self::byCode(self::facts([
            'estimatedResult' => Decimal::of('-5000'), 'hourlyCost' => null, 'revenueTarget' => Decimal::of('250000'), 'declaredShare' => Decimal::of('0.10'),
            'offers' => [], 'coverageGapPoints' => Decimal::of('14.7'), 'recurringHours' => Decimal::of('60'), 'profileCompleteness' => 50,
        ]));
        foreach (['RESULT_NEGATIVE' => 'critical', 'NO_HOURLY_COST' => 'high', 'TARGET_GAP' => 'medium', 'RECURRING_SHARE_LOW' => 'high', 'NO_RECURRING_OFFER' => 'high',
            'RECURRING_DATA_GAP' => 'info', 'CAPACITY_RECURRING_HIGH' => 'medium', 'PROFILE_INCOMPLETE' => 'info', 'NO_DIAGNOSTIC' => 'info'] as $code => $severity) {
            self::assertArrayHasKey($code, $codes, $code);
            self::assertSame($severity, $codes[$code]->severity, $code);
        }
        self::assertSame('70000.00', $codes['TARGET_GAP']->impactAmount?->toFixed(2));
        self::assertArrayNotHasKey('HOURLY_ASSUMPTIONS', $codes);

        $offers = [['public_id' => 'A', 'name' => 'Annuelle', 'contracts' => 5, 'monthly_result' => Decimal::of('-2'), 'margin' => Decimal::of('-0.02')],
            ['public_id' => 'B', 'name' => 'Basique', 'contracts' => 3, 'monthly_result' => Decimal::of('10'), 'margin' => Decimal::of('0.15')]];
        $codes = self::byCode(self::facts(['offers' => $offers, 'hourlyCostAssumptions' => ['billable_rate']]));
        self::assertSame('10.00', $codes['MAINT_UNPROFITABLE']->impactAmount?->toFixed(2), 'REC-2 : 2 € × 5 contrats par mois.');
        self::assertSame('offer:B', $codes['MAINT_LOW_MARGIN']->subjectKey);
        self::assertArrayHasKey('HOURLY_ASSUMPTIONS', $codes);
    }

    public function testDiagnosticRules(): void
    {
        $diag = self::diagnostic('20.00');
        $diag['dimensions']['str']['score'] = Decimal::of('33.33');
        $codes = self::byCode(self::facts(['diagnostic' => $diag]));
        self::assertSame('high', $codes['DIM_LOW_D3']->severity, 'Score < 25.');
        self::assertSame('medium', $codes['DIM_LOW_D6']->severity);
        self::assertSame(78, $codes['DIM_LOW_D3']->priority, '70 + arrondi(10 × 0,8).');
        self::assertArrayNotHasKey('NO_DIAGNOSTIC', $codes);
        $partial = self::byCode(self::facts(['diagnostic' => self::diagnostic('0', false)]));
        self::assertArrayHasKey('DIAG_LOW_RELIABILITY', $partial);
        self::assertArrayNotHasKey('DIM_LOW_D3', $partial, 'Une dimension non calculable n\'est jamais un axe d\'amélioration.');
        self::assertSame('partial', $partial['DIAG_LOW_RELIABILITY']->dataQuality);
    }
}
