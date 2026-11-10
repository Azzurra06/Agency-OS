<?php

declare(strict_types=1);

/*
 * Applique les migrations en attente (ligne de commande uniquement).
 * Avant toute migration en production : effectuer une sauvegarde (docs/04-securite.md §8.4).
 */

use App\Infrastructure\Database\Migrator;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';
$app = \App\Boot::createApp(isset($argv[1]) && is_string($argv[1]) ? $argv[1] : null);
$applied = (new Migrator($app->db(), dirname(__DIR__) . '/database/migrations'))->migrate();
echo $applied === [] ? "Aucune migration en attente.\n" : 'Migrations appliquées : ' . implode(', ', $applied) . "\n";
