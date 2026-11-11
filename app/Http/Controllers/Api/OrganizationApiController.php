<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\ValidationException;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Support\Validator;

final class OrganizationApiController extends Controller
{
    public function show(Request $request): Response
    {
        $identity = $this->app->organizations()->get($this->org($request));
        unset($identity['demo_expires_at']);

        return Response::json($identity);
    }

    public function update(Request $request): Response
    {
        return $this->withJson($request, function (array $input) use ($request): Response {
            $validator = Validator::make($input, [
                'name' => ['string', 'min:2', 'max:150'],
                'activity_type' => ['nullable', 'enum:wordpress,web,digital,marketing,other'],
                'team_size' => ['nullable', 'int', 'min:1', 'max:500'],
                'legal_status' => ['nullable', 'enum:micro,ei,eurl,sarl,sasu,sas,other'],
            ]);
            if ($validator->fails()) {
                throw new ValidationException($validator->errors());
            }
            /** @var array{name?: string, activity_type?: string|null, team_size?: int|null, legal_status?: string|null} $fields */
            $fields = $validator->validated();
            $this->app->organizations()->updateIdentity($this->org($request), $fields);

            return $this->show($request);
        });
    }

    public function onboarding(Request $request): Response
    {
        return $this->withJson($request, function (array $input) use ($request): Response {
            $validator = Validator::make($input, ['step' => ['required', 'int', 'min:1', 'max:3'], 'completed' => ['bool']]);
            if ($validator->fails()) {
                throw new ValidationException($validator->errors());
            }
            $v = $validator->validated();
            $this->app->organizations()->setOnboardingStep($this->org($request), is_int($v['step']) ? $v['step'] : 1, ($v['completed'] ?? false) === true);

            return Response::json(['saved' => true]);
        });
    }
}
