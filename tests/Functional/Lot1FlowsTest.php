<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Http\Response;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;
use Tests\Support\RequestFactory;

/**
 * Parcours HTTP du lot 1 : démo, connexion, onboarding, profil financier, prestations, isolation.
 */
final class Lot1FlowsTest extends DatabaseTestCase
{
    private const ORIGIN = ['Origin' => 'https://agency-os.test'];
    private const TEST_PASSWORD = 'mot-de-passe-de-test-123';
    private const WRONG_PASSWORD = 'faux-mot-de-passe';

    private function send(string $method, string $path, array $json = null, array $form = [], array $headers = []): Response
    {
        $headers += self::ORIGIN;
        if (!isset($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = str_repeat('e', 64);
        }
        $headers['X-CSRF-Token'] = (string) $_SESSION['_csrf'];
        $body = '';
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($json, JSON_THROW_ON_ERROR);
        }
        $headers['Cookie'] = $this->app->sessions()->cookieName() . '=test';

        return $this->app->kernel()->handle(RequestFactory::make($method, $path, $headers, $body, $form));
    }

    private function startDemo(): void
    {
        $_SESSION = ['_csrf' => str_repeat('e', 64)];
        $response = $this->send('POST', '/demo', form: ['_csrf' => str_repeat('e', 64)]);
        self::assertSame(303, $response->status);
        self::assertSame('/app', $response->header('Location'));
        $_SESSION['_csrf'] = str_repeat('e', 64);
    }

    public function testDemoJourneyShowsFictitiousDashboard(): void
    {
        $this->startDemo();
        $page = $this->send('GET', '/app');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Démonstration', $page->body);
        self::assertStringContainsString("24\u{202F}400", $page->body, 'Résultat estimé RES-1.');
        self::assertStringContainsString("67,01\u{00A0}€", $page->body, 'Coût horaire HC-1.');
        self::assertStringContainsString('Exercice clos · 01/01/2025 → 31/12/2025 · 12 mois', $page->body);
        self::assertStringContainsString('N\'indiquez pas les chiffres réels', $this->send('GET', '/app/parametres/finances')->body);
        foreach (['/app/bienvenue', '/app/bienvenue?etape=2', '/app/parametres/agence', '/app/parametres/prestations', '/app/diagnostic', '/app/pricing'] as $path) {
            self::assertSame(200, $this->send('GET', $path)->status, $path);
        }
    }

    public function testDemoResetAndQuit(): void
    {
        $this->startDemo();
        $this->send('PUT', '/api/v1/financial-profile', ['revenue' => '1.00']);
        self::assertSame(303, $this->send('POST', '/demo/reinitialiser', form: ['_csrf' => str_repeat('e', 64)])->status);
        $data = json_decode($this->send('GET', '/api/v1/financial-profile')->body, true)['data'];
        self::assertSame('180000.00', $data['values']['revenue']);
        self::assertSame(303, $this->send('POST', '/demo/quitter', form: ['_csrf' => str_repeat('e', 64)])->status);
        self::assertSame([], $_SESSION);
    }

    public function testLoginWithTestAccountAndOnboardingRedirect(): void
    {
        $this->app->auth()->createTestAccount('claire@example.test', self::TEST_PASSWORD, 'Agence Claire');
        $_SESSION = ['_csrf' => str_repeat('e', 64)];
        $bad = $this->send('POST', '/connexion', form: ['_csrf' => str_repeat('e', 64), 'email' => 'claire@example.test', 'password' => self::WRONG_PASSWORD]);
        self::assertSame('/connexion', $bad->header('Location'));
        self::assertSame('Identifiants invalides.', $_SESSION['_flash']['message']);

        $ok = $this->send('POST', '/connexion', form: ['_csrf' => str_repeat('e', 64), 'email' => 'claire@example.test', 'password' => self::TEST_PASSWORD]);
        self::assertSame('/app', $ok->header('Location'));
        $_SESSION['_csrf'] = str_repeat('e', 64);
        self::assertSame('/app/bienvenue', $this->send('GET', '/app')->header('Location'), 'Onboarding non terminé : redirection.');

        self::assertSame(200, $this->send('PATCH', '/api/v1/organization', ['name' => 'Agence Claire', 'team_size' => '3', 'activity_type' => 'wordpress'])->status);
        self::assertSame(200, $this->send('PATCH', '/api/v1/organization/onboarding', ['step' => 3, 'completed' => true])->status);
        self::assertSame(200, $this->send('GET', '/app')->status);
        self::assertStringContainsString('Non calculable', $this->send('GET', '/app')->body, 'Profil vide : jamais de 0 trompeur.');
    }

