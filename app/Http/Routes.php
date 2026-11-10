<?php

declare(strict_types=1);

namespace App\Http;

use App\App;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Pages\PublicPagesController;

/**
 * Table des routes. Chaque route déclare explicitement son accès (« public » ou une permission).
 * Le lot 0 ne contient que le socle ; les modules métier ajoutent leurs routes dans les lots suivants.
 */
final class Routes
{
    public static function build(App $app): Router
    {
        $router = new Router();
        $pages = new PublicPagesController($app);
        $health = new HealthController();

        $router->add('GET', '/', $pages->home(...), Route::ACCESS_PUBLIC);
        $router->add('GET', '/connexion', $pages->comingSoon(...), Route::ACCESS_PUBLIC);
        $router->add('GET', '/demo/expiree', $pages->demoExpired(...), Route::ACCESS_PUBLIC);
        $router->add('GET', '/api/v1/health', $health->show(...), Route::ACCESS_PUBLIC);

        return $router;
    }
}
