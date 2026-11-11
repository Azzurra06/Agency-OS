<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Repositories\FinancialProfileRepository;

final class FinancialProfileApiController extends Controller
{
    public function show(Request $request): Response
    {
        $org = $this->org($request);

        return Response::json([
            'values' => $this->app->financialProfiles()->formValues($org),
            'derived' => $this->app->financialProfiles()->derived($org),
        ]);
    }

    public function update(Request $request): Response
    {
        return $this->withJson($request, function (array $input) use ($request): Response {
            $origin = ($request->query['origine'] ?? '') === 'onboarding' ? 'onboarding' : 'settings';
            $this->app->financialProfiles()->update($this->org($request), $input, $this->auth($request)->userId, $origin);

            return $this->show($request);
        });
    }

    public function derived(Request $request): Response
    {
        return Response::json($this->app->financialProfiles()->derived($this->org($request)));
    }

    public function changes(Request $request): Response
    {
        $repository = new FinancialProfileRepository($this->app->db(), $this->app->fieldCrypto(), $this->app->clock());

        return Response::json(['changes' => $repository->changes($this->org($request), 50)]);
    }
}
