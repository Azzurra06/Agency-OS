<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TypedArray;
use App\Http\Request;
use App\Http\Response;
use App\Http\Route;
use App\Infrastructure\Database\OrgId;
use App\Security\AuthContext;
use App\Security\IdentityRepository;
use App\Security\Permissions;
use App\Security\SecurityEventLogger;
use App\Support\Clock;

/**
 * Authentification et contexte d'organisation (isolation, niveau 1 — docs/04-securite.md §4).
 *
 * - l'utilisateur et l'organisation active viennent EXCLUSIVEMENT de la session ;
 * - l'appartenance est revérifiée en base à chaque requête ;
 * - compte actif et email vérifié exigés ; époque de session contrôlée (révocation globale) ;
 * - organisation de démo expirée : accès refusé ;
 * - permission de la route vérifiée (refus par défaut), restrictions propres à la démo.
 */
final class Authenticate implements Middleware
{
    public function __construct(
        private readonly IdentityRepository $identities,
        private readonly SecurityEventLogger $events,
        private readonly Clock $clock = new Clock(),
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $route = $request->attribute('route');
        if (!$route instanceof Route || $route->isPublic()) {
            return $next($request);
        }

        $session = $_SESSION ?? [];
        $userId = TypedArray::int($session, 'user_id', 0);
        $orgId = TypedArray::int($session, 'organization_id', 0);
        $epoch = TypedArray::int($session, 'session_epoch', 0);
        $context = ($userId > 0 && $orgId > 0) ? $this->identities->authContext($userId, $orgId) : null;

        if ($context === null || $context->sessionEpoch !== $epoch || !$context->canSignIn()) {
            return $this->unauthenticated($request);
        }
        if ($context->isDemo && $context->demoExpiresAt !== null && $context->demoExpiresAt <= $this->clock->nowUtc()) {
            $_SESSION = [];

            return $request->isApi()
                ? Response::error(401, 'demo_expired', 'Cette démonstration a expiré et ses données ont été supprimées.')
                : Response::redirect('/demo/expiree');
        }
        if (!Permissions::allows($context->role, $route->access)) {
            $this->events->record('authz.denied', $userId, $orgId, $request->clientIp, null, $request->requestId, [
                'route' => $route->pattern, 'method' => $request->method,
            ]);

            return Response::error(403, 'forbidden', 'Action non autorisée.');
        }
        if ($context->isDemo && !$route->allowDemo) {
            return Response::error(403, 'demo_restricted', 'Fonction non disponible en démonstration.');
        }

        return $next($request->withAttribute('auth', $context)->withAttribute('org', new OrgId($orgId)));
    }

    private function unauthenticated(Request $request): Response
    {
        return $request->isApi()
            ? Response::error(401, 'unauthenticated', 'Votre session a expiré. Reconnectez-vous.')
            : Response::redirect('/connexion');
    }
}
