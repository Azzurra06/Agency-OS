<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\App;
use App\Application\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Database\OrgId;
use App\Security\AuthContext;
use App\Support\View;
use JsonException;
use LogicException;

/**
 * Outils communs aux contrôleurs : contexte authentifié, corps JSON, erreurs de validation, rendu de pages.
 */
abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    protected function auth(Request $request): AuthContext
    {
        $auth = $request->attribute('auth');
        if (!$auth instanceof AuthContext) {
            throw new LogicException('Route privée appelée sans contexte authentifié.');
        }

        return $auth;
    }

    protected function org(Request $request): OrgId
    {
        $org = $request->attribute('org');
        if (!$org instanceof OrgId) {
            throw new LogicException('Route privée appelée sans organisation.');
        }

        return $org;
    }

    /**
     * Exécute une action API : JSON invalide → 400, ValidationException → 422.
     *
     * @param callable(array<string, mixed>): Response $action
     */
    protected function withJson(Request $request, callable $action): Response
    {
        if (!$request->isJson()) {
            return Response::error(415, 'unsupported_media_type', 'Contenu JSON attendu.');
        }
        try {
            $input = $request->json();
        } catch (JsonException) {
            return Response::error(400, 'invalid_json', 'Requête invalide.');
        }
        try {
            return $action($input);
        } catch (ValidationException $e) {
            return Response::error(422, 'validation_failed', 'Certaines valeurs sont invalides.', $e->errors);
        }
    }

    protected function csrfToken(): string
    {
        /** @var array<string, mixed> $session */
        $session = &$_SESSION;

        return $this->app->csrf()->token($session);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function page(string $template, array $data, string $layout = 'layouts/app'): Response
    {
        return Response::html(View::render($template, $data + ['csrf' => $this->csrfToken()], $layout));
    }

    /**
     * Page de l'application authentifiée (gabarit commun : organisation, bandeau démo).
     *
     * @param array<string, mixed> $data
     */
    protected function screen(Request $request, string $template, string $nav, string $title, array $data): Response
    {
        $org = $this->org($request);
        $auth = $this->auth($request);

        return $this->page($template, $data + [
            'title' => $title,
            'nav' => $nav,
            'org' => $this->app->organizations()->get($org),
            'isDemo' => $auth->isDemo,
            'demoExpiresAt' => $auth->demoExpiresAt?->format('Y-m-d\TH:i:s\Z'),
            'demoRemaining' => $auth->demoExpiresAt === null ? null : max(0, $auth->demoExpiresAt->getTimestamp() - $this->app->clock()->nowUtc()->getTimestamp()),
        ]);
    }
}
