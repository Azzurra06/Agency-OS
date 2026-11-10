<?php

declare(strict_types=1);

namespace App\Security\Crypto;

use RuntimeException;

/**
 * Trousseau de clés de données, versionnées (k1, k2…), stockées HORS du répertoire public et HORS de la base.
 *
 * Fichiers (config/keys/, droits 600, absents de Git) :
 *  - keyring.json : {"active": "k2", "accepted": ["k1", "k2"]}
 *  - data.k1, data.k2… : 32 octets aléatoires encodés en base64.
 */
final class Keyring
{
    /**
     * @param array<string, string> $keys clés brutes (32 octets) indexées par version
     */
    public function __construct(private readonly string $active, private readonly array $keys)
    {
        if (!isset($keys[$active])) {
            throw new RuntimeException('La clé active est absente du trousseau.');
        }
        foreach ($keys as $version => $key) {
            if (!preg_match('/^k\d{1,4}$/', $version) || strlen($key) !== 32) {
                throw new RuntimeException('Clé de données invalide dans le trousseau.');
            }
        }
    }

    public static function load(string $directory): self
    {
        $manifestPath = $directory . '/keyring.json';
        if (!is_file($manifestPath)) {
            throw new RuntimeException('Trousseau de clés introuvable : exécuter bin/generate-key.php.');
        }
        /** @var array{active?: string, accepted?: list<string>} $manifest */
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 4, JSON_THROW_ON_ERROR);
        $active = $manifest['active'] ?? '';
        $keys = [];
        foreach ($manifest['accepted'] ?? [] as $version) {
            if (!preg_match('/^k\d{1,4}$/', $version)) {
                throw new RuntimeException('Version de clé invalide.');
            }
            $raw = base64_decode(trim((string) @file_get_contents($directory . '/data.' . $version)), true);
            if ($raw === false || strlen($raw) !== 32) {
                throw new RuntimeException(sprintf('Clé %s illisible ou invalide.', $version));
            }
            $keys[$version] = $raw;
        }

        return new self($active, $keys);
    }

    public function activeVersion(): string
    {
        return $this->active;
    }

    public function key(string $version): string
    {
        if (!isset($this->keys[$version])) {
            throw new IntegrityException('Version de clé non acceptée.');
        }

        return $this->keys[$version];
    }
}
