<?php

declare(strict_types=1);

namespace App\Http;

use App\App;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\CsrfProtection;
use App\Http\Middleware\Middleware;
use App\Http\Middleware\SecurityHeaders;
use App\Security\Crypto\IntegrityException;
use App\Security\CsrfGuard;
use App\Support\View;
use Throwable;

/**
 * Noyau HTTP : contrôle de taille, routage, session, CSRF, authentification, gestion d'erreurs.
 * Les erreurs ne divulguent jamais d'information technique : message générique et référence d'incident.
 */
final class Kernel
{
    public function __construct(private readonly App $app, private readonly Router $router)
    {
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function handle(Request $request): Response
    {
        $config = $this->app->config;
        try {
            $response = $this->dispatch($request);
        } catch (Throwable $e) {
            $response = $this->failure($request, $e);
        }

        return SecurityHeaders::apply($response, $config->isProduction() && str_starts_with($config->appUrl, 'https://'), !$config->isProduction());
    }

    private function dispatch(Request $request): Response
    {
        if (is_file($this->app->config->storagePath('maintenance.flag'))) {
            return $this->respondError($request, 503, 'maintenance', 'Agency OS est en maintenance. Réessayez dans quelques minutes.');
        }
        if ($request->bodyTooLarge) {
            $this->app->securityEvents()->record('request.too_large', ip: $request->clientIp, requestId: $request->requestId, context: ['route' => $request->path]);

            return $this->respondError($request, 413, 'payload_too_large', 'La requête est trop volumineuse.');
        }

        $match = $this->router->match($request->method, $request->path);
        if ($match === null) {
            return $this->router->pathExists($request->path)
                ? $this->respondError($request, 405, 'method_not_allowed', 'Méthode non autorisée.')
                : $this->respondError($request, 404, 'not_found', 'Cette page n\'existe pas ou n\'est pas accessible.');
        }
        [$route, $params] = $match;
        $request = $request->withRouteParams($params)->withAttribute('route', $route);

        // Minimisation : pas de session (ni cookie, ni fichier) pour une visite anonyme d'une page publique.
        if ($this->needsSession($request, $route)) {
            $this->app->sessions()->start();
        } else {
            $_SESSION = [];
        }

        /** @var list<Middleware> $pipeline */
        $pipeline = [
            new CsrfProtection($this->app->csrf(), $this->app->securityEvents()),
            new Authenticate($this->app->identities(), $this->app->securityEvents(), $this->app->clock()),
        ];
        $handler = static fn (Request $r): Response => $route->handle($r);
        foreach (array_reverse($pipeline) as $middleware) {
            $next = $handler;
            $handler = static fn (Request $r): Response => $middleware->process($r, $next);
        }

        return $handler($request);
    }

    private function needsSession(Request $request, Route $route): bool
    {
        $cookies = $request->header('cookie') ?? '';

        return !$route->isPublic()
            || $route->startsSession
            || !CsrfGuard::isSafeMethod($request->method)
            || str_contains($cookies, $this->app->sessions()->cookieName() . '=');
    }

    private function failure(Request $request, Throwable $e): Response
    {
        $ref = strtoupper(bin2hex(random_bytes(4)));
        $this->app->logger()->exception($e, $ref);
        if ($e instanceof IntegrityException) {
            $this->app->securityEvents()->record('crypto.integrity_failure', ip: $request->clientIp, requestId: $request->requestId, context: ['route' => $request->path]);
        }

        return $this->respondError($request, 500, 'internal_error', 'Une erreur est survenue.', $ref);
    }

    private function respondError(Request $request, int $status, string $code, string $message, ?string $ref = null): Response
    {
        if ($request->isApi()) {
            return Response::error($status, $code, $message, [], $ref);
        }

        return Response::html(View::render('errors/error', [
            'title' => $status === 404 ? 'Page introuvable' : ($status === 503 ? 'Maintenance' : 'Erreur'),
            'message' => $message,
            'status' => $status,
            'errorRef' => $ref,
        ], 'layouts/minimal'), $status);
    }
}
