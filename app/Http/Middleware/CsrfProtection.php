<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Security\CsrfGuard;
use App\Security\SecurityEventLogger;

/**
 * Refuse toute requête modifiant des données sans jeton CSRF valide et sans origine conforme.
 * Le jeton est lu dans l'en-tête X-CSRF-Token (API) ou le champ _csrf (formulaires).
 */
final class CsrfProtection implements Middleware
{
    public function __construct(private readonly CsrfGuard $guard, private readonly SecurityEventLogger $events)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $submitted = $request->header('x-csrf-token');
        if ($submitted === null && isset($request->form['_csrf']) && is_string($request->form['_csrf'])) {
            $submitted = $request->form['_csrf'];
        }
        /** @var array<string, mixed> $session */
        $session = $_SESSION ?? [];
        $reason = $this->guard->rejectionReason($request->method, $submitted, $request->header('origin'), $request->header('referer'), $session);
        if ($reason !== null) {
            $this->events->record('csrf.rejected', ip: $request->clientIp, requestId: $request->requestId, context: [
                'route' => $request->path, 'method' => $request->method, 'reason' => $reason,
            ]);

            return Response::error(403, 'csrf_invalid', 'La requête a expiré. Rechargez la page et réessayez.');
        }

        return $next($request);
    }
}
