<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\DemoService;
use App\Domain\Diagnostic\DiagnosticScorer;
use App\Domain\Diagnostic\QuestionnaireRegistry;
use App\Infrastructure\Database\OrgId;
use App\Http\Response;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;
use Tests\Support\RequestFactory;

/**
 * Parcours du lot 2 : brouillon unique, sauvegarde automatique, conditions, clôture figée,
 * diagnostic partiel, isolation entre agences, démo Lumen.
 */
final class DiagnosticFlowTest extends DatabaseTestCase
{
    private const ORIGIN = ['Origin' => 'https://agency-os.test'];

    private function send(string $method, string $path, ?array $json = null, array $form = []): Response
    {
        $headers = self::ORIGIN;
        if (!isset($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = str_repeat('e', 64);
        }
        $headers['X-CSRF-Token'] = (string) $_SESSION['_csrf'];
        $body = '';
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($json === [] ? new \stdClass() : $json, JSON_THROW_ON_ERROR);
        }
        $headers['Cookie'] = $this->app->sessions()->cookieName() . '=test';

        return $this->app->kernel()->handle(RequestFactory::make($method, $path, $headers, $body, $form));
    }

    /** @return array<string, mixed> */
    private static function data(Response $response): array
    {
        return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['data'];
    }

    /** @return array{user_id: int, org_id: int, org_public_id: string} */
    private function member(string $email, int $team = 4): array
    {
        $member = Fixtures::member($this->app->db(), $email);
        $this->app->db()->run('UPDATE organizations SET team_size = :t WHERE id = :id', ['t' => $team, 'id' => $member['org_id']]);
        $this->app->organizations()->setOnboardingStep(new OrgId($member['org_id']), 3, true);
        Fixtures::login($member);

        return $member;
    }

    private function answerAll(string $id, array $answers): void
    {
        foreach ($answers as $question => $option) {
            self::assertSame(200, $this->send('PUT', "/api/v1/diagnostics/{$id}/answers", ['question' => $question, 'option' => $option])->status, $question);
        }
    }

    public function testSingleDraftAutosaveAndFrozenCompletion(): void
    {
        $this->member('claire@example.test');
        $first = $this->send('POST', '/api/v1/diagnostics', []);
        self::assertSame(201, $first->status);
        $id = self::data($first)['public_id'];
        $again = $this->send('POST', '/api/v1/diagnostics', []);
        self::assertSame(200, $again->status, 'Un seul brouillon : reprise.');
        self::assertSame($id, self::data($again)['public_id']);
        self::assertSame(1, $this->countRows('diagnostics'));

        // Réponse invalide, question inconnue → 422 ; clôture incomplète refusée.
        self::assertSame(422, $this->send('PUT', "/api/v1/diagnostics/{$id}/answers", ['question' => 'pos.target', 'option' => 'n4'])->status);
        self::assertSame(422, $this->send('PUT', "/api/v1/diagnostics/{$id}/answers", ['question' => 'zzz.nope', 'option' => 'n1'])->status);
        self::assertSame(422, $this->send('POST', "/api/v1/diagnostics/{$id}/complete", [])->status);

        $this->answerAll($id, DemoService::LUMEN_DIAGNOSTIC);
        $page = $this->send('GET', '/app/diagnostic/questionnaire?etape=3');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('compté comme pratique absente', $page->body, 'Politique évaluable expliquée.');

        $done = $this->send('POST', "/api/v1/diagnostics/{$id}/complete", []);
        self::assertSame(200, $done->status);
        self::assertSame('complete', self::data($done)['completeness']);
        self::assertSame(422, $this->send('POST', "/api/v1/diagnostics/{$id}/complete", [])->status, 'Double clôture refusée.');
        self::assertSame(422, $this->send('PUT', "/api/v1/diagnostics/{$id}/answers", ['question' => 'pos.target', 'option' => 'n0'])->status, 'Réponses figées.');

        $results = self::data($this->send('GET', "/api/v1/diagnostics/{$id}/results"));
        self::assertSame('58.06', $results['global']);
        self::assertSame('58', $results['global_display']);
        self::assertSame(['ret', 'org'], $results['strengths']);
        self::assertSame(['str', 'fin'], $results['weaknesses']);
        self::assertSame('Fiabilité élevée', $results['reliability_label']);
        self::assertSame('75.00', $results['dimensions']['org']['score']);

        $html = $this->send('GET', "/app/diagnostic/resultats/{$id}");
        self::assertSame(200, $html->status);
        self::assertStringContainsString('Maturité : En construction', $html->body);
        self::assertStringContainsString('id="data-radar"', $html->body);
        self::assertStringContainsString('chart-4.4.4.umd.min.js', $html->body);
        self::assertStringContainsString('Dernier résultat', $this->send('GET', '/app/diagnostic')->body);
    }

