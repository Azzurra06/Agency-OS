<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Diagnostic\DiagnosticResult;
use App\Domain\Diagnostic\DiagnosticScorer;
use App\Domain\Diagnostic\QuestionnaireRegistry;
use App\Support\Decimal;
use PHPUnit\Framework\TestCase;

/**
 * Cas DIAG-1 et DIAG-2 (docs/03-formules-et-scoring.md §6.3) sur le questionnaire v1 réel.
 */
final class DiagnosticScorerTest extends TestCase
{
    /** Empreinte de la version publiée : toute modification du fichier fait échouer ce test (immuabilité). */
    private const V1_SHA256 = '9315c9219377fe576c5c9b685ced05967649db8115ca6921623bf827a0925a7d';

    /** @return array<string, string> */
    public static function diag1Answers(): array
    {
        $levels = [
            'pos.target' => 2, 'pos.specialisation' => 2, 'pos.packaged_offers' => 1, 'pos.differentiation' => 2, 'pos.portfolio_fit' => 1,
            'acq.lead_sources' => 2, 'acq.sales_process' => 2, 'acq.conversion' => 2, 'acq.pipeline' => 2, 'acq.visibility' => 2,
            'fin.pricing_method' => 1, 'fin.hourly_cost' => 2, 'fin.time_tracking' => 1, 'fin.project_profitability' => 1, 'fin.steering' => 1,
            'org.production_process' => 3, 'org.project_tools' => 2, 'org.scope_changes' => 2, 'org.roles' => 2,
            'ret.maintenance_offer' => 2, 'ret.renewal' => 3, 'ret.contract_review' => 2, 'ret.upsell' => 3, 'ret.satisfaction' => 2,
            'str.objectives' => 1, 'str.vision' => 1, 'str.review' => 1, 'str.capacity_plan' => 1, 'str.market_watch' => 1,
        ];
        $answers = array_map(static fn (int $l): string => 'n' . $l, $levels);
        $answers['org.owner_dependency'] = 'unknown'; // question « inconnue » : exclue du calcul

        return $answers;
    }

    public function testVersionOneIsImmutable(): void
    {
        self::assertSame(self::V1_SHA256, hash_file('sha256', QuestionnaireRegistry::path('v1')), 'Le questionnaire v1 publié a été modifié : créez une v2.');
        $q = QuestionnaireRegistry::current();
        self::assertSame('v1', $q->version);
        self::assertSame(['pos', 'acq', 'fin', 'org', 'ret', 'str'], $q->dimensionCodes());
        self::assertSame(30, $q->questionCount());
        $unknownPolicy = [];
        foreach ($q->dimensions as $dimension) {
            foreach ($dimension['questions'] as $code => $question) {
                if ($question['unknown_policy'] === 'unknown') {
                    $unknownPolicy[] = $code;
                }
            }
        }
        self::assertSame(['acq.lead_sources', 'org.owner_dependency', 'ret.renewal'], $unknownPolicy);
    }

    public function testDiag1(): void
    {
        $result = (new DiagnosticScorer())->score(QuestionnaireRegistry::current(), self::diag1Answers(), ['team_size' => 4]);
        $scores = array_map(static fn ($d): ?string => $d->score?->toFixed(2), $result->dimensions);
        self::assertSame(['pos' => '53.33', 'acq' => '66.67', 'fin' => '40.00', 'org' => '75.00', 'ret' => '80.00', 'str' => '33.33'], $scores);
        self::assertSame('58.06', $result->global?->toFixed(2));
        self::assertSame('complete', $result->completeness());
        self::assertSame('En construction', DiagnosticResult::level($result->global));
        self::assertSame(['ret', 'org'], $result->strengths());
        self::assertSame(['str', 'fin'], $result->weaknesses());
        self::assertSame('0.9667', $result->globalReliability->toFixed(4), '29 réponses exploitables sur 30.');
        self::assertSame('Fiabilité élevée', DiagnosticResult::reliabilityLabel($result->globalReliability));
        self::assertSame(['org.owner_dependency'], $result->unknownExcluded);
    }

