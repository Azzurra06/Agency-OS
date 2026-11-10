<?php

declare(strict_types=1);

/*
 * Contrôle statique des motifs interdits (exécuté en CI et par la suite de tests de sécurité).
 * docs/04-securite.md §5 et §12.1 : SQL concaténé, sinks XSS, eval, superglobales, URL dans fopen, sorties non échappées.
 *
 *   php tools/check-forbidden.php    → code de sortie 1 si un motif interdit est trouvé
 */

$base = dirname(__DIR__);

/** @var list<array{string, string, string, list<string>}> $rules [glob, motif, explication, fichiers autorisés] */
$rules = [
    // PHP
    ['app,bin,public_html', '/->(query|exec|prepare)\(\s*["\'][^"\']*["\']\s*\.\s*\$/', 'SQL concaténé avec une variable : utiliser une requête préparée', []],
    ['app,bin,public_html', '/->(query|exec|prepare)\(\s*"[^"]*\{?\$[a-zA-Z_]/', 'Variable interpolée dans du SQL : utiliser une requête préparée', []],
    ['app,bin,public_html', '/\b(eval|assert|create_function|shell_exec|passthru|system|proc_open|popen)\s*\(/', 'Fonction dangereuse interdite', []],
    ['app,bin,public_html', '/\b(var_dump|print_r|var_export|debug_zval_dump)\s*\(/', 'Sortie de débogage interdite', []],
    ['app,bin,public_html', '/\bunserialize\s*\(/', 'unserialize interdit (utiliser JSON)', []],
    ['app,bin,public_html', '/\$_(GET|POST|REQUEST|COOKIE|FILES)\b/', 'Superglobale lue hors de Request', ['app/Http/Request.php']],
    ['app,bin,public_html', '/\b(file_get_contents|fopen|file|readfile)\s*\(\s*["\']https?:/', 'Accès réseau par fopen/file_get_contents interdit (allow_url_fopen)', []],
    ['app,bin,public_html', '/\bextract\s*\(/', 'extract() interdit hors du moteur de vues', ['app/Support/View.php']],
    ['app,bin,public_html', '/\b(md5|sha1)\s*\(/', 'Hachage faible interdit pour la sécurité (utiliser hash(\'sha256\') ou password_hash)', []],
    ['app,bin,public_html', '/\brand\s*\(|\bmt_rand\s*\(|\buniqid\s*\(/', 'Aléa non cryptographique : utiliser random_bytes/random_int', []],
    // Vues : toute sortie passe par e(), attr() ou json_script()
    ['app/Views', '/<\?=\s*+(?!e\(|attr\(|json_script\()(?!\$content \/\* contenu déjà rendu)/', 'Sortie non échappée dans une vue', []],
    // JavaScript : aucun sink XSS
    ['public_html/assets/js,prototype/assets/js', '/\.(innerHTML|outerHTML)\s*=|insertAdjacentHTML\s*\(|document\.write\s*\(/', 'Sink XSS interdit : utiliser textContent et le DOM', []],
    ['public_html/assets/js,prototype/assets/js', '/\beval\s*\(|new\s+Function\s*\(|setTimeout\s*\(\s*["\']/', 'Exécution de code dynamique interdite', []],
    ['public_html/assets/js,prototype/assets/js', '/\b(localStorage|sessionStorage|indexedDB)\b/', 'Stockage navigateur : jamais de données financières (liste blanche à justifier)', []],
];

$violations = [];
foreach ($rules as [$roots, $pattern, $message, $allowed]) {
    foreach (explode(',', $roots) as $root) {
        $dir = $base . '/' . $root;
        if (!is_dir($dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (!preg_match('/\.(php|js)$/', $path) || str_contains($path, '/vendor/') || str_ends_with($path, '.min.js')) {
                continue;
            }
            $relative = substr($path, strlen($base) + 1);
            if (in_array($relative, $allowed, true)) {
                continue;
            }
            foreach (file($path) ?: [] as $number => $line) {
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '#')) {
                    continue;
                }
                if (preg_match($pattern, $line)) {
                    $violations[] = sprintf('%s:%d — %s', $relative, $number + 1, $message);
                }
            }
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "Motifs interdits détectés :\n  " . implode("\n  ", $violations) . "\n");
    exit(1);
}
echo "Aucun motif interdit.\n";
