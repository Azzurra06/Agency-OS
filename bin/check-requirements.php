<?php

declare(strict_types=1);

/*
 * Vérifie l'environnement RÉEL d'exécution (docs/04-securite.md §6.3 et §14.1) :
 * version de PHP, extensions, primitives cryptographiques, réglages effectifs, droits des fichiers,
 * secrets, base de données et migrations. Code de sortie 1 si un contrôle bloquant échoue.
 *
 *   php bin/check-requirements.php [chemin/.env]
 */

use App\Infrastructure\Database\Migrator;
use App\Security\Crypto\FieldContext;
use App\Security\PasswordHasher;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '', bool $blocking = true) use (&$failures): void {
    if (!$ok && $blocking) {
        $failures++;
    }
    printf("[%s] %s%s\n", $ok ? ' OK ' : ($blocking ? 'ÉCHEC' : 'AVERT'), $label, $detail === '' ? '' : ' — ' . $detail);
};

$check('PHP ≥ 8.2', PHP_VERSION_ID >= 80200, PHP_VERSION);
foreach (['bcmath', 'pdo_mysql', 'openssl', 'mbstring', 'json'] as $ext) {
    $check("Extension {$ext}", extension_loaded($ext));
}
$check('OpenSSL : AES-256-GCM', in_array('aes-256-gcm', openssl_get_cipher_methods(), true));

$base = dirname(__DIR__);
require $base . '/app/bootstrap.php';
try {
    $app = \App\Boot::createApp(isset($argv[1]) && is_string($argv[1]) ? $argv[1] : null);
} catch (Throwable $e) {
    $check('Configuration (config/.env)', false, $e->getMessage());
    exit(1);
}
$config = $app->config;
$check('Configuration cohérente', true, "profil {$config->profile}, environnement {$config->env}");
if ($config->cryptoProvider === 'sodium') {
    $check('Extension sodium (CRYPTO_PROVIDER=sodium)', extension_loaded('sodium'));
}

// Réglages effectifs appliqués par le code (indépendants de l'hébergement partagé).
$check('display_errors désactivé', in_array(strtolower((string) ini_get('display_errors')), ['0', '', 'off'], true));
$check('log_errors activé', in_array(strtolower((string) ini_get('log_errors')), ['1', 'on'], true));
$check('allow_url_fopen', !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN), 'activé sur le serveur : compensé par une règle de code (aucune URL dans fopen/file_get_contents)', false);

// Fichiers et droits.
$envFile = $argv[1] ?? $base . '/config/.env';
$perm = static fn (string $p): int => is_file($p) || is_dir($p) ? (fileperms($p) & 0777) : -1;
$check('config/.env présent', is_file($envFile));
if (is_file($envFile)) {
    $check('config/.env non lisible par les autres', ($perm($envFile) & 0077) === 0, sprintf('droits %o', $perm($envFile)), $config->isProduction());
}
foreach (['logs', 'sessions', 'mail-capture', 'backups'] as $dir) {
    $path = $config->storagePath($dir);
    $check("storage/{$dir} accessible en écriture", is_dir($path) && is_writable($path));
}
$check('Code hors du répertoire public', !is_file($base . '/public_html/bootstrap.php') && is_file($base . '/public_html/index.php'));

// Cryptographie : aller-retour réel et hachage de mot de passe.
try {
    $crypto = $app->fieldCrypto();
    $context = new FieldContext('check', 'value', 1, 'CHECK');
    $check('Chiffrement des champs (aller-retour)', $crypto->decrypt($crypto->encrypt('12345.67', $context), $context) === '12345.67', $config->cryptoProvider);
} catch (Throwable $e) {
    $check('Chiffrement des champs (aller-retour)', false, $e->getMessage());
}
$hasher = new PasswordHasher($config->cryptoProvider === 'sodium');
$hash = $hasher->hash('controle-mot-de-passe-123');
$check('Hachage des mots de passe', $hasher->verify('controle-mot-de-passe-123', $hash), $hasher->algorithmName());

// Base de données et migrations.
try {
    $version = \App\Support\TypedArray::string($app->db()->fetchOne('SELECT VERSION() AS v') ?? [], 'v', '?');
    $check('Connexion à la base', true, $version);
    $migrator = new Migrator($app->db(), $base . '/database/migrations');
    $applied = array_map(static fn (array $row): string => \App\Support\TypedArray::string($row, 'version'), $app->db()->fetchAll('SELECT version FROM schema_migrations'));
    $pending = array_diff(array_keys($migrator->files()), $applied);
    $check('Migrations à jour', $pending === [], $pending === [] ? '' : 'en attente : ' . implode(', ', $pending));
} catch (Throwable $e) {
    $check('Connexion à la base', false, 'vérifier DB_* dans config/.env');
}

echo $failures === 0 ? "\nEnvironnement conforme.\n" : "\n{$failures} contrôle(s) bloquant(s) en échec.\n";
exit($failures === 0 ? 0 : 1);
