<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Http\Response;
use App\Infrastructure\Database\OrgId;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;
use Tests\Support\RequestFactory;

/**
 * Parcours du lot 3 : aperçu, scénarios, finalisation figée et chiffrée, versions, dérive, isolation, démo.
 */
final class PricingFlowTest extends DatabaseTestCase
{
    private const ORIGIN = ['Origin' => 'https://agency-os.test'];

    /** Scénario PR-1 (docs/03 §4.3) avec un coût horaire personnalisé de 60 €/h. */
    private const PR1 = [
        'label' => 'Scénario A', 'hourly_cost_mode' => 'custom', 'hourly_cost_custom' => '60.00', 'contingency_pct' => '0.00',
        'target_margin_pct' => '30.00', 'proposed_price' => null,
        'lines' => [['kind' => 'time', 'label' => 'Développement', 'hours' => '40.00'], ['kind' => 'direct_cost', 'label' => 'Thème premium', 'amount' => '300.00']],
    ];

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

    /** @return array{user_id: int, org_id: int, org_public_id: string, service: string} */
    private function agency(string $email, bool $withProfile = true): array
    {
        $member = Fixtures::member($this->app->db(), $email);
        $org = new OrgId($member['org_id']);
        $this->app->organizations()->setOnboardingStep($org, 3, true);
        Fixtures::login($member);
        if ($withProfile) {
            // Profil de HC-1 (coût horaire 67,01 €/h) et tv = 2 %.
            $this->app->financialProfiles()->update($org, [
                'period_type' => 'closed_fiscal_year', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'revenue' => '180000.00',
                'fixed_costs' => '30000.00', 'personnel_costs' => '60000.00', 'variable_costs_general' => '3600.00',
                'owner_comp_total_cost' => '50000.00', 'owner_comp_included_amount' => '0.00', 'production_fte' => '2.00',
                'annual_hours_per_fte' => '1607.00', 'billable_rate' => '65.00',
            ], $member['user_id']);
        }
        $service = $this->app->catalog()->create($org, ['name' => 'Site vitrine', 'category' => 'showcase', 'current_price' => '3200.00', 'annual_volume' => '8'], false);

        return $member + ['service' => $service];
    }

    /** @return array{0: string, 1: string} simulation et scénario A */
    private function simulation(string $service): array
    {
        $created = $this->send('POST', '/api/v1/pricing/simulations', ['service' => $service]);
        self::assertSame(201, $created->status);
        $sim = self::data($created)['public_id'];
        $view = self::data($this->send('GET', "/api/v1/pricing/simulations/{$sim}"));

        return [$sim, $view['scenarios'][0]['public_id']];
    }

    public function testPreviewMatchesPr1AndEchoesSequence(): void
    {
        $a = $this->agency('claire@example.test');
        [$sim] = $this->simulation($a['service']);
        $response = $this->send('POST', '/api/v1/pricing/calculate', self::PR1 + ['simulation' => $sim], ['X-Request-Seq' => '42']);
        self::assertSame(200, $response->status);
        self::assertSame('42', $response->header('X-Request-Seq'));
        $d = self::data($response);
        self::assertSame('simulated', $d['nature']);
        self::assertFalse($d['affects_strategy']);
        self::assertSame('2.00', $d['inputs']['variable_rate_pct']['value']);
        self::assertSame('catalog', $d['inputs']['price_source'], 'Prix testé vide : prix actuel du catalogue.');
        self::assertSame('2755.10', $d['results']['floor_price']);
        self::assertSame('3970.59', $d['results']['recommended_price']);
        self::assertSame('3980', $d['rounded_price'], 'Arrondi commercial proposé à côté, par défaut au pas de 10 €.');
        self::assertSame('436.00', $d['results']['at_price']['result']);
        self::assertSame('13.63', $d['results']['at_price']['margin_on_price_pct']);
        self::assertSame('6164.71', $d['results']['at_price']['annual_shortfall']);
        self::assertSame('price_below_recommended', $d['alerts'][0]['code']);
        self::assertSame(0, $this->countRows('pricing_scenario_lines'), 'L\'aperçu n\'enregistre rien.');

        // Profil suivi : 67,01 €/h calculé, avec hypothèses.
        $profile = self::data($this->send('POST', '/api/v1/pricing/calculate', ['hourly_cost_mode' => 'profile'] + self::PR1));
        self::assertSame('67.01', $profile['inputs']['hourly_cost']['value']);

        // Validation : nombres JSON, marge hors bornes, ligne incomplète, champ inconnu.
        self::assertSame(422, $this->send('POST', '/api/v1/pricing/calculate', ['target_margin_pct' => 30] + self::PR1)->status);
        self::assertSame(422, $this->send('POST', '/api/v1/pricing/calculate', ['target_margin_pct' => '95.00'] + self::PR1)->status);
        self::assertSame(422, $this->send('POST', '/api/v1/pricing/calculate', ['lines' => [['kind' => 'time', 'label' => 'X']]] + self::PR1)->status);
        self::assertSame(422, $this->send('POST', '/api/v1/pricing/calculate', self::PR1 + ['admin' => true])->status);
        $max = self::data($this->send('POST', '/api/v1/pricing/calculate', ['target_margin_pct' => '90.00'] + self::PR1));
        self::assertSame('33750.00', $max['results']['recommended_price'], 'Borne haute : 2 700 ÷ (1 − 0,90 − 0,02).');
        $empty = self::data($this->send('POST', '/api/v1/pricing/calculate', ['lines' => []] + self::PR1));
        self::assertNull($empty['results']);
        self::assertSame('empty_scenario', $empty['error']);
    }