    public function testLoginRequiresCsrf(): void
    {
        $this->app->auth()->createTestAccount('claire@example.test', self::TEST_PASSWORD, 'Agence Claire');
        $_SESSION = ['_csrf' => str_repeat('e', 64)];
        $response = $this->app->kernel()->handle(RequestFactory::make('POST', '/connexion', self::ORIGIN, '', ['email' => 'claire@example.test', 'password' => self::TEST_PASSWORD]));
        self::assertSame(403, $response->status);
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testFinancialProfileApiValidation(): void
    {
        $this->startDemo();
        $response = $this->send('PUT', '/api/v1/financial-profile', ['revenue' => 180000]);
        self::assertSame(422, $response->status);
        self::assertSame(['revenue' => 'decimal_must_be_string'], json_decode($response->body, true)['error']['fields']);

        $response = $this->send('PUT', '/api/v1/financial-profile', ['organization_id' => 1, 'revenue' => '1.00']);
        self::assertSame('unknown_field', json_decode($response->body, true)['error']['fields']['organization_id']);

        $invalidJson = $this->app->kernel()->handle(RequestFactory::make('PUT', '/api/v1/financial-profile',
            self::ORIGIN + ['X-CSRF-Token' => str_repeat('e', 64), 'Content-Type' => 'application/json', 'Cookie' => $this->app->sessions()->cookieName() . '=x'], '{"revenue":'));
        self::assertSame(400, $invalidJson->status);

        $ok = $this->send('PUT', '/api/v1/financial-profile', ['revenue' => '200000.00']);
        self::assertSame(200, $ok->status);
        self::assertSame('44400.00', json_decode($ok->body, true)['data']['derived']['estimated_result']['value']);
        $changes = json_decode($this->send('GET', '/api/v1/financial-profile/changes')->body, true)['data']['changes'];
        self::assertSame('revenue', $changes[0]['field']);
    }

    public function testServicesAreIsolatedAndProbingIsLogged(): void
    {
        $a = Fixtures::member($this->app->db(), 'a@example.test');
        Fixtures::login($a);
        $created = $this->send('POST', '/api/v1/services', ['name' => 'Site vitrine', 'category' => 'showcase', 'current_price' => '3200.00']);
        self::assertSame(201, $created->status);
        $serviceId = json_decode($created->body, true)['data']['public_id'];

        $b = Fixtures::member($this->app->db(), 'b@example.test');
        Fixtures::login($b);
        foreach ([['PATCH', ['current_price' => '1.00']], ['DELETE', null]] as [$method, $body]) {
            $response = $this->send($method, '/api/v1/services/' . $serviceId, $body);
            self::assertSame(404, $response->status, $method);
        }
        self::assertSame([], json_decode($this->send('GET', '/api/v1/services')->body, true)['data']['services']);
        self::assertSame(2, $this->countRows('security_events', "event = 'authz.foreign_resource'"));
        self::assertSame('3200.00', $this->app->db()->fetchOne('SELECT current_price FROM services WHERE public_id = :p', ['p' => $serviceId])['current_price']);
    }

    public function testPasswordResetLinkIsRemovedFromUrl(): void
    {
        $this->app->auth()->createTestAccount('claire@example.test', self::TEST_PASSWORD, 'Agence Claire');
        $this->app->auth()->requestPasswordReset('claire@example.test', '203.0.113.60');
        $files = glob($this->app->config->storagePath('mail-capture') . '/*.json') ?: [];
        preg_match('#/reinitialisation/([0-9a-f]{64})#', (string) file_get_contents($files[0]), $m);

        $_SESSION = [];
        $exchange = $this->send('GET', '/reinitialisation/' . $m[1]);
        self::assertSame('/reinitialisation', $exchange->header('Location'));
        self::assertSame('no-referrer', $exchange->header('Referrer-Policy'));
        self::assertSame(200, $this->send('GET', '/reinitialisation')->status);

        $newPassword = bin2hex(random_bytes(10)); // généré à l'exécution : aucun mot de passe littéral dans le dépôt
        $form = ['_csrf' => (string) $_SESSION['_csrf']];
        $form['password'] = $newPassword;
        $form['password_confirmation'] = $newPassword;
        $done = $this->send('POST', '/reinitialisation', form: $form);
        self::assertSame('/connexion', $done->header('Location'));
        self::assertSame('ok', $this->app->auth()->attempt('claire@example.test', $newPassword, '203.0.113.61', null));
    }

    public function testPagesEscapeUserProvidedText(): void
    {
        $this->startDemo();
        $this->send('PATCH', '/api/v1/organization', ['name' => '<script>alert(1)</script>']);
        $this->send('POST', '/api/v1/services', ['name' => '"><img src=x onerror=alert(1)>', 'category' => 'other']);
        foreach (['/app', '/app/parametres/prestations', '/app/parametres/agence'] as $path) {
            $body = $this->send('GET', $path)->body;
            self::assertStringNotContainsString('<script>alert(1)</script>', $body, $path);
            self::assertStringNotContainsString('<img src=x', $body, $path);
        }
    }
}
