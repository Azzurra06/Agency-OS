<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\DemoService;
use App\Http\Response;
use App\Infrastructure\Database\OrgId;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;
use Tests\Support\RequestFactory;

/**
 * Parcours du lot 5 : évaluation automatique après enregistrement, réconciliation (création, mise à jour,
 * résolution automatique, réapparition), cycle de vie, plan d'action, chiffrement des preuves, isolation, démo.
 */
final class StrategyFlowTest extends DatabaseTestCase
{
    private const ORIGIN = ['Origin' => 'https://agency-os.test'];
    private const PR1 = [
        'label' => 'Scénario A', 'hourly_cost_mode' => 'custom', 'hourly_cost_custom' => '60.00', 'contingency_pct' => '0.00', 'target_margin_pct' => '30.00', 'proposed_price' => null,
        'lines' => [['kind' => 'time', 'label' => 'Développement', 'hours' => '40.00'], ['kind' => 'direct_cost', 'label' => 'Thème', 'amount' => '300.00']],
    ];

    private function send(string $method, string $path, ?array $json = null): Response
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

        return $this->app->kernel()->handle(RequestFactory::make($method, $path, $headers, $body));
    }

    /** @return array<string, mixed> */
    private static function data(Response $response): array
    {
        return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['data'];
    }

    /** @return array<string, array<string, mixed>> recommandations d'un onglet, par code de règle */
    private function recos(string $status = 'new'): array
    {
        $out = [];
        foreach (self::data($this->send('GET', '/api/v1/recommendations?status=' . $status))['items'] as $item) {
            $out[$item['rule_code']] = $item;
        }

        return $out;
    }

    /** Agence du cas RULE-1 : PR-1 finalisée pour « Site vitrine » (prix 3 200 €, 8 ventes par an). */
    private function rule1Agency(string $email, bool $finalize = true): array
    {
        $member = Fixtures::member($this->app->db(), $email);
        $org = new OrgId($member['org_id']);
        $this->app->organizations()->setOnboardingStep($org, 3, true);
        Fixtures::login($member);
        $this->send('PUT', '/api/v1/financial-profile', [
            'period_type' => 'closed_fiscal_year', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'revenue' => '180000.00',
            'fixed_costs' => '30000.00', 'personnel_costs' => '60000.00', 'variable_costs_general' => '3600.00', 'variable_costs_attributable' => '0.00',
            'owner_comp_total_cost' => '50000.00', 'owner_comp_included_amount' => '0.00', 'production_fte' => '2.00', 'annual_hours_per_fte' => '1607.00',
            'billable_rate' => '65.00', 'recurring_share_declared' => '25.00',
        ]);
        $service = self::data($this->send('POST', '/api/v1/services', ['name' => 'Site vitrine', 'category' => 'showcase', 'current_price' => '3200.00', 'annual_volume' => '8']))['public_id'];
        $sim = self::data($this->send('POST', '/api/v1/pricing/simulations', ['service' => $service]))['public_id'];
        $scenario = self::data($this->send('GET', "/api/v1/pricing/simulations/{$sim}"))['scenarios'][0]['public_id'];
        $this->send('PUT', "/api/v1/pricing/scenarios/{$scenario}", self::PR1);
        if ($finalize) {
            self::assertSame(200, $this->send('POST', "/api/v1/pricing/simulations/{$sim}/finalize", [])->status);
        }

        return $member + ['service' => $service, 'sim' => $sim];
    }

    public function testRule1To4EndToEndWithReconciliation(): void
    {
        // RULE-4 : simulation encore en brouillon → seulement NO_PRICING.
        $a = $this->rule1Agency('claire@example.test', false);
        $recos = $this->recos();
        self::assertArrayNotHasKey('PRICE_UNDER', $recos);
        self::assertArrayHasKey('NO_PRICING', $recos);
        self::assertArrayHasKey('NO_DIAGNOSTIC', $recos);

        // RULE-1 : la finalisation déclenche l'évaluation.
        $this->send('POST', "/api/v1/pricing/simulations/{$a['sim']}/finalize", []);
        $recos = $this->recos();
        $price = $recos['PRICE_UNDER'];
        self::assertSame(45, $price['priority']);
        self::assertSame('medium', $price['severity']);
        self::assertSame('6164.71', $price['impact_amount']);
        self::assertSame('-19.4074', $price['values']['price_gap_rate']);
        self::assertStringContainsString('Simulation « Site vitrine » v1, figée le', implode(' ', $price['sources']));
        self::assertArrayNotHasKey('NO_PRICING', $recos, 'Condition disparue : résolue automatiquement.');
        self::assertArrayHasKey('NO_PRICING', $this->recos('resolved'));

        // Preuves et impact chiffrés en base, aucun montant en clair.
        $raw = $this->app->db()->fetchOne("SELECT evidence_enc, impact_amount_enc FROM recommendations WHERE rule_code = 'PRICE_UNDER'");
        self::assertMatchesRegularExpression('/^k\d+\.gcm:/', (string) $raw['evidence_enc']);
        self::assertStringNotContainsString('3970', (string) $raw['evidence_enc']);
        self::assertStringNotContainsString('6164', (string) $raw['impact_amount_enc']);

        // Acceptation : action avec ses sous-actions.
        $action = self::data($this->send('POST', "/api/v1/recommendations/{$price['public_id']}/accept", []))['action'];
        $plan = self::data($this->send('GET', '/api/v1/action-plan'));
        $item = $plan['groups']['todo'][0];
        self::assertSame($action, $item['public_id']);
        self::assertCount(4, $item['tasks']);
        self::assertArrayHasKey('PRICE_UNDER', $this->recos('accepted'));

        // RULE-3 : prix relevé à 4 000 € → résolue automatiquement, l'action affiche « objectif atteint ».
        $this->send('PATCH', "/api/v1/services/{$a['service']}", ['current_price' => '4000.00']);
        self::assertSame('auto_resolved', $this->recos('resolved')['PRICE_UNDER']['status']);
        self::assertTrue(self::data($this->send('GET', '/api/v1/action-plan'))['groups']['todo'][0]['goal_reached']);
        self::assertStringContainsString('Objectif atteint', $this->send('GET', '/app/strategie/plan')->body);

        // Le problème revient : « réapparue ».
        $this->send('PATCH', "/api/v1/services/{$a['service']}", ['current_price' => '3200.00']);
        $back = $this->recos()['PRICE_UNDER'];
        self::assertTrue($back['reappeared']);
        self::assertSame('new', $back['status']);
    }

    public function testRule2DiagnosticBonus(): void
    {
        $this->rule1Agency('claire@example.test');
        $id = self::data($this->send('POST', '/api/v1/diagnostics', []))['public_id'];
        foreach (DemoService::LUMEN_DIAGNOSTIC as $question => $option) {
            $this->send('PUT', "/api/v1/diagnostics/{$id}/answers", ['question' => $question, 'option' => $option]);
        }
        $this->send('POST', "/api/v1/diagnostics/{$id}/complete", []); // D3 = 40, D6 = 33
        $recos = $this->recos();
        self::assertSame(51, $recos['PRICE_UNDER']['priority'], 'RULE-2 : 45 + arrondi(10 × 0,6).');
        self::assertSame(52, $recos['DIM_LOW_D6']['priority'], '45 + arrondi(10 × 0,667).');
        self::assertArrayNotHasKey('NO_DIAGNOSTIC', $recos);
    }

    public function testDismissSnoozeAndSeverityIncrease(): void
    {
        $a = $this->rule1Agency('claire@example.test');
        $price = $this->recos()['PRICE_UNDER'];
        self::assertSame(200, $this->send('POST', "/api/v1/recommendations/{$price['public_id']}/dismiss", [])->status);
        self::assertArrayHasKey('PRICE_UNDER', $this->recos('dismissed'));
        $this->send('POST', '/api/v1/recommendations/evaluate', []);
        self::assertArrayHasKey('PRICE_UNDER', $this->recos('dismissed'), 'Même sévérité : reste ignorée.');
        $this->send('PATCH', "/api/v1/services/{$a['service']}", ['current_price' => '3150.00']); // écart −20,7 % → high
        $back = $this->recos()['PRICE_UNDER'];
        self::assertSame('high', $back['severity'], 'Sévérité augmentée : la recommandation ignorée revient.');

        $snoozed = self::data($this->send('POST', "/api/v1/recommendations/{$back['public_id']}/snooze", ['days' => '30']));
        self::assertSame('2026-12-12', $snoozed['snoozed_until']);
        self::assertSame(422, $this->send('POST', "/api/v1/recommendations/{$back['public_id']}/snooze", ['days' => '45'])->status);
        $this->app->db()->run("UPDATE recommendations SET snoozed_until = '2026-11-01' WHERE rule_code = 'PRICE_UNDER'");
        $this->send('POST', '/api/v1/recommendations/evaluate', []);
        self::assertArrayHasKey('PRICE_UNDER', $this->recos(), 'Report échu : retour dans les nouvelles.');
        self::assertSame(200, $this->send('GET', '/app/strategie?onglet=resolues&module=pricing')->status);
    }

    public function testActionPlan(): void
    {
        $this->rule1Agency('claire@example.test');
        $price = $this->recos()['PRICE_UNDER'];
        $action = self::data($this->send('POST', "/api/v1/recommendations/{$price['public_id']}/accept", []))['action'];
        self::assertSame($action, self::data($this->send('POST', "/api/v1/recommendations/{$price['public_id']}/accept", []))['action'], 'Pas de doublon.');
        $task = self::data($this->send('GET', '/api/v1/action-plan'))['groups']['todo'][0]['tasks'][0]['public_id'];
        self::assertSame(200, $this->send('PATCH', "/api/v1/action-tasks/{$task}", ['is_done' => true])->status);
        $newTask = self::data($this->send('POST', "/api/v1/action-items/{$action}/tasks", ['label' => 'Prévenir les clients existants']))['public_id'];
        self::assertSame(204, $this->send('DELETE', "/api/v1/action-tasks/{$newTask}")->status);
        self::assertSame(200, $this->send('PATCH', "/api/v1/action-items/{$action}", ['status' => 'done'])->status);
        self::assertSame('done', $this->recos('resolved')['PRICE_UNDER']['status'], 'Action terminée : recommandation terminée.');

        $free = self::data($this->send('POST', '/api/v1/action-items', ['title' => 'Relancer les devis en attente', 'due_date' => '2026-11-01']))['public_id'];
        $plan = self::data($this->send('GET', '/api/v1/action-plan'));
        self::assertTrue($plan['groups']['todo'][0]['overdue'], 'Échéance passée : en retard.');
        self::assertSame(['done' => 1, 'total' => 2, 'pct' => 50], $plan['progress']);
        self::assertSame(422, $this->send('POST', '/api/v1/action-items', ['title' => 'X'])->status);
        self::assertSame(422, $this->send('PATCH', "/api/v1/action-items/{$free}", ['status' => 'archived'])->status);
        self::assertSame(204, $this->send('DELETE', "/api/v1/action-items/{$free}")->status);
        self::assertSame(200, $this->send('GET', '/app/strategie/plan')->status);
        self::assertStringContainsString("6\u{202F}165", $this->send('GET', '/app/strategie?onglet=resolues')->body, 'Impact chiffré affiché sur la carte.');
    }

    public function testIsolationBetweenAgencies(): void
    {
        $this->rule1Agency('a@example.test');
        $price = $this->recos()['PRICE_UNDER'];
        $action = self::data($this->send('POST', "/api/v1/recommendations/{$price['public_id']}/accept", []))['action'];
        $task = self::data($this->send('GET', '/api/v1/action-plan'))['groups']['todo'][0]['tasks'][0]['public_id'];

        $b = Fixtures::member($this->app->db(), 'b@example.test');
        Fixtures::login($b);
        foreach ([['POST', "/api/v1/recommendations/{$price['public_id']}/accept"], ['POST', "/api/v1/recommendations/{$price['public_id']}/dismiss"],
            ['POST', "/api/v1/recommendations/{$price['public_id']}/reopen"], ['DELETE', "/api/v1/action-items/{$action}"], ['DELETE', "/api/v1/action-tasks/{$task}"]] as [$method, $path]) {
            self::assertSame(404, $this->send($method, $path, $method === 'POST' ? [] : null)->status, $path);
        }
        self::assertSame(404, $this->send('PATCH', "/api/v1/action-items/{$action}", ['status' => 'done'])->status);
        self::assertSame(404, $this->send('PATCH', "/api/v1/action-tasks/{$task}", ['is_done' => true])->status);
        self::assertSame([], self::data($this->send('GET', '/api/v1/action-plan'))['groups']['todo']);
        self::assertArrayNotHasKey('PRICE_UNDER', $this->recos('accepted'));
        self::assertGreaterThanOrEqual(7, $this->countRows('security_events', "event = 'authz.foreign_resource'"));
        self::assertSame('accepted', $this->app->db()->fetchOne('SELECT status FROM recommendations WHERE public_id = :p', ['p' => $price['public_id']])['status']);
    }

    public function testDemoStrategy(): void
    {
        $_SESSION = ['_csrf' => str_repeat('e', 64)];
        self::assertSame(303, $this->app->kernel()->handle(RequestFactory::make('POST', '/demo', self::ORIGIN + ['Cookie' => $this->app->sessions()->cookieName() . '=test'], '', ['_csrf' => str_repeat('e', 64)]))->status);
        $_SESSION['_csrf'] = str_repeat('e', 64);
        $all = array_merge($this->recos(), $this->recos('accepted'));
        foreach (['PRICE_UNDER', 'MAINT_UNPROFITABLE', 'TARGET_GAP', 'HOURLY_ASSUMPTIONS', 'DIM_LOW_D6', 'NO_PRICING'] as $code) {
            self::assertArrayHasKey($code, $all, $code);
        }
        self::assertSame('high', $all['PRICE_UNDER']['severity'], 'Lumen : 3 200 € soit −27 % sous le prix recommandé.');
        $plan = self::data($this->send('GET', '/api/v1/action-plan'));
        self::assertSame(2, $plan['progress']['total']);
        self::assertCount(1, $plan['groups']['in_progress']);
        $dashboard = $this->send('GET', '/app')->body;
        self::assertStringContainsString('Plan d\'action', $dashboard);
        self::assertStringNotContainsString('Bientôt vos recommandations', $dashboard);
        self::assertSame(200, $this->send('GET', '/app/strategie')->status);
    }
}
