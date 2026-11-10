<?php

declare(strict_types=1);

/*
 * Contrôleur frontal : seul fichier PHP exposé. Le code, la configuration et les données sont hors de public_html.
 */

use App\Boot;
use App\Http\Request;

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $app = Boot::createApp();
} catch (Throwable $e) {
    // Configuration absente ou incohérente : réponse générique, détail dans le journal PHP (hors public).
    error_log('Agency OS : échec de démarrage — ' . $e::class);
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Service momentanément indisponible.';
    exit;
}

$app->kernel()->handle(Request::fromGlobals(64 * 1024))->send();
