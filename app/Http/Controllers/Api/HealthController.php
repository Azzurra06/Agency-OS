<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Request;
use App\Http\Response;

/**
 * Point de contrôle de disponibilité. Ne révèle ni version, ni configuration, ni état interne.
 */
final class HealthController
{
    public function show(Request $request): Response
    {
        return Response::json(['status' => 'ok']);
    }
}
