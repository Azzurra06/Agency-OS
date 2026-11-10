<?php

declare(strict_types=1);

namespace App;

use App\Support\Config;
use App\Support\Env;

/**
 * Création de l'application à partir de config/.env (après chargement de app/bootstrap.php).
 */
final class Boot
{
    public static function createApp(?string $envFile = null): App
    {
        $base = dirname(__DIR__);
        $env = Env::load($envFile ?? $base . '/config/.env');

        return new App(Config::fromEnv($env, $base));
    }
}
