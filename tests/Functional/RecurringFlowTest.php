<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Http\Response;
use App\Infrastructure\Database\OrgId;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;
use Tests\Support\RequestFactory;

/**
 * Parcours du lot 4 : formules et contrats (réel), synthèse et couverture, scénarios (simulé), cycle de vie, isolation, démo.
 */
final class RecurringFlowTest extends DatabaseTestCase
{
    private const ORIGIN = ['Origin' => 'https://agency-os.test'];
    private const REC1 = ['name' => 'Maintenance mensuelle', 'kind' => 'maintenance', 'billing_period' => 'monthly', 'price' => '150.00', 'hours_per_month' => '1.50', 'external_costs_per_month' => '10.00', 'active_contracts' => '12', 'items' => ['Mises à jour', 'Sauvegardes']];
    private const REC2 = ['name' => 'Maintenance annuelle', 'kind' => 'maintenance', 'billing_period' => 'annual', 'price' => '1200.00', 'hours_per_month' => '1.50', 'external_costs_per_month' => '10.00', 'active_contracts' => '5'];

    private function send(string $method, string $path, ?array $json = null, array $headers = []): Response
    {
        $headers += self::ORIGIN;
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

    /** Agence au profil de RES-1 / HC-1, avec un coût horaire forcé à 60 €/h (cas REC-1) et tv = 2 %. */
    private function agency(string $email, string $declaredShare = '30.00'): array
    {
        $member = Fixtures::member($this->app->db(), $email);
        $org = new OrgId($member['org_id']);
        $this->app->organizations()->setOnboardingStep($org, 3, true);
        Fixtures::login($member);
        $this->app->financialProfiles()->update($org, [
            'period_type' => 'closed_fiscal_year', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'revenue' => '180000.00',
            'fixed_costs' => '30000.00', 'personnel_costs' => '60000.00', 'variable_costs_general' => '3600.00',
            'owner_comp_total_cost' => '50000.00', 'owner_comp_included_amount' => '0.00', 'production_fte' => '2.00',
            'annual_hours_per_fte' => '1607.00', 'billable_rate' => '65.00', 'hourly_cost_override' => '60.00', 'recurring_share_declared' => $declaredShare,
        ], $member['user_id']);

        return $member;
    }

    public function testOffersSummaryCoverageAndExplicitShareUpdate(): void
    {
        $this->agency('claire@example.test');
        $preview = self::data($this->send('POST', '/api/v1/recurring/calculate', ['type' => 'offer'] + self::REC1, ['X-Request-Seq' => '7']));
        self::assertSame('47.00', $preview['monthly_result'], 'REC-1');
        self::assertSame('31.33', $preview['margin_on_price_pct']);
        self::assertSame('102.04', $preview['floor_price']);
        self::assertSame(0, $this->countRows('recurring_offers'), 'L\'aperçu n\'enregistre rien.');

        $a = self::data($this->send('POST', '/api/v1/recurring/offers', self::REC1))['public_id'];
        $b = self::data($this->send('POST', '/api/v1/recurring/offers', self::REC2))['public_id'];
        $offer = self::data($this->send('GET', "/api/v1/recurring/offers/{$b}"));
        self::assertSame('-2.00', $offer['economics']['monthly_result'], 'REC-2 : déficitaire.');
        self::assertTrue($offer['economics']['unprofitable']);
        self::assertSame(['Mises à jour', 'Sauvegardes'], self::data($this->send('GET', "/api/v1/recurring/offers/{$a}"))['items']);

        $summary = self::data($this->send('GET', '/api/v1/recurring/summary'));
        self::assertSame('2300.00', $summary['mrr'], 'REC-3');
        self::assertSame('27600.00', $summary['arr']);
        self::assertSame('15.33', $summary['coverage_pct']);
        self::assertSame('30.00', $summary['declared_share_pct'], 'La part déclarée n\'est jamais modifiée automatiquement.');
        self::assertSame('14.67', $summary['gap_points']);
        self::assertTrue($summary['gap_alert']);
        self::assertSame('25.50', $summary['hours_needed'], 'REC-5');
        self::assertSame('14.65', $summary['capacity_use_pct']);
        self::assertSame(['Maintenance annuelle'], $summary['unprofitable']);
        $page = $this->send('GET', '/app/recurrent');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Réel : contrats signés', $page->body);
        self::assertStringContainsString('Prévisionnel : scénarios', $page->body);
        self::assertStringContainsString('Remplacer la part déclarée', $page->body);

        // Contrats signés : mise à jour sur place, validation.
        self::assertSame(200, $this->send('PATCH', "/api/v1/recurring/offers/{$a}/contracts", ['active_contracts' => '14'])->status);
        self::assertSame(422, $this->send('PATCH', "/api/v1/recurring/offers/{$a}/contracts", ['active_contracts' => '-1'])->status);
        self::assertSame('2600.00', self::data($this->send('GET', '/api/v1/recurring/summary'))['mrr']);
        self::assertSame(422, $this->send('POST', '/api/v1/recurring/offers', ['price' => 150] + self::REC1)->status, 'Décimal en nombre JSON refusé.');

        // Mise à jour EXPLICITE de la part déclarée, journalisée.
        $accepted = self::data($this->send('POST', '/api/v1/financial-profile/recurring-share/accept-computed', []));
        self::assertSame('17.33', $accepted['recurring_share_declared']);
        self::assertSame(1, $this->countRows('financial_profile_changes', "origin = 'accept_computed_share' AND field = 'recurring_share_declared'"));
        self::assertFalse(self::data($this->send('GET', '/api/v1/recurring/summary'))['gap_alert']);
    }

    public function testScenarioProjectionLifecycleAndRealNeverMixed(): void
    {
        $this->agency('claire@example.test');
        $a = self::data($this->send('POST', '/api/v1/recurring/offers', ['active_contracts' => '10'] + self::REC1))['public_id'];
        $id = self::data($this->send('POST', '/api/v1/recurring/scenarios', ['name' => 'REC-4', 'horizon' => '12']))['public_id'];
        $view = self::data($this->send('GET', "/api/v1/recurring/scenarios/{$id}"));
        self::assertSame('10.00', $view['entries'][0]['starting_clients'], 'Départ par défaut : les contrats signés.');

        $rec4 = ['name' => 'REC-4', 'horizon' => '12', 'entries' => [['offer' => $a, 'starting_clients' => '10.00', 'new_clients_per_month' => '2.00', 'monthly_churn_pct' => '3.00']]];
        $preview = self::data($this->send('POST', '/api/v1/recurring/calculate', ['type' => 'projection'] + $rec4));
        self::assertSame('1755.00', $preview['months'][0]['mrr']);
        self::assertSame('4102.34', $preview['months'][11]['mrr']);
        self::assertSame('27', $preview['months'][11]['clients_rounded']);
        self::assertSame('35857.68', $preview['cumulative']);
        self::assertSame('14095.14', $preview['cumulative_6']);
        self::assertSame(200, $this->send('PUT', "/api/v1/recurring/scenarios/{$id}", $rec4)->status);

        $projection = self::data($this->send('GET', "/api/v1/recurring/scenarios/{$id}/projection"));
        self::assertSame('simulated', $projection['nature']);
        self::assertSame('1500.00', self::data($this->send('GET', '/api/v1/recurring/summary'))['mrr'], 'Le MRR réel ignore les scénarios.');

        self::assertSame(200, $this->send('POST', "/api/v1/recurring/scenarios/{$id}/finalize", [])->status);
        self::assertSame(409, $this->send('PUT', "/api/v1/recurring/scenarios/{$id}", $rec4)->status);
        self::assertSame(409, $this->send('DELETE', "/api/v1/recurring/scenarios/{$id}")->status);
        $raw = $this->app->db()->fetchOne('SELECT frozen_results_enc FROM recurring_scenarios WHERE public_id = :p', ['p' => $id]);
        self::assertMatchesRegularExpression('/^k\d+\.gcm:/', (string) $raw['frozen_results_enc']);
        self::assertStringNotContainsString('4102.34', (string) $raw['frozen_results_enc']);

        // La formule change (donnée réelle) : la projection figée ne bouge pas.
        $this->send('PATCH', "/api/v1/recurring/offers/{$a}", ['price' => '200.00'] + self::REC1);
        $frozen = self::data($this->send('GET', "/api/v1/recurring/scenarios/{$id}"));
        self::assertSame('finalized', $frozen['scenario']['status']);
        self::assertSame('4102.34', $frozen['projection']['months'][11]['mrr']);
        self::assertStringContainsString('Dupliquer pour modifier', $this->send('GET', "/app/recurrent/scenarios/{$id}")->body);

        // Dupliquer pour modifier : brouillon v2 recalculé avec le nouveau prix ; sa finalisation remplace v1.
        $v2 = self::data($this->send('POST', "/api/v1/recurring/scenarios/{$id}/new-version", []))['public_id'];
        $v2view = self::data($this->send('GET', "/api/v1/recurring/scenarios/{$v2}"));
        self::assertSame(2, $v2view['scenario']['version']);
        self::assertSame('5469.79', $v2view['projection']['months'][11]['mrr'], '27,3489… clients × 200 €.');
        self::assertSame(200, $this->send('POST', "/api/v1/recurring/scenarios/{$v2}/finalize", [])->status);
        self::assertSame('superseded', self::data($this->send('GET', "/api/v1/recurring/scenarios/{$id}"))['scenario']['status']);
        self::assertSame(200, $this->send('POST', "/api/v1/recurring/scenarios/{$v2}/archive", [])->status);

        // Formule supprimée : le scénario figé reste lisible.
        self::assertSame(204, $this->send('DELETE', "/api/v1/recurring/offers/{$a}")->status);
        $after = self::data($this->send('GET', "/api/v1/recurring/scenarios/{$id}"));
        self::assertSame('4102.34', $after['projection']['months'][11]['mrr']);
        self::assertSame(200, $this->send('GET', "/app/recurrent/scenarios/{$id}")->status);
        self::assertSame(422, $this->send('POST', '/api/v1/recurring/scenarios', [])->status, 'Aucune formule : pas de scénario.');
    }

    public function testValidation(): void
    {
        $this->agency('claire@example.test');
        $a = self::data($this->send('POST', '/api/v1/recurring/offers', self::REC1))['public_id'];
        $id = self::data($this->send('POST', '/api/v1/recurring/scenarios', []))['public_id'];
        $bad = ['name' => 'X', 'horizon' => '24', 'entries' => [['offer' => $a, 'starting_clients' => '1', 'new_clients_per_month' => '1', 'monthly_churn_pct' => '3']]];
        self::assertSame(422, $this->send('PUT', "/api/v1/recurring/scenarios/{$id}", $bad)->status, 'Horizon de 6 ou 12 mois.');
        $bad['horizon'] = '12';
        $bad['entries'][0]['monthly_churn_pct'] = '150';
        self::assertSame(422, $this->send('PUT', "/api/v1/recurring/scenarios/{$id}", $bad)->status);
        $bad['entries'][0]['monthly_churn_pct'] = '3';
        $bad['entries'][] = $bad['entries'][0];
        self::assertSame(422, $this->send('PUT', "/api/v1/recurring/scenarios/{$id}", $bad)->status, 'Formule en double.');
        self::assertSame(422, $this->send('POST', '/api/v1/recurring/offers', ['billing_period' => 'weekly'] + self::REC1)->status);
        self::assertSame(422, $this->send('POST', '/api/v1/recurring/offers', ['items' => array_fill(0, 25, 'x')] + self::REC1)->status);
        self::assertSame(422, $this->send('POST', '/api/v1/recurring/calculate', ['type' => 'autre'])->status);
    }

    public function testIsolationBetweenAgencies(): void
    {
        $this->agency('a@example.test');
        $offer = self::data($this->send('POST', '/api/v1/recurring/offers', self::REC1))['public_id'];
        $scenario = self::data($this->send('POST', '/api/v1/recurring/scenarios', []))['public_id'];

        $this->agency('b@example.test');
        $mine = self::data($this->send('POST', '/api/v1/recurring/offers', self::REC2))['public_id'];
        $myScenario = self::data($this->send('POST', '/api/v1/recurring/scenarios', []))['public_id'];
        self::assertSame(404, $this->send('GET', "/api/v1/recurring/offers/{$offer}")->status);
        self::assertSame(404, $this->send('PATCH', "/api/v1/recurring/offers/{$offer}", self::REC1)->status);
        self::assertSame(404, $this->send('PATCH', "/api/v1/recurring/offers/{$offer}/contracts", ['active_contracts' => '0'])->status);
        self::assertSame(404, $this->send('DELETE', "/api/v1/recurring/offers/{$offer}")->status);
        self::assertSame(404, $this->send('GET', "/api/v1/recurring/scenarios/{$scenario}")->status);
        self::assertSame(404, $this->send('POST', "/api/v1/recurring/scenarios/{$scenario}/finalize", [])->status);
        self::assertSame(404, $this->send('GET', "/app/recurrent/formules/{$offer}")->status);
        self::assertSame(404, $this->send('GET', "/app/recurrent/scenarios/{$scenario}")->status);
        $foreign = ['name' => 'X', 'horizon' => '12', 'entries' => [['offer' => $offer, 'starting_clients' => '1', 'new_clients_per_month' => '0', 'monthly_churn_pct' => '0']]];
        self::assertSame(422, $this->send('PUT', "/api/v1/recurring/scenarios/{$myScenario}", $foreign)->status, 'Formule d\'une autre agence refusée dans un scénario.');
        self::assertSame(422, $this->send('POST', '/api/v1/recurring/calculate', ['type' => 'projection'] + $foreign)->status);
        self::assertSame('500.00', self::data($this->send('GET', '/api/v1/recurring/summary'))['mrr'], 'B ne voit que ses contrats (5 × 1 200 € ÷ 12).');
        self::assertGreaterThanOrEqual(6, $this->countRows('security_events', "event = 'authz.foreign_resource'"));
        self::assertSame(12, (int) $this->app->db()->fetchOne('SELECT active_contracts FROM recurring_offers WHERE public_id = :p', ['p' => $offer])['active_contracts']);
        self::assertNotNull($mine);
    }

    public function testDemoRecurring(): void
    {
        $_SESSION = ['_csrf' => str_repeat('e', 64)];
        self::assertSame(303, $this->app->kernel()->handle(RequestFactory::make('POST', '/demo', self::ORIGIN + ['Cookie' => $this->app->sessions()->cookieName() . '=test'], '', ['_csrf' => str_repeat('e', 64)]))->status);
        $_SESSION['_csrf'] = str_repeat('e', 64);
        $summary = self::data($this->send('GET', '/api/v1/recurring/summary'));
        self::assertSame('2660.00', $summary['mrr']);
        self::assertSame(['Maintenance annuelle'], $summary['unprofitable']);
        $statuses = array_column(self::data($this->send('GET', '/api/v1/recurring/scenarios'))['scenarios'], 'status', 'name');
        self::assertSame('finalized', $statuses['Croissance maintenance']);
        self::assertSame('draft', $statuses['Hypothèse prudente']);
        self::assertSame(200, $this->send('GET', '/app/recurrent')->status);
        self::assertStringContainsString('2' . "\u{202F}" . '660', $this->send('GET', '/app')->body, 'Vue d\'ensemble : MRR contractualisé.');
    }
}