    public function testDiag2PartialDiagnostic(): void
    {
        $answers = self::diag1Answers();
        // D2 : sales 3, conversion 2, pipeline 2, lead_sources inconnu (exclu), visibility « Je ne sais pas » évaluable (niveau 0).
        $answers['acq.lead_sources'] = 'unknown';
        $answers['acq.sales_process'] = 'n3';
        $answers['acq.conversion'] = 'n2';
        $answers['acq.pipeline'] = 'n2';
        $answers['acq.visibility'] = 'unknown';
        // D6 : trois questions laissées sans réponse → 2 réponses exploitables sur 5 → non calculable.
        unset($answers['str.vision'], $answers['str.review'], $answers['str.capacity_plan']);

        $result = (new DiagnosticScorer())->score(QuestionnaireRegistry::current(), $answers, ['team_size' => 4]);
        $acq = $result->dimensions['acq'];
        self::assertSame('58.33', $acq->score?->toFixed(2));
        self::assertSame('0.8000', $acq->reliability->toFixed(4));
        self::assertSame(1, $acq->unknownExcluded);
        self::assertSame(1, $acq->unknownEvaluable);

        $str = $result->dimensions['str'];
        self::assertFalse($str->scorable);
        self::assertNull($str->score);
        self::assertNull($result->global, 'Score global non affiché : diagnostic partiel.');
        self::assertSame('partial', $result->completeness());
        self::assertSame(['str'], $result->unscorableDimensions());
        self::assertSame(['str.vision', 'str.review', 'str.capacity_plan'], $result->missing);
        self::assertNotContains('str', $result->weaknesses(), 'Une dimension non calculable n\'est jamais un axe d\'amélioration.');
    }

    public function testConditionsMaintenanceAndSoloAgency(): void
    {
        $answers = self::diag1Answers();
        $answers['ret.maintenance_offer'] = 'n0'; // pas d'offre : renouvellement et revue non applicables
        $scorer = new DiagnosticScorer();
        $q = QuestionnaireRegistry::current();

        $result = $scorer->score($q, $answers, ['team_size' => 1]);
        self::assertSame(3, $result->dimensions['ret']->applicable);
        self::assertSame('55.56', $result->dimensions['ret']->score?->toFixed(2), '(0 + 3 + 2) ÷ 9 : les réponses devenues non applicables sont ignorées.');
        self::assertSame(4, $result->dimensions['org']->applicable, 'Agence d\'une personne : question sur les rôles masquée.');
        self::assertSame(27, count($scorer->applicableQuestions($q, $answers, ['team_size' => 1])));
        self::assertSame(28, count($scorer->applicableQuestions($q, $answers, ['team_size' => null])), 'Taille inconnue : question sur les rôles affichée.');

        // « Je ne sais pas » sur l'offre de maintenance : les questions conditionnelles restent masquées.
        $answers['ret.maintenance_offer'] = 'unknown';
        self::assertNotContains('ret.renewal', $scorer->applicableQuestions($q, $answers, ['team_size' => 4]));
    }

    public function testThresholdBoundaries(): void
    {
        $scorer = new DiagnosticScorer();
        $q = QuestionnaireRegistry::current();
        $answers = self::diag1Answers();
        unset($answers['pos.target'], $answers['pos.specialisation']); // 3 sur 5 = 60 % : juste calculable
        self::assertTrue($scorer->score($q, $answers, ['team_size' => 4])->dimensions['pos']->scorable);
        unset($answers['pos.packaged_offers']); // 2 sur 5 : non calculable
        self::assertFalse($scorer->score($q, $answers, ['team_size' => 4])->dimensions['pos']->scorable);
    }

    public function testLevelsAndAllUnknownEvaluableCountAsZero(): void
    {
        self::assertSame('Fragile', DiagnosticResult::level(Decimal::of('39.99')));
        self::assertSame('En construction', DiagnosticResult::level(Decimal::of('40')));
        self::assertSame('Structuré', DiagnosticResult::level(Decimal::of('60')));
        self::assertSame('Avancé', DiagnosticResult::level(Decimal::of('80')));

        $q = QuestionnaireRegistry::current();
        $answers = array_fill_keys(array_keys($q->dimensions['fin']['questions']), 'unknown');
        $result = (new DiagnosticScorer())->score($q, $answers);
        self::assertSame('0.00', $result->dimensions['fin']->score?->toFixed(2), 'Ne pas connaître ses chiffres est un manque de maturité.');
        self::assertTrue($result->dimensions['fin']->scorable);
    }
}
