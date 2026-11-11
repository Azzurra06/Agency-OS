<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\ConflictException;
use App\Application\NotFoundException;
use App\Application\PricingService;
use App\Application\ValidationException;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * API du module Pricing (docs/02 §6). Brouillons modifiables ; versions figées en lecture seule (409).
 * Un identifiant d'une autre organisation donne un 404, identique à « inexistant », et la tentative est journalisée.
 */
final class PricingApiController extends Controller
{
    /** Aperçu sans enregistrement ; l'en-tête X-Request-Seq est renvoyé pour écarter les réponses périmées. */
    public function calculate(Request $request): Response
    {
        $response = $this->withJson($request, fn (array $input): Response => $this->guard($request, is_string($input['simulation'] ?? null) ? $input['simulation'] : '', fn (): Response => Response::json(
            $this->app->pricing()->preview($this->org($request), $input, $this->app->organizations()->priceRounding($this->org($request))),
        )));
        $seq = $request->header('X-Request-Seq');
        if ($seq !== null && preg_match('/^\d{1,12}$/', $seq) === 1) {
            $response = $response->withHeader('X-Request-Seq', $seq);
        }

        return $response;
    }

    public function index(Request $request): Response
    {
        return Response::json(['simulations' => $this->app->pricing()->index($this->org($request))]);
    }

    public function store(Request $request): Response
    {
        return $this->withJson($request, fn (array $input): Response => Response::json(['public_id' => $this->app->pricing()->create($this->org($request), $input)], 201));
    }

    public function show(Request $request): Response
    {
        $id = $request->param('id');
        $view = $this->app->pricing()->view($this->org($request), $id);

        return $view === null ? $this->notFound($request, $id) : Response::json($view);
    }

    public function update(Request $request): Response
    {
        $id = $request->param('id');

        return $this->withJson($request, fn (array $input): Response => $this->guard($request, $id, function () use ($request, $id, $input): Response {
            $this->app->pricing()->update($this->org($request), $id, $input);

            return Response::json(['public_id' => $id]);
        }));
    }

    public function destroy(Request $request): Response
    {
        $id = $request->param('id');

        return $this->guard($request, $id, function () use ($request, $id): Response {
            $this->app->pricing()->delete($this->org($request), $id);

            return new Response(204);
        });
    }

    public function addScenario(Request $request): Response
    {
        $id = $request->param('id');

        return $this->withJson($request, fn (array $input): Response => $this->guard($request, $id, fn (): Response => Response::json(
            ['public_id' => $this->app->pricing()->addScenario($this->org($request), $id, $input)],
            201,
        )));
    }

    public function saveScenario(Request $request): Response
    {
        $id = $request->param('id');

        return $this->withJson($request, fn (array $input): Response => $this->guard($request, $id, function () use ($request, $id, $input): Response {
            $this->app->pricing()->saveScenario($this->org($request), $id, $input);

            return Response::json(['public_id' => $id]);
        }));
    }

    public function deleteScenario(Request $request): Response
    {
        $id = $request->param('id');

        return $this->guard($request, $id, function () use ($request, $id): Response {
            $this->app->pricing()->deleteScenario($this->org($request), $id);

            return new Response(204);
        });
    }

    public function finalize(Request $request): Response
    {
        $id = $request->param('id');

        return $this->guard($request, $id, function () use ($request, $id): Response {
            $this->app->pricing()->finalize($this->org($request), $id, $this->auth($request)->userId);

            return Response::json(['public_id' => $id, 'status' => 'finalized']);
        });
    }

    public function newVersion(Request $request): Response
    {
        $id = $request->param('id');

        return $this->guard($request, $id, fn (): Response => Response::json(['public_id' => $this->app->pricing()->newVersion($this->org($request), $id)], 201));
    }

    public function archive(Request $request): Response
    {
        $id = $request->param('id');

        return $this->guard($request, $id, function () use ($request, $id): Response {
            $this->app->pricing()->archive($this->org($request), $id);

            return Response::json(['public_id' => $id, 'status' => 'archived']);
        });
    }

    public function template(Request $request): Response
    {
        $category = $request->param('category');
        if (!in_array($category, ['showcase', 'ecommerce', 'redesign', 'seo', 'custom', 'other'], true)) {
            return Response::error(404, 'not_found', 'Modèle introuvable.');
        }

        return Response::json([
            'lines' => PricingService::template($category),
            'warning' => 'Ordre de grandeur pour démarrer, à adapter à votre méthode. Ce n\'est pas une référence de marché.',
        ]);
    }

    /** @param callable(): Response $action */
    private function guard(Request $request, string $id, callable $action): Response
    {
        try {
            return $action();
        } catch (NotFoundException) {
            return $this->notFound($request, $id);
        } catch (ConflictException $e) {
            return Response::error(409, 'read_only', $e->getMessage());
        } catch (ValidationException $e) {
            return Response::error(422, 'validation_failed', 'Certaines valeurs sont invalides.', $e->errors);
        }
    }

    private function notFound(Request $request, string $id): Response
    {
        if ($id !== '' && $this->app->pricingRepository()->existsElsewhere($id)) {
            $auth = $this->auth($request);
            $this->app->securityEvents()->record('authz.foreign_resource', $auth->userId, $auth->organizationId, $request->clientIp, null, $request->requestId, [
                'resource' => 'pricing', 'resource_public_id' => $id,
            ]);
        }

        return Response::error(404, 'not_found', 'Ressource introuvable.');
    }
}
