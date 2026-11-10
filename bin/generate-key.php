<?php

declare(strict_types=1);

/*
 * Génère les secrets locaux (à exécuter sur le serveur, jamais dans Git) :
 *   php bin/generate-key.php --app-key      affiche une valeur APP_KEY à recopier dans config/.env
 *   php bin/generate-key.php --data-key     crée la clé de données suivante (k1, k2…) et l'active
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$base = dirname(__DIR__);
$option = $argv[1] ?? '';

if ($option === '--app-key') {
    echo bin2hex(random_bytes(32)), PHP_EOL;
    exit(0);
}

if ($option === '--data-key') {
    $dir = $argv[2] ?? $base . '/config/keys';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        fwrite(STDERR, "Répertoire des clés indisponible.\n");
        exit(1);
    }
    @chmod($dir, 0700);
    $manifestPath = $dir . '/keyring.json';
    $decoded = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true, 4, JSON_THROW_ON_ERROR) : [];
    $accepted = is_array($decoded) && isset($decoded['accepted']) && is_array($decoded['accepted'])
        ? array_values(array_filter($decoded['accepted'], static fn ($v): bool => is_string($v) && preg_match('/^k\d{1,4}$/', $v) === 1))
        : [];
    $next = 1;
    foreach ($accepted as $version) {
        $next = max($next, (int) substr($version, 1) + 1);
    }
    $manifest = ['active' => null, 'accepted' => $accepted];
    $version = 'k' . $next;
    $keyFile = $dir . '/data.' . $version;
    file_put_contents($keyFile, base64_encode(random_bytes(32)) . "\n", LOCK_EX);
    chmod($keyFile, 0600);
    $manifest['accepted'][] = $version;
    $manifest['active'] = $version;
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT) . "\n", LOCK_EX);
    chmod($manifestPath, 0600);
    echo "Clé {$version} créée et activée. Sauvegardez-la HORS LIGNE (sans elle, les données chiffrées sont irrécupérables).", PHP_EOL;
    exit(0);
}

fwrite(STDERR, "Usage : php bin/generate-key.php --app-key | --data-key [répertoire]\n");
exit(1);
