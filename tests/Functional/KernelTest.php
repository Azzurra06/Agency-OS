<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Http\Response;
use App\Http\Route;
use App\Infrastructure\Database\OrgId;
use App\Security\AuthContext;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;
use Tests\Support\RequestFactory;

/**
 * Parcours de requêtes complets à travers le noyau : en-têtes, erreurs, CSRF, authentification, isolation.
 */
final class KernelTest extends DatabaseTestCase
{
    private const ORIGIN = ['Origin' => 'https://agency-os.test'];

    protected function setUp(): void
    {
        parent::setUp();
        $router = $this->app->kernel()->router();
        $router->add('GET', '/api/v1/test/whoami', static function ($request): Response {
            $auth = $request->attribute('auth');
            $org = $request->attribute('org');

            return Response::json([
                'org' => $org instanceof OrgId ? $org->value : null,
                'user' => $auth instanceof AuthContext ? $auth->userId : null,
            ]);
        }, 'app.read');
        $router->add('POST', '/api/v1/test/write', static fn (): Response => Response::json(['saved' => true]), 'pricing.write');
        $router->add('POST', '/api/v1/test/owner-only', static fn (): Response => Response::json(['ok' => true]), 'account.delete', false);
        $router->add('GET', '/api/v1/test/boom', static fn (): Response => throw new \RuntimeException('CA 180000 de claire@example.test'), Route::ACCESS_PUBLIC);
    }

    public function testSecurityHeadersOnEveryResponse(): void
    {
        foreach (['/', '/api/v1/health', '/introuvable', '/api/v1/introuvable', '/api/v1/test/whoami'] as $path) {
            $response = $this->app->kernel()->handle(RequestFactory::make('GET', $path));
            self::assertStringContainsString("frame-ancestors 'none'", (string) $response->header('Content-Security-Policy'), $path);
            self::assertStringContainsString("script-src 'self'", (string) $response->header('Content-Security-Policy'));
            self::assertStringNotContainsString('unsafe-inline', (string) $response->header('Content-Security-Policy'));
            self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
            self::assertSame('DENY', $response->header('X-Frame-Options'));
            self::assertSame('private, no-store, max-age=0', $response->header('Cache-Control'));
            self::assertNull($response->header('X-Powered-By'));
        }
    }

    public function testAnonymousPublicVisitDoesNotCreateASession(): void
    {
        $_SESSION = ['leftover' => true];
        $this->app->kernel()->handle(RequestFactory::make('GET', '/api/v1/health'));
        self::assertSame([], $_SESSION, 'Aucune session pour une visite anonyme (minimisation).');
    }

    public function testHealthEndpointRevealsNothing(): void
    {
        $response = $this->app->kernel()->handle(RequestFactory::make('GET', '/api/v1/health'));
        self::assertSame(200, $response->status);
        self::assertSame('{"data":{"status":"ok"}}', $response->body);
    }

    public function testNotFoundIsJsonForApiAndHtmlForPages(): void
    {
        $api = $this->app->kernel()->handle(RequestFactory::make('GET', '/api/v1/nope'));
        self::assertSame(404, $api->status);
        self::assertSame('not_found', json_decode($api->body, true)['error']['code']);

        $page = $this->app->kernel()->handle(RequestFactory::make('GET', '/nope'));
        self::assertSame(404, $page->status);
        self::assertStringContainsString('Page introuvable', $page->body);

        $method = $this->app->kernel()->handle(RequestFactory::make('DELETE', '/api/v1/health'));
        self::assertSame(405, $method->status);
    }

    public function testInternalErrorsAreGenericAndLoggedWithoutData(): void
    {
        $response = $this->app->kernel()->handle(RequestFactory::make('GET', '/api/v1/test/boom'));
        self::assertSame(500, $response->status);
        $error = json_decode($response->body, true)['error'];
        self::assertSame('internal_error', $error['code']);
        self::assertSame('Une erreur est survenue.', $error['message']);
        self::assertMatchesRegularExpression('/^[0-9A-F]{8}$/', $error['error_ref']);
        self::assertStringNotContainsString('180000', $response->body);

        $logs = implode('', array_map('file_get_contents', glob($this->app->config->storagePath('logs') . '/*.log') ?: []));
        self::assertStringContainsString($error['error_ref'], $logs);
        self::assertStringNotContainsString('180000', $logs);
        self::assertStringNotContainsString('claire@example.test', $logs);
    }

    public function testOversizedBodyIsRejected(): void
    {
        $response = $this->app->kernel()->handle(RequestFactory::make('POST', '/api/v1/test/write', tooLarge: true));
        self::assertSame(413, $response->status);
        self::assertSame(1, $this->countRows('security_events', "event = 'request.too_large'"));
    }

    public function testMaintenanceMode(): void
    {
        touch($this->app->config->storagePath('maintenance.flag'));
        self::assertSame(503, $this->app->kernel()->handle(RequestFactory::make('GET', '/api/v1/health'))->status);
    }

    public function testAnonymousAccessToPrivateRouteIs401(): void
    {
        $response = $this->app->kernel()->handle(RequestFactory::make('GET', '/api/v1/test/whoami'));
        self::assertSame(401, $response->status);
        self::assertSame('unauthenticated', json_decode($response->body, true)['error']['code']);
    }

    public function testOrganisationComesFromSessionOnly(): void
    {
        $a = Fixtures::member($this->app->db(), 'a@example.test');
        Fixtures::member($this->app->db(), 'b@example.test');
        Fixtures::login($a);

        $response = $this->app->kernel()->handle(RequestFactory::make('GET', '/api/v1/test/whoami'));
        self::assertSame(200, $response->status);
        self::assertSame(['org' => $a['org_id'], 'user' => $a['user_id']], json_decode($response->body, true)['data']);
    }

