<?php

declare(strict_types=1);

/*
 * Amorçage d'Agency OS : autoloader, durcissement PHP à l'exécution, configuration.
 * Ce fichier est situé HORS du répertoire public (seul public_html/ est servi).
 */

define('AGENCY_OS_BASE', dirname(__DIR__));

// Autoloader : celui de Composer s'il existe (production : PHPMailer), sinon PSR-4 minimal pour App\.
if (is_file(AGENCY_OS_BASE . '/vendor/autoload.php')) {
    require AGENCY_OS_BASE . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'App\\')) {
            $file = AGENCY_OS_BASE . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}

require_once AGENCY_OS_BASE . '/app/Support/helpers.php';

// Durcissement indépendant de la configuration partagée de l'hébergement (docs/04-securite.md §6.3).
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', AGENCY_OS_BASE . '/storage/logs/php-error.log');
ini_set('default_charset', 'UTF-8');
error_reporting(E_ALL);
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
if (PHP_SAPI !== 'cli') {
    header_remove('X-Powered-By');
    set_time_limit(30);
}
