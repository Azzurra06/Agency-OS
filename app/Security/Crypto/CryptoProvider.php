<?php

declare(strict_types=1);

namespace App\Security\Crypto;

/**
 * Chiffrement authentifié (AEAD) derrière une interface unique (décision S2, docs/04-securite.md §17.4).
 *
 * - OpenSslProvider : AES-256-GCM (MVP portfolio, extension OpenSSL intégrée) ;
 * - SodiumProvider  : XChaCha20-Poly1305 (SaaS commercial).
 *
 * Aucune primitive cryptographique n'est implémentée ici : uniquement des fonctions reconnues.
 */
interface CryptoProvider
{
    /** Identifiant court inscrit dans le chiffré (permet de relire après un changement de fournisseur). */
    public function algorithm(): string;

    /** Chiffre et authentifie ; les données associées (AAD) sont authentifiées mais non chiffrées. */
    public function encrypt(string $plaintext, string $associatedData, string $key): string;

    /** @throws IntegrityException si la clé, le chiffré ou les données associées ne correspondent pas */
    public function decrypt(string $ciphertext, string $associatedData, string $key): string;
}
