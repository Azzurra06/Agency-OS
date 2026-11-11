<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\NotFoundException;
use App\Domain\Diagnostic\QuestionnaireRegistry;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use InvalidArgumentException;

/**
 * API du diagnostic. Un identifiant d'une autre organisation donne un 404, identique à « inexistant ».
 */
final class DiagnosticApiController extends Controller
{
    /** Questionnaire publié (version courante, ou ?version=v1 pour relire un ancien diagnostic). */
    public function questionnaire(Request $request): Response
    {
        $version = $request->query['version'] ?? QuestionnaireRegistry::CURRENT;
        try {
            $questionnaire = QuestionnaireRegistry::get($version);
        } catch (InvalidArgumentException) {
            return Response::error(404, 'not_found', 'Version de questionnaire inconnue.');
        }

        return Response::json(['version' => $questionnaire->version, 'dimensions' => $questionnaire->dimensions]);
    }

    public function index(Request $request): Response
    {
        return Response::json($this->app->diagnostics()->overview($this->org($request)));
    }

    /** Démarre (ou reprend) le brouillon unique ; « from » recopie les réponses d'un diagnostic terminé. */
    public function store(Request $request): Response
    {
        return $this->withJson($request, function (array $input) use ($request): Response {
            $from = $input['from'] ?? null;
            $draft = $this->app->diagnostics()->startOrResume($this->org($request), $this->auth($request)->userId, is_string($from) ? $from : null);

            return Response::json($draft, $draft['resumed'] ? 200 : 201);
        });
    }

    public function answer(Request $request): Response
    {
        $id = $request->param('id');

        return $this->withJson($request, function (array $input) use ($request, $id): Response {
            try {
                return Response::json(['progress' => $this->app->diagnostics()->answer($this->org($request), $id, $input)]);
            } catch (NotFoundException) {
                return $this->notFound($request, $id);
            }
        });
    }

    public function complete(Request $request): Response
    {
        $id = $request->param('id');

        return $this->withJson($request, function () use ($request, $id): Response {
            try {
                $done = $this->app->diagnostics()->complete($this->org($request), $id);
            } catch (NotFoundException) {
                return $this->notFound($request, $id);
            }

            return Response::json($done + ['results_url' => '/app/diagnostic/resultats/' . $id]);
        });
    }

    public function results(Request $request): Response
    {
        $id = $request->param('id');
        $results = $this->app->diagnostics()->results($this->org($request), $id);

        return $results === null ? $this->notFound($request, $id) : Response::json($results);
    }

    public function destroy(Request $request): Response
    {
        $id = $request->param('id');

        return $this->app->diagnostics()->discardDraft($this->org($request), $id) ? new Response(204) : $this->notFound($request, $id);
    }

    private function notFound(Request $request, string $id): Response
    {
        if ($this->app->diagnosticRepository()->find($this->org($request), $id) === null && $this->app->diagnosticRepository()->existsElsewhere($id)) {
            $auth = $this->auth($request);
            $this->app->securityEvents()->record('authz.foreign_resource', $auth->userId, $auth->organizationId, $request->clientIp, null, $request->requestId, [
                'resource' => 'diagnostic', 'resource_public_id' => $id,
            ]);
        }

        return Response::error(404, 'not_found', 'Ressource introuvable.');
    }
}
