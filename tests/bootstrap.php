<?php

declare(strict_types=1);

/*
 * Amorçage des tests. Utilise l'autoloader Composer s'il existe ; sinon un autoloader PSR-4 minimal
 * (environnement local sans accès aux archives de dépendances) et PHPMailer depuis PHPMAILER_SRC.
 */

$base = dirname(__DIR__);
if (is_file($base . '/vendor/autoload.php')) {
    require $base . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class) use ($base): void {
        $map = ['App\\' => $base . '/app/', 'Tests\\' => $base . '/tests/'];
        $phpmailer = getenv('PHPMAILER_SRC');
        if (is_string($phpmailer) && $phpmailer !== '') {
            $map['PHPMailer\\PHPMailer\\'] = rtrim($phpmailer, '/') . '/';
        }
        foreach ($map as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }

                return;
            }
        }
    });
}

require_once $base . '/app/Support/helpers.php';
require_once $base . '/app/Support/ui.php';
date_default_timezone_set('UTC');
