<?php

declare(strict_types=1);

/*
 * Exécuteur des tâches planifiées. Une seule ligne cron, toutes les 15 minutes :
 *   *\/15 * * * * php /home/<compte>/domains/<domaine>/bin/cron.php
 * Les échéances sont évaluées en heure de Paris par l'application (docs/05-architecture-detaillee.md §4).
 * MVP portfolio : script développé et testé, NON programmé sur l'hébergement (décision A3).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
set_time_limit(300);
require dirname(__DIR__) . '/app/bootstrap.php';
$app = \App\Boot::createApp(isset($argv[1]) && is_string($argv[1]) ? $argv[1] : null);
foreach ($app->cronRunner()->runDue() as $job => $status) {
    echo str_pad($job, 28), $status, PHP_EOL;
}