    public function testScenariosFinalizeFreezeEncryptAndVersions(): void
    {
        $a = $this->agency('claire@example.test');
        $org = new OrgId($a['org_id']);
        [$sim, $scenarioA] = $this->simulation($a['service']);
        self::assertSame(200, $this->send('PUT', "/api/v1/pricing/scenarios/{$scenarioA}", self::PR1)->status);
        $b = self::data($this->send('POST', "/api/v1/pricing/simulations/{$sim}/scenarios", ['copy_of' => $scenarioA]))['public_id'];
        self::assertSame(201, $this->send('POST', "/api/v1/pricing/simulations/{$sim}/scenarios", [])->status);
        self::assertSame(422, $this->send('POST', "/api/v1/pricing/simulations/{$sim}/scenarios", [])->status, 'Au plus 3 scénarios.');
        self::assertSame(200, $this->send('PUT', "/api/v1/pricing/scenarios/{$b}", ['label' => 'Avec aléas', 'contingency_pct' => '10.00'] + self::PR1)->status);

        $view = self::data($this->send('GET', "/api/v1/pricing/simulations/{$sim}"));
        self::assertCount(3, $view['scenarios']);
        self::assertTrue($view['scenarios'][0]['is_reference']);
        self::assertSame('4323.53', $view['scenarios'][1]['computed']['results']['recommended_price'], 'PR-2 avec 10 % d\'aléas.');
        self::assertSame(200, $this->send('PATCH', "/api/v1/pricing/simulations/{$sim}", ['reference' => $b])->status);

        $finalized = $this->send('POST', "/api/v1/pricing/simulations/{$sim}/finalize", []);
        self::assertSame(422, $finalized->status, 'Le scénario C (vide) n\'est pas calculable : finalisation refusée.');
        $c = self::data($this->send('GET', "/api/v1/pricing/simulations/{$sim}"))['scenarios'][2]['public_id'];
        self::assertSame(204, $this->send('DELETE', "/api/v1/pricing/scenarios/{$c}")->status);
        self::assertSame(200, $this->send('POST', "/api/v1/pricing/simulations/{$sim}/finalize", [])->status);

        // Lecture seule : 409 sur toute modification.
        self::assertSame(409, $this->send('PUT', "/api/v1/pricing/scenarios/{$scenarioA}", self::PR1)->status);
        self::assertSame(409, $this->send('POST', "/api/v1/pricing/simulations/{$sim}/scenarios", [])->status);
        self::assertSame(409, $this->send('DELETE', "/api/v1/pricing/simulations/{$sim}")->status);
        self::assertSame(409, $this->send('POST', "/api/v1/pricing/simulations/{$sim}/finalize", [])->status);

        // Données figées chiffrées en base (format k<version>:…), jamais en clair.
        $raw = $this->app->db()->fetchOne('SELECT frozen_context_enc FROM pricing_simulations WHERE public_id = :p', ['p' => $sim]);
        self::assertMatchesRegularExpression('/^k\d+\.gcm:/', (string) $raw['frozen_context_enc']);
        self::assertStringNotContainsString('3200', (string) $raw['frozen_context_enc']);
        $rawResults = $this->app->db()->fetchOne('SELECT frozen_results_enc FROM pricing_scenarios WHERE public_id = :p', ['p' => $scenarioA]);
        self::assertStringNotContainsString('3970.59', (string) $rawResults['frozen_results_enc']);

        $frozen = self::data($this->send('GET', "/api/v1/pricing/simulations/{$sim}"));
        self::assertSame('finalized', $frozen['simulation']['status']);
        self::assertTrue($frozen['simulation']['affects_strategy']);
        self::assertSame('3970.59', $frozen['scenarios'][0]['computed']['results']['recommended_price']);
        self::assertSame(['hourly_cost' => false, 'variable_rate' => false], $frozen['drift']);

        // Le profil change : les valeurs figées ne bougent pas, la dérive est signalée (tv utilisé par tous les scénarios).
        $this->app->financialProfiles()->update($org, ['variable_costs_general' => '7200.00'], $a['user_id']);
        $after = self::data($this->send('GET', "/api/v1/pricing/simulations/{$sim}"));
        self::assertSame('3970.59', $after['scenarios'][0]['computed']['results']['recommended_price']);
        self::assertTrue($after['drift']['variable_rate']);
        self::assertFalse($after['drift']['hourly_cost'], 'Coût horaire personnalisé : pas de dérive du coût horaire.');
        self::assertStringContainsString('Hypothèses modifiées depuis la validation', $this->send('GET', "/app/pricing/{$sim}")->body);

        // Nouvelle version : brouillon v2 (une seule à la fois), finalisée → v1 remplacée.
        $v2 = self::data($this->send('POST', "/api/v1/pricing/simulations/{$sim}/new-version", []))['public_id'];
        self::assertSame($v2, self::data($this->send('POST', "/api/v1/pricing/simulations/{$sim}/new-version", []))['public_id']);
        $v2view = self::data($this->send('GET', "/api/v1/pricing/simulations/{$v2}"));
        self::assertSame(2, $v2view['simulation']['version']);
        self::assertSame('Avec aléas', array_values(array_filter($v2view['scenarios'], static fn (array $s): bool => $s['is_reference']))[0]['label']);
        self::assertSame(200, $this->send('POST', "/api/v1/pricing/simulations/{$v2}/finalize", [])->status);
        self::assertSame('superseded', self::data($this->send('GET', "/api/v1/pricing/simulations/{$sim}"))['simulation']['status']);
        self::assertSame(1, $this->countRows('pricing_simulations', "status = 'finalized'"), 'Une seule simulation active par prestation.');
        self::assertSame(200, $this->send('POST', "/api/v1/pricing/simulations/{$v2}/archive", [])->status);
        self::assertSame(0, $this->countRows('pricing_simulations', "status = 'finalized'"));

        $list = self::data($this->send('GET', '/api/v1/pricing/simulations'))['simulations'];
        self::assertCount(2, $list);
        $page = $this->send('GET', "/app/pricing/{$sim}");
        self::assertSame(200, $page->status);
        self::assertStringContainsString('remplacée par une version plus récente', $page->body);
        self::assertStringContainsString('Comparaison des scénarios', $page->body);
        self::assertSame(200, $this->send('GET', '/app/pricing?filtre=archivees')->status);
    }