    public function testSessionPointingToForeignOrganisationIsRejected(): void
    {
        $a = Fixtures::member($this->app->db(), 'a@example.test');
        $b = Fixtures::member($this->app->db(), 'b@example.test');
        // Session falsifiée : utilisateur A, organisation B (aucune appartenance).
        $_SESSION = ['user_id' => $a['user_id'], 'organization_id' => $b['org_id'], 'session_epoch' => 1];
        self::assertSame(401, $this->app->kernel()->handle(RequestFactory::make('GET', '/api/v1/test/whoami'))->status);
    }

    public function testRevokedSessionEpochIsRejected(): void
    {
        $a = Fixtures::member($this->app->db(), 'a@example.test');
        Fixtures::login($a);
        $this->app->db()->run('UPDATE users SET session_epoch = 2 WHERE id = :id', ['id' => $a['user_id']]);
        self::assertSame(401, $this->app->kernel()->handle(RequestFactory::make('GET', '/api/v1/test/whoami'))->status);
    }

    public function testUnverifiedOrLockedAccountsAreRejected(): void
    {
        $unverified = Fixtures::member($this->app->db(), 'u@example.test', verified: false);
        Fixtures::login($unverified);
        self::assertSame(401, $this->app->kernel()->handle(RequestFactory::make('GET', '/api/v1/test/whoami'))->status);

        $locked = Fixtures::member($this->app->db(), 'l@example.test', status: 'locked');
        Fixtures::login($locked);
        self::assertSame(401, $this->app->kernel()->handle(RequestFactory::make('GET', '/api/v1/test/whoami'))->status);
    }

    public function testExpiredDemoIsRejectedAndSessionCleared(): void
    {
        $demo = Fixtures::member($this->app->db(), 'demo@example.test', isDemo: true, demoExpiresAt: '2026-11-12 08:59:59');
        Fixtures::login($demo);
        $response = $this->app->kernel()->handle(RequestFactory::make('GET', '/api/v1/test/whoami'));
        self::assertSame(401, $response->status);
        self::assertSame('demo_expired', json_decode($response->body, true)['error']['code']);
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testDemoRestrictionsAndPermissions(): void
    {
        $demo = Fixtures::member($this->app->db(), 'demo@example.test', isDemo: true, demoExpiresAt: '2026-11-13 09:00:00');
        Fixtures::login($demo);
        $_SESSION['_csrf'] = str_repeat('c', 64);
        $response = $this->app->kernel()->handle(RequestFactory::make('POST', '/api/v1/test/owner-only', ['X-CSRF-Token' => str_repeat('c', 64)] + self::ORIGIN));
        self::assertSame(403, $response->status);
        self::assertSame('demo_restricted', json_decode($response->body, true)['error']['code']);

        $member = Fixtures::member($this->app->db(), 'm@example.test', role: 'member');
        Fixtures::login($member);
        $_SESSION['_csrf'] = str_repeat('c', 64);
        $response = $this->app->kernel()->handle(RequestFactory::make('POST', '/api/v1/test/owner-only', ['X-CSRF-Token' => str_repeat('c', 64)] + self::ORIGIN));
        self::assertSame(403, $response->status);
        self::assertSame('forbidden', json_decode($response->body, true)['error']['code']);
        self::assertSame(1, $this->countRows('security_events', "event = 'authz.denied'"));
    }

    public function testCsrfIsRequiredForWrites(): void
    {
        $a = Fixtures::member($this->app->db(), 'a@example.test');
        Fixtures::login($a);
        $_SESSION['_csrf'] = str_repeat('d', 64);

        $missing = $this->app->kernel()->handle(RequestFactory::make('POST', '/api/v1/test/write', self::ORIGIN));
        self::assertSame(403, $missing->status);
        self::assertSame('csrf_invalid', json_decode($missing->body, true)['error']['code']);

        $foreign = $this->app->kernel()->handle(RequestFactory::make('POST', '/api/v1/test/write', ['X-CSRF-Token' => str_repeat('d', 64), 'Origin' => 'https://evil.example']));
        self::assertSame(403, $foreign->status);

        $ok = $this->app->kernel()->handle(RequestFactory::make('POST', '/api/v1/test/write', ['X-CSRF-Token' => str_repeat('d', 64)] + self::ORIGIN));
        self::assertSame(200, $ok->status);
        self::assertSame(2, $this->countRows('security_events', "event = 'csrf.rejected'"));
    }

    public function testIdleSessionExpires(): void
    {
        $a = Fixtures::member($this->app->db(), 'a@example.test');
        Fixtures::login($a);
        self::assertSame(200, $this->app->kernel()->handle(RequestFactory::make('GET', '/api/v1/test/whoami'))->status);
        $clock = $this->app->clock();
        self::assertInstanceOf(\App\Support\FrozenClock::class, $clock);
        $clock->advance('+61 minutes');
        self::assertSame(401, $this->app->kernel()->handle(RequestFactory::make('GET', '/api/v1/test/whoami'))->status);
    }

    public function testHomePageEscapesAndIsServed(): void
    {
        $response = $this->app->kernel()->handle(RequestFactory::make('GET', '/'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('données fictives', $response->body);
        self::assertStringNotContainsString('<script', $response->body);
        self::assertSame('noindex, nofollow, noarchive', $response->header('X-Robots-Tag'), 'Hors production : pas d\'indexation.');
    }
}
