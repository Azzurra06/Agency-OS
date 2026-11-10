<?php

declare(strict_types=1);

namespace App\Backup;

/**
 * Stockage des sauvegardes (A7 : architecture préparée, aucun stockage externe souscrit).
 *
 * Implémentations :
 *  - LocalBackupStorage : répertoire local protégé (tests, portfolio) ;
 *  - (SaaS commercial) stockage objet européen compatible S3, verrouillage d'objets, identifiants en écriture seule.
 *
 * Les fichiers transmis sont TOUJOURS déjà chiffrés (scellés par clé publique) avant l'appel.
 */
interface BackupStorage
{
    /** Envoie un fichier chiffré ; renvoie son empreinte SHA-256 telle que stockée. */
    public function put(string $name, string $localPath): string;

    public function exists(string $name): bool;

    /** Empreinte SHA-256 de l'objet stocké, pour la vérification quotidienne. */
    public function checksum(string $name): ?string;
}
