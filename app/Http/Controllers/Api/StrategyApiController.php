<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\NotFoundException;
use App\Application\StrategyService;
use App\Application\ValidationException;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * API du module Stratégie (docs/02 §6) : recommandations (évaluation, acceptation, report, rejet) et plan d'action.
 */
final class StrategyApiController extends Controller
{
    public function index(Request $request): Response
    {
        $tab = $request->query['status'] ?? 'new';

        return Response::json($this->app->strategy()->list($this->org($request), in_array($tab, StrategyService::STATUSES, true) ? $tab : 'new', self::module($request)));
    }

    public function evaluate(Request $request): Response
    {
        return Response::json($this->app->strategy()->evaluate($this->org($request)));
    }

    public function accept(Request $request): Response
    {
        $id = $request->param('id');

        return $this->guard($request, $id, fn (): Response => Response::json(['action' => $this->app->strategy()->accept($this->org($request), $id)]));
    }

    public function dismiss(Request $request): Response
    {
        $id = $request->param('id');

        return $this->guard($request, $id, function () use ($request, $id): Response {
            $this->app->strategy()->dismiss($this->org($request), $id);

            return Response::json(['status' => 'dismissed']);
        });
    }

    public function snooze(Request $request): Response
    {
        $id = $request->param('id');

        return $this->withJson($request, fn (array $input): Response => $this->guard($request, $id, fn (): Response => Response::json([
            'status' => 'snoozed', 'snoozed_until' => $this->app->strategy()->snooze($this->org($request), $id, $input),
        ])));
    }

    public function reopen(Request $request): Response
    {
        $id = $request->param('id');

        return $this->guard($request, $id, function () use ($request, $id): Response {
            $this->app->strategy()->reopen($this->org($request), $id);

            return Response::json(['status' => 'new']);
        });
    }

    public function plan(Request $request): Response
    {
        return Response::json($this->app->strategy()->plan($this->org($request)));
    }

    public function storeAction(Request $request): Response
    {
        return $this->withJson($request, fn (array $input): Response => Response::json(['public_id' => $this->app->strategy()->createAction($this->org($request), $input)], 201));
    }

    public function updateAction(Request $request): Response
    {
        $id = $request->param('id');

        return $this->withJson($request, fn (array $input): Response => $this->guard($request, $id, function () use ($request, $id, $input): Response {
            $this->app->strategy()->updateAction($this->org($request), $id, $input);

            return Response::json(['public_id' => $id]);
        }));
    }

    public function destroyAction(Request $request): Response
    {
        $id = $request->param('id');

        return $this->guard($request, $id, function () use ($request, $id): Response {
            $this->app->strategy()->deleteAction($this->org($request), $id);

            return new Response(204);
        });
    }

    public function storeTask(Request $request): Response
    {
        $id = $request->param('id');

        return $this->withJson($request, fn (array $input): Response => $this->guard($request, $id, fn (): Response => Response::json(
            ['public_id' => $this->app->strategy()->addTask($this->org($request), $id, $input)],
            201,
        )));
    }

    public function updateTask(Request $request): Response
    {
        $id = $request->param('id');

        return $this->withJson($request, fn (array $input): Response => $this->guard($request, $id, function () use ($request, $id, $input): Response {
            $this->app->strategy()->updateTask($this->org($request), $id, $input);

            return Response::json(['public_id' => $id]);
        }));
    }

    public function destroyTask(Request $request): Response
    {
        $id = $request->param('id');

        return $this->guard($request, $id, function () use ($request, $id): Response {
            $this->app->strategy()->deleteTask($this->org($request), $id);

            return new Response(204);
        });
    }

    private static function module(Request $request): ?string
    {
        $module = $request->query['module'] ?? null;

        return in_array($module, ['pricing', 'recurring', 'diagnostic', 'strategy'], true) ? $module : null;
    }

    /** @param callable(): Response $action */
    private function guard(Request $request, string $id, callable $action): Response
    {
        try {
            return $action();
        } catch (NotFoundException) {
            if ($this->app->strategyRepository()->existsElsewhere($id)) {
                $auth = $this->auth($request);
                $this->app->securityEvents()->record('authz.foreign_resource', $auth->userId, $auth->organizationId, $request->clientIp, null, $request->requestId, [
                    'resource' => 'strategy', 'resource_public_id' => $id,
                ]);
            }

            return Response::error(404, 'not_found', 'Ressource introuvable.');
        } catch (ValidationException $e) {
            return Response::error(422, 'validation_failed', 'Certaines valeurs sont invalides.', $e->errors);
        }
    }
}