    public function testConditionalQuestionsAndPartialDiagnosticThenCopy(): void
    {
        $this->member('solo@example.test', 1);
        $id = self::data($this->send('POST', '/api/v1/diagnostics', []))['public_id'];
        $answers = DemoService::LUMEN_DIAGNOSTIC;
        unset($answers['org.roles']);
        $answers['ret.maintenance_offer'] = 'n0';
        unset($answers['ret.renewal'], $answers['ret.contract_review']);
        $answers['acq.lead_sources'] = 'unknown';
        $answers['acq.sales_process'] = 'n1';
        $last = null;
        foreach ($answers as $question => $option) {
            $last = $this->send('PUT', "/api/v1/diagnostics/{$id}/answers", ['question' => $question, 'option' => $option]);
        }
        $progress = self::data($last)['progress'];
        self::assertSame(27, $progress['applicable'], 'Agence solo sans maintenance : 27 questions.');
        self::assertTrue($progress['ready']);
        self::assertNotContains('ret.renewal', $progress['applicable_questions']);

        $done = self::data($this->send('POST', "/api/v1/diagnostics/{$id}/complete", []));
        self::assertSame('complete', $done['completeness']);

        // « Compléter ces réponses » : nouveau brouillon prérempli avec les réponses précédentes.
        $copy = $this->send('POST', '/api/v1/diagnostics', ['from' => $id]);
        self::assertSame(201, $copy->status);
        $copyId = self::data($copy)['public_id'];
        self::assertNotSame($id, $copyId);
        self::assertSame(27, $this->countRows('diagnostic_answers', 'diagnostic_id = (SELECT id FROM diagnostics WHERE public_id = :p)', ['p' => $copyId]));
        self::assertSame(204, $this->send('DELETE', "/api/v1/diagnostics/{$copyId}")->status);
        self::assertSame(404, $this->send('DELETE', "/api/v1/diagnostics/{$id}")->status, 'Un diagnostic terminé ne se supprime pas.');
    }

    public function testPartialDiagnosticDisplay(): void
    {
        // Avec le questionnaire v1, une clôture complète ne peut pas produire de dimension non calculable (au plus une
        // question « inconnue » par dimension). Le cas partiel est donc écrit directement en base (cas DIAG-2) pour
        // vérifier son affichage, prévu pour les versions futures du questionnaire.
        $member = $this->member('partial@example.test');
        $org = new OrgId($member['org_id']);
        $answers = DemoService::LUMEN_DIAGNOSTIC;
        unset($answers['str.vision'], $answers['str.review'], $answers['str.capacity_plan']);
        $repo = $this->app->diagnosticRepository();
        $id = (string) $repo->createDraft($org, $member['user_id'], 'v1', DiagnosticScorer::CALC_VERSION);
        $draft = $repo->find($org, $id);
        self::assertNotNull($draft);
        foreach ($answers as $question => $option) {
            $repo->saveAnswer($org, $draft['id'], $question, $option, false);
        }
        $result = (new DiagnosticScorer())->score(QuestionnaireRegistry::current(), $answers, ['team_size' => 4]);
        self::assertTrue($repo->complete($org, $draft['id'], $result, DiagnosticScorer::CALC_VERSION));

        $results = self::data($this->send('GET', "/api/v1/diagnostics/{$id}/results"));
        self::assertSame('partial', $results['completeness']);
        self::assertNull($results['global']);
        self::assertSame(5, $results['scorable_count']);
        self::assertFalse($results['dimensions']['str']['scorable']);
        self::assertNotContains('str', $results['weaknesses']);
        $html = $this->send('GET', "/app/diagnostic/resultats/{$id}")->body;
        self::assertStringContainsString('Diagnostic partiel : 5/6 dimensions', $html);
        self::assertStringContainsString('Score global disponible lorsque les 6 dimensions sont renseignées.', $html);
        self::assertStringContainsString('Données insuffisantes', $html);
        self::assertStringContainsString('Stratégie et développement (insuffisant)', $html);
        self::assertStringContainsString('Diagnostic partiel', $this->send('GET', '/app')->body);
    }

