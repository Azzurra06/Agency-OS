<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Response;
use App\Http\Route;
use App\Http\Router;
use App\Security\CsrfGuard;
use App\Security\Permissions;
use App\Security\SessionManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HttpUnitTest extends TestCase
{
    public function testRouteWithoutAccessDeclarationIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Route('GET', '/x', static fn () => Response::json([]), '');
    }

    public function testRouteParametersOnlyAcceptSafeIdentifiers(): void
    {
        $router = new Router();
        $router->add('GET', '/api/v1/pricing/simulations/{id}', static fn () => Response::json([]), 'pricing.write');
        self::assertNotNull($router->match('GET', '/api/v1/pricing/simulations/01JC9ABCDEFGHJKMNPQRSTVWXY'));
        self::assertNull($router->match('GET', '/api/v1/pricing/simulations/1%20OR%201=1'));
        self::assertNull($router->match('GET', '/api/v1/pricing/simulations/a/b'));
        self::assertNull($router->match('POST', '/api/v1/pricing/simulations/abc'));
        self::assertTrue($router->pathExists('/api/v1/pricing/simulations/abc'));
    }

    public function testRedirectsAreInternalOnly(): void
    {
        self::assertSame('/', Response::redirect('https://evil.example')->header('Location'));
        self::assertSame('/', Response::redirect('//evil.example')->header('Location'));
        self::assertSame('/app', Response::redirect('/app')->header('Location'));
    }

    public function testCsrfGuard(): void
    {
        $guard = new CsrfGuard('https://agency-os.azzurra-pro.com');
        $session = [];
        $token = $guard->token($session);
        self::assertSame(64, strlen($token));
        self::assertSame($token, $guard->token($session));

        self::assertNull($guard->rejectionReason('GET', null, null, null, $session));
        self::assertSame('token', $guard->rejectionReason('POST', null, 'https://agency-os.azzurra-pro.com', null, $session));
        self::assertSame('token', $guard->rejectionReason('POST', str_repeat('0', 64), 'https://agency-os.azzurra-pro.com', null, $session));
        self::assertSame('origin', $guard->rejectionReason('POST', $token, 'https://evil.example', null, $session));
        self::assertSame('origin', $guard->rejectionReason('DELETE', $token, null, null, $session));
        self::assertNull($guard->rejectionReason('PUT', $token, 'https://agency-os.azzurra-pro.com', null, $session));
        self::assertNull($guard->rejectionReason('PATCH', $token, null, 'https://agency-os.azzurra-pro.com/app/pricing', $session));
        self::assertSame([SessionManager::KEY_CSRF], array_keys($session));
    }

    public function testPermissionsDenyByDefault(): void
    {
        self::assertTrue(Permissions::allows('owner', 'pricing.write'));
        self::assertTrue(Permissions::allows('member', 'pricing.write'));
        self::assertFalse(Permissions::allows('member', 'organization.write'));
        self::assertFalse(Permissions::allows('member', 'account.delete'));
        self::assertFalse(Permissions::allows('intrus', 'app.read'));
    }

    public function testErrorEnvelope(): void
    {
        $response = Response::error(422, 'validation_failed', 'Données invalides.', ['price' => 'too_small']);
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(['error' => ['code' => 'validation_failed', 'message' => 'Données invalides.', 'fields' => ['price' => 'too_small']]], $body);
        self::assertSame('application/json; charset=utf-8', $response->header('content-type'));
    }
}
