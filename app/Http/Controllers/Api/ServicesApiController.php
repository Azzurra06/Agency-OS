<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Support\PublicId;

/**
 * Catalogue des prestations. Un identifiant d'une autre organisation donne un 404, identique à « inexistant ».
 */
final class ServicesApiController extends Controller
{
    public function index(Request $request): Response
    {
        return Response::json(['services' => $this->app->serviceRepository()->all($this->org($request))]);
    }

    public function store(Request $request): Response
    {
        return $this->withJson($request, function (array $input) use ($request): Response {
            $id = $this->app->catalog()->create($this->org($request), $input, $this->auth($request)->isDemo);

            return Response::json(['public_id' => $id], 201);
        });
    }

    public function update(Request $request): Response
    {
        $id = $request->param('id');
        if (!PublicId::isValid($id) || !$this->app->serviceRepository()->exists($this->org($request), $id)) {
            return $this->notFound($request, $id);
        }

        return $this->withJson($request, function (array $input) use ($request, $id): Response {
            $this->app->catalog()->update($this->org($request), $id, $input);

            return Response::json(['public_id' => $id]);
        });
    }

    public function destroy(Request $request): Response
    {
        $id = $request->param('id');
        if (!PublicId::isValid($id) || !$this->app->serviceRepository()->delete($this->org($request), $id)) {
            return $this->notFound($request, $id);
        }

        return new Response(204);
    }

    private function notFound(Request $request, string $id): Response
    {
        if (PublicId::isValid($id)
            && $this->app->db()->fetchOne('SELECT id FROM services WHERE public_id = :pid', ['pid' => $id]) !== null) {
            // Ressource existante d'une autre organisation : tentative de sondage (IDOR) journalisée.
            $auth = $this->auth($request);
            $this->app->securityEvents()->record('authz.foreign_resource', $auth->userId, $auth->organizationId, $request->clientIp, null, $request->requestId, [
                'resource' => 'service', 'resource_public_id' => $id,
            ]);
        }

        return Response::error(404, 'not_found', 'Ressource introuvable.');
    }
}