    public function testDatabaseRefusesTwoActiveSimulationsForOneService(): void
    {
        $a = $this->agency('claire@example.test');
        [$s1] = $this->simulation($a['service']);
        [$s2] = $this->simulation($a['service']);
        $this->app->db()->run("UPDATE pricing_simulations SET status = 'finalized' WHERE public_id = :p", ['p' => $s1]);
        $this->expectException(\PDOException::class);
        $this->app->db()->run("UPDATE pricing_simulations SET status = 'finalized' WHERE public_id = :p", ['p' => $s2]);
    }

    public function testHourlyCostUnavailableBlocksFinalization(): void
    {
        $a = $this->agency('vide@example.test', false);
        [$sim, $scenario] = $this->simulation($a['service']);
        $this->send('PUT', "/api/v1/pricing/scenarios/{$scenario}", ['hourly_cost_mode' => 'profile', 'hourly_cost_custom' => null] + self::PR1);
        $view = self::data($this->send('GET', "/api/v1/pricing/simulations/{$sim}"));
        self::assertSame('hourly_cost_unavailable', $view['scenarios'][0]['computed']['error']);
        self::assertSame(422, $this->send('POST', "/api/v1/pricing/simulations/{$sim}/finalize", [])->status);
        self::assertStringContainsString('Coût horaire non calculable', $this->send('GET', "/app/pricing/{$sim}")->body);
        self::assertSame(422, $this->send('PUT', "/api/v1/pricing/scenarios/{$scenario}", ['hourly_cost_mode' => 'custom', 'hourly_cost_custom' => null] + self::PR1)->status);
    }