    public function testIsolationBetweenAgencies(): void
    {
        $this->member('a@example.test');
        $idA = self::data($this->send('POST', '/api/v1/diagnostics', []))['public_id'];
        $this->answerAll($idA, DemoService::LUMEN_DIAGNOSTIC);
        $this->send('POST', "/api/v1/diagnostics/{$idA}/complete", []);

        $this->member('b@example.test');
        self::assertSame(404, $this->send('GET', "/api/v1/diagnostics/{$idA}/results")->status);
        self::assertSame(404, $this->send('PUT', "/api/v1/diagnostics/{$idA}/answers", ['question' => 'pos.target', 'option' => 'n0'])->status);
        self::assertSame(404, $this->send('POST', "/api/v1/diagnostics/{$idA}/complete", [])->status);
        self::assertSame(404, $this->send('GET', "/app/diagnostic/resultats/{$idA}")->status);
        self::assertSame(422, $this->send('POST', '/api/v1/diagnostics', ['from' => $idA])->status, 'Impossible de recopier le diagnostic d\'une autre agence.');
        self::assertSame(0, $this->countRows('diagnostics', "status = 'draft'"));
        self::assertSame([], self::data($this->send('GET', '/api/v1/diagnostics'))['history']);
        self::assertGreaterThanOrEqual(3, $this->countRows('security_events', "event = 'authz.foreign_resource'"));
        self::assertSame(30, $this->countRows('diagnostic_answers'), 'Aucune réponse écrite chez A par B.');
    }

    public function testDemoHasCompletedLumenDiagnosticAndResetKeepsIt(): void
    {
        $_SESSION = ['_csrf' => str_repeat('e', 64)];
        self::assertSame(303, $this->send('POST', '/demo', form: ['_csrf' => str_repeat('e', 64)])->status);
        $_SESSION['_csrf'] = str_repeat('e', 64);
        $overview = self::data($this->send('GET', '/api/v1/diagnostics'));
        self::assertSame('58', $overview['latest']['global_display']);
        self::assertNull($overview['draft']);
        $dashboard = $this->send('GET', '/app');
        self::assertStringContainsString('En construction', $dashboard->body);

        self::assertSame(303, $this->send('POST', '/demo/reinitialiser', form: ['_csrf' => str_repeat('e', 64)])->status);
        $_SESSION['_csrf'] = str_repeat('e', 64);
        self::assertCount(1, self::data($this->send('GET', '/api/v1/diagnostics'))['history'], 'Réinitialisation : un seul diagnostic fictif.');
    }

    public function testHourlyCostPrefillFromProfile(): void
    {
        $member = $this->member('prefill@example.test');
        $this->app->financialProfiles()->update(new OrgId($member['org_id']), [
            'period_type' => 'closed_fiscal_year', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31',
            'fixed_costs' => '30000.00', 'personnel_costs' => '60000.00', 'owner_comp_total_cost' => '50000.00', 'owner_comp_included_amount' => '0.00',
            'production_fte' => '2.00', 'annual_hours_per_fte' => '1607.00', 'billable_rate' => '65.00',
        ], $member['user_id']);
        self::data($this->send('POST', '/api/v1/diagnostics', []));
        self::assertSame('n2', $this->app->db()->fetchOne("SELECT option_code FROM diagnostic_answers WHERE question_code = 'fin.hourly_cost' AND prefilled_from_profile = 1")['option_code'] ?? null);
        self::assertStringContainsString('Issu de votre profil, modifiable', $this->send('GET', '/app/diagnostic/questionnaire?etape=3')->body);
    }
}
