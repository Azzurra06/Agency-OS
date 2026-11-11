<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\ConflictException;
use App\Application\NotFoundException;
use App\Application\ValidationException;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * API du module Revenus récurrents (docs/02 §6). Formules et contrats = données réelles modifiables ;
 * scénarios = simulations (brouillon modifiable, version figée en lecture seule : 409).
 */
final class RecurringApiController extends Controller
{
    /** Aperçu sans enregistrement : « type » = offer (économie d'une formule) ou projection (scénario). */
    public function calculate(Request $request): Response
    {
        return $this->withJson($request, function (array $input) use ($request): Response {
            $type = $input['type'] ?? null;
            unset($input['type']);
            $service = $this->app->recurring();
            $response = match ($type) {
                'offer' => Response::json($service->previewOffer($this->org($request), $input)),
                'projection' => Response::json($service->previewProjection($this->org($request), $input)),
                default => Response::error(422, 'validation_failed', 'Certaines valeurs sont invalides.', ['type' => 'invalid_choice']),
            };
            $seq = $request->header('X-Request-Seq');

            return $seq !== null && preg_match('/^\d{1,12}$/', $seq) === 1 ? $response->withHeader('X-Request-Seq', $seq) : $response;
        });
    }

    public function summary(Request $request): Response
    {
        return Response::json($this->app->recurring()->summary($this->org($request)));
    }

    public function offers(Request $request): Response
    {
        return Response::json(['offers' => $this->app->recurring()->offers($this->org($request))]);
    }

    public function showOffer(Request $request): Response
    {
        $id = $request->param('id');
        $offer = $this->app->recurring()->offer($this->org($request), $id);

        return $offer === null ? $this->notFound($request, $id) : Response::json($offer);
    }

    public function storeOffer(Request $request): Response
    {
        return $this->withJson($request, fn (array $input): Response => Response::json(['public_id' => $this->app->recurring()->createOffer($this->org($request), $input)], 201));
    }

    public function updateOffer(Request $request): Response
    {
        $id = $request->param('id');

        return $this->withJson($request, fn (array $input): Response => $this->guard($request, $id, function () use ($request, $id, $input): Response {
            $this->app->recurring()->updateOffer($this->org($request), $id, $input);

            return Response::json(['public_id' => $id]);
        }));
    }

    public function contracts(Request $request): Response
    {
        $id = $request->param('id');

        return $this->withJson($request, fn (array $input): Response => $this->guard($request, $id, fn (): Response => Response::json([
            'public_id' => $id, 'active_contracts' => $this->app->recurring()->setContracts($this->org($request), $id, $input),
        ])));
    }

    public function destroyOffer(Request $request): Response
    {
        $id = $request->param('id');

        return $this->guard($request, $id, function () use ($request, $id): Response {
            $this->app->recurring()->deleteOffer($this->org($request), $id);

            return new Response(204);
        });
    }

    public function scenarios(Request $request): Response
    {
        return Response::json(['scenarios' => $this->app->recurring()->scenarios($this->org($request))]);
    }

    public function storeScenario(Request $request): Response
    {
        return $this->withJson($request, fn (array $input): Response => Response::json(['public_id' => $this->app->recurring()->createScenario($this->org($request), $input)], 201));
    }

    public function showScenario(Request $request): Response
    {
        $id = $request->param('id');
        $view = $this->app->recurring()->scenarioView($this->org($request), $id);

        return $view === null ? $this->notFound($request, $id) : Response::json($view);
    }

    public function projection(Request $request): Response
    {
        $id = $request->param('id');
        $view = $this->app->recurring()->scenarioView($this->org($request), $id);

        if ($view === null) {
            return $this->notFound($request, $id);
        }
        $projection = $view['projection'];

        return Response::json(is_array($projection) ? $projection : []);
    }

    public function updateScenario(Request $request): Response
    {
        $id = $request->param('id');

        return $this->withJson($request, fn (array $input): Response => $this->guard($request, $id, function () use ($request, $id, $input): Response {
            $this->app->recurring()->updateScenario($this->org($request), $id, $input);

            return Response::json(['public_id' => $id]);
        }));
    }

    public function destroyScenario(Request $request): Response
    {
        $id = $request->param('id');

        return $this->guard($request, $id, function () use ($request, $id): Response {
            $this->app->recurring()->deleteScenario($this->org($request), $id);

            return new Response(204);
        });
    }

    public function finalize(Request $request): Response
    {
        $id = $request->param('id');

        return $this->guard($request, $id, function () use ($request, $id): Response {
            $this->app->recurring()->finalizeScenario($this->org($request), $id, $this->auth($request)->userId);

            return Response::json(['public_id' => $id, 'status' => 'finalized']);
        });
    }

    public function newVersion(Request $request): Response
    {
        $id = $request->param('id');

        return $this->guard($request, $id, fn (): Response => Response::json(['public_id' => $this->app->recurring()->newVersion($this->org($request), $id)], 201));
    }

    public function archive(Request $request): Response
    {
        $id = $request->param('id');

        return $this->guard($request, $id, function () use ($request, $id): Response {
            $this->app->recurring()->archiveScenario($this->org($request), $id);

            return Response::json(['public_id' => $id, 'status' => 'archived']);
        });
    }

    /** Mise à jour EXPLICITE de la part récurrente déclarée avec la couverture calculée. */
    public function acceptComputedShare(Request $request): Response
    {
        return $this->guard($request, '', fn (): Response => Response::json([
            'recurring_share_declared' => $this->app->recurring()->acceptComputedShare($this->org($request), $this->auth($request)->userId),
        ]));
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
        if ($id !== '' && $this->app->recurringRepository()->existsElsewhere($id)) {
            $auth = $this->auth($request);
            $this->app->securityEvents()->record('authz.foreign_resource', $auth->userId, $auth->organizationId, $request->clientIp, null, $request->requestId, [
                'resource' => 'recurring', 'resource_public_id' => $id,
            ]);
        }

        return Response::error(404, 'not_found', 'Ressource introuvable.');
    }
}