    public function testIsolationBetweenAgencies(): void
    {
        $a = $this->agency('a@example.test');
        [$sim, $scenario] = $this->simulation($a['service']);
        $this->send('PUT', "/api/v1/pricing/scenarios/{$scenario}", self::PR1);

        $this->agency('b@example.test');
        self::assertSame(404, $this->send('GET', "/api/v1/pricing/simulations/{$sim}")->status);
        self::assertSame(404, $this->send('PUT', "/api/v1/pricing/scenarios/{$scenario}", self::PR1)->status);
        self::assertSame(404, $this->send('DELETE', "/api/v1/pricing/scenarios/{$scenario}")->status);
        self::assertSame(404, $this->send('POST', "/api/v1/pricing/simulations/{$sim}/finalize", [])->status);
        self::assertSame(404, $this->send('POST', "/api/v1/pricing/simulations/{$sim}/new-version", [])->status);
        self::assertSame(404, $this->send('POST', '/api/v1/pricing/calculate', self::PR1 + ['simulation' => $sim])->status, 'Prix et volume d\'une autre agence inaccessibles.');
        self::assertSame(404, $this->send('GET', "/app/pricing/{$sim}")->status);
        self::assertSame(422, $this->send('POST', '/api/v1/pricing/simulations', ['service' => $a['service']])->status, 'Prestation d\'une autre agence refusée.');
        self::assertGreaterThanOrEqual(6, $this->countRows('security_events', "event = 'authz.foreign_resource'"));
        self::assertSame('draft', $this->app->db()->fetchOne('SELECT status FROM pricing_simulations WHERE public_id = :p', ['p' => $sim])['status']);
        self::assertSame(2, $this->countRows('pricing_scenario_lines'), 'Aucune écriture de B chez A.');
    }

    public function testTemplatesAndCustomSimulationSurvivesServiceDeletion(): void
    {
        $a = $this->agency('claire@example.test');
        $template = self::data($this->send('GET', '/api/v1/pricing/templates/showcase'));
        self::assertStringContainsString('pas une référence de marché', $template['warning']);
        self::assertSame('time', $template['lines'][0]['kind']);
        self::assertSame(404, $this->send('GET', '/api/v1/pricing/templates/inconnu')->status);

        [$sim, $scenario] = $this->simulation($a['service']);
        $this->send('PUT', "/api/v1/pricing/scenarios/{$scenario}", self::PR1);
        $this->send('POST', "/api/v1/pricing/simulations/{$sim}/finalize", []);
        self::assertSame(204, $this->send('DELETE', "/api/v1/services/{$a['service']}")->status);
        $view = self::data($this->send('GET', "/api/v1/pricing/simulations/{$sim}"));
        self::assertNull($view['simulation']['service'], 'Prestation supprimée : la simulation figée reste lisible.');
        self::assertSame('3970.59', $view['scenarios'][0]['computed']['results']['recommended_price']);
        self::assertSame('Site vitrine', $view['frozen_context']['service']['name']);

        $custom = self::data($this->send('POST', '/api/v1/pricing/simulations', ['name' => 'Audit sur mesure']));
        self::assertSame(200, $this->send('GET', '/app/pricing/' . $custom['public_id'])->status);
    }

    public function testDemoHasActiveShowcaseSimulation(): void
    {
        $_SESSION = ['_csrf' => str_repeat('e', 64)];
        self::assertSame(303, $this->app->kernel()->handle(RequestFactory::make('POST', '/demo', self::ORIGIN + ['Cookie' => $this->app->sessions()->cookieName() . '=test'], '', ['_csrf' => str_repeat('e', 64)]))->status);
        $_SESSION['_csrf'] = str_repeat('e', 64);
        $list = self::data($this->send('GET', '/api/v1/pricing/simulations'))['simulations'];
        $statuses = array_column($list, 'status', 'name');
        self::assertSame('finalized', $statuses['Site vitrine WordPress']);
        self::assertSame('draft', $statuses['Refonte de site']);
        $page = $this->send('GET', '/app/pricing');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Valeurs fictives de démonstration', $page->body);
        $showcase = array_values(array_filter($list, static fn (array $s): bool => $s['status'] === 'finalized'))[0];
        self::assertStringStartsWith('-', (string) $showcase['gap_pct'], 'Prix actuel 3 200 € sous le prix recommandé.');
        self::assertSame(200, $this->send('GET', '/app/pricing/' . $showcase['public_id'])->status);
    }
}
