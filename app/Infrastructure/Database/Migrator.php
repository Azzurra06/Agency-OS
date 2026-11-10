<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Support\TypedArray;
use RuntimeException;

/**
 * Applique les migrations SQL numérotées (database/migrations/NNNN_nom.sql), une seule fois chacune.
 *
 * - Chaque migration appliquée est enregistrée avec son empreinte SHA-256 ;
 *   une migration déjà appliquée puis modifiée est détectée et bloque l'exécution.
 * - Utilisé UNIQUEMENT en ligne de commande (bin/migrate.php) : le code web ne charge jamais
 *   cette classe (aucune requête DDL depuis le web, docs/04-securite.md §6.4).
 */
final class Migrator
{
    public function __construct(private readonly Connection $db, private readonly string $directory)
    {
    }

    /**
     * @return list<string> migrations appliquées lors de cet appel
     */
    public function migrate(): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Les migrations ne s\'exécutent qu\'en ligne de commande.');
        }
        $this->ensureTable();
        $applied = $this->appliedChecksums();
        $done = [];

        foreach ($this->files() as $version => $path) {
            $sql = (string) file_get_contents($path);
            $checksum = hash('sha256', $sql);
            if (isset($applied[$version])) {
                if (!hash_equals($applied[$version], $checksum)) {
                    throw new RuntimeException(sprintf('La migration %s a été modifiée après application.', $version));
                }
                continue;
            }
            foreach (self::splitStatements($sql) as $statement) {
                $this->db->pdo()->exec($statement);
            }
            $this->db->run(
                'INSERT INTO schema_migrations (version, checksum, applied_at) VALUES (:version, :checksum, UTC_TIMESTAMP())',
                ['version' => $version, 'checksum' => $checksum],
            );
            $done[] = $version;
        }

        return $done;
    }

    /**
     * @return array<string, string>
     */
    public function files(): array
    {
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files);
        $result = [];
        foreach ($files as $file) {
            $name = basename($file, '.sql');
            if (!preg_match('/^\d{4}_[a-z0-9_]+$/', $name)) {
                throw new RuntimeException(sprintf('Nom de migration invalide : %s', $name));
            }
            $result[$name] = $file;
        }

        return $result;
    }

    /**
     * Découpe un fichier SQL en instructions (séparateur « ; » en fin de ligne, commentaires « -- » ignorés).
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '--')) {
                continue;
            }
            $lines[] = $line;
        }
        $statements = [];
        foreach (preg_split('/;\s*(\R|$)/', implode("\n", $lines)) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    private function ensureTable(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(100) NOT NULL PRIMARY KEY,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    /**
     * @return array<string, string>
     */
    private function appliedChecksums(): array
    {
        $result = [];
        foreach ($this->db->fetchAll('SELECT version, checksum FROM schema_migrations') as $row) {
            $result[TypedArray::string($row, 'version')] = TypedArray::string($row, 'checksum');
        }

        return $result;
    }
}
