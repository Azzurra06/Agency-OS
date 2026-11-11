<?php

declare(strict_types=1);

/*
 * Crée un compte de test (profil portfolio : seul moyen de créer un compte, aucune inscription publique).
 *   php bin/create-test-account.php email@exemple.fr "Nom de l'agence" [chemin/.env]
 * Le mot de passe est lu sur l'entrée standard, jamais en argument (il resterait dans l'historique du shell) :
 *   read -rs P && printf '%s' "$P" | php bin/create-test-account.php email@exemple.fr "Agence"
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$email = isset($argv[1]) && is_string($argv[1]) ? $argv[1] : '';
$agency = isset($argv[2]) && is_string($argv[2]) ? $argv[2] : '';
if ($email === '' || $agency === '') {
    fwrite(STDERR, "Usage : php bin/create-test-account.php email \"Nom de l'agence\" [chemin/.env]\n");
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';
$app = \App\Boot::createApp(isset($argv[3]) && is_string($argv[3]) ? $argv[3] : null);

// Saisie sans affichage recommandée :  read -rs P && printf '%s' "$P" | php bin/create-test-account.php …
$password = trim((string) fgets(STDIN));
try {
    $ids = $app->auth()->createTestAccount($email, $password, $agency);
    echo "Compte de test créé (organisation n° {$ids['organization_id']}). Données fictives uniquement.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Échec : ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
