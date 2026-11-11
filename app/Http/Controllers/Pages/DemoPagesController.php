<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Démonstration : présentation, création d'un espace fictif, réinitialisation, sortie.
 */
final class DemoPagesController extends Controller
{
    public function show(Request $request): Response
    {
        $error = $_SESSION['_demo_error'] ?? null;
        unset($_SESSION['_demo_error']);

        return $this->page('demo/start', ['title' => 'Démonstration', 'error' => is_string($error) ? $error : null], 'layouts/minimal');
    }

    public function start(Request $request): Response
    {
        $result = $this->app->demos()->start($request->clientIp, $request->header('user-agent'));
        if ($result === 'ok') {
            return Response::redirect('/app');
        }
        $_SESSION['_demo_error'] = $result === 'rate_limited'
            ? 'Vous avez lancé plusieurs démonstrations récemment. Réessayez dans une heure.'
            : 'La démonstration est momentanément indisponible. Réessayez plus tard.';

        return Response::redirect('/demo');
    }

    public function reset(Request $request): Response
    {
        $auth = $this->auth($request);
        if (!$auth->isDemo) {
            return Response::error(403, 'forbidden', 'Action réservée à la démonstration.');
        }
        $this->app->demos()->reset($this->org($request), $auth->userId);

        return Response::redirect('/app');
    }

    public function quit(Request $request): Response
    {
        $this->app->sessions()->destroy();

        return Response::redirect('/');
    }
}
