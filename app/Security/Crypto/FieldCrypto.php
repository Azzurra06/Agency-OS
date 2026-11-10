<?php

declare(strict_types=1);

namespace App\Security\Crypto;

use App\Support\Decimal;

/**
 * Point UNIQUE de chiffrement des champs sensibles (décision D4, docs/04-securite.md §7.5).
 *
 * Format stocké : « <version de clé>.<algorithme>:<base64(chiffré brut)> », ex. « k1.gcm:… ».
 * Données associées (AAD) : table | colonne | organization_id | public_id de la ligne.
 * Un chiffré recopié vers une autre colonne, une autre ligne ou une autre organisation ne se déchiffre pas.
 *
 * Limite assumée : ne protège pas contre une compromission complète du serveur (la clé y est lisible).
 */
final class FieldCrypto
{
    /** @var array<string, CryptoProvider> */
    private array $providers = [];

    public function __construct(private readonly Keyring $keyring, private readonly CryptoProvider $active, CryptoProvider ...$readers)
    {
        foreach ([$active, ...$readers] as $provider) {
            $this->providers[$provider->algorithm()] = $provider;
        }
    }

    public function encrypt(string $plaintext, FieldContext $context): string
    {
        $version = $this->keyring->activeVersion();
        $raw = $this->active->encrypt($plaintext, $context->associatedData(), $this->keyring->key($version));

        return $version . '.' . $this->active->algorithm() . ':' . base64_encode($raw);
    }

    public function decrypt(string $stored, FieldContext $context): string
    {
        if (!preg_match('/^(k\d{1,4})\.([a-z0-9]{2,8}):([A-Za-z0-9+\/=]+)$/', $stored, $match)) {
            throw new IntegrityException('Format de valeur chiffrée invalide.');
        }
        $provider = $this->providers[$match[2]] ?? null;
        $raw = base64_decode($match[3], true);
        if ($provider === null || $raw === false) {
            throw new IntegrityException('Algorithme ou encodage non pris en charge.');
        }

        return $provider->decrypt($raw, $context->associatedData(), $this->keyring->key($match[1]));
    }

    public function encryptDecimal(?Decimal $value, FieldContext $context): ?string
    {
        return $value === null ? null : $this->encrypt($value->toString(), $context);
    }

    public function decryptDecimal(?string $stored, FieldContext $context): ?Decimal
    {
        return $stored === null ? null : Decimal::of($this->decrypt($stored, $context));
    }

    /** Indique si une valeur doit être rechiffrée (rotation de clé ou changement d'algorithme). */
    public function needsReencryption(string $stored): bool
    {
        return !str_starts_with($stored, $this->keyring->activeVersion() . '.' . $this->active->algorithm() . ':');
    }
}
