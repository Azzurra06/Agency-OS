<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Historique des indicateurs (docs/05 §4.4) : chaque date de la période, les jours sans instantané valent {date, missing: true}.
 */
final class DashboardApiController extends Controller
{
    public function history(Request $request): Response
    {
        $org = $this->org($request);
        $days = (int) ($request->query['days'] ?? '30');

        return Response::json($this->app->history()->series($org, $this->app->organizations()->get($org)['public_id'], $days));
    }
}
