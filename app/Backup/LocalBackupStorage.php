<?php

declare(strict_types=1);

namespace App\Backup;

use InvalidArgumentException;
use RuntimeException;

/**
 * Stockage local des sauvegardes chiffrées (storage/backups, droits 700, hors du répertoire public).
 */
final class LocalBackupStorage implements BackupStorage
{
    public function __construct(private readonly string $directory)
    {
    }

    public function put(string $name, string $localPath): string
    {
        $target = $this->path($name);
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Répertoire de sauvegarde indisponible.');
        }
        if (is_file($target)) {
            throw new RuntimeException('Une sauvegarde de ce nom existe déjà (immutabilité).');
        }
        if (!copy($localPath, $target)) {
            throw new RuntimeException('Copie de la sauvegarde impossible.');
        }
        chmod($target, 0600);

        return (string) hash_file('sha256', $target);
    }

    public function exists(string $name): bool
    {
        return is_file($this->path($name));
    }

    public function checksum(string $name): ?string
    {
        $file = $this->path($name);

        return is_file($file) ? (string) hash_file('sha256', $file) : null;
    }

    private function path(string $name): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,120}$/', $name)) {
            throw new InvalidArgumentException('Nom de sauvegarde invalide.');
        }

        return $this->directory . '/' . $name;
    }
}
