<?php

declare(strict_types=1);

namespace App\Security\Crypto;

use RuntimeException;
use SodiumException;

/**
 * XChaCha20-Poly1305 (libsodium), pour l'hébergement commercial. Format brut : nonce (24) ‖ chiffré+tag.
 */
final class SodiumProvider implements CryptoProvider
{
    public function __construct()
    {
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new RuntimeException('Extension sodium indisponible.');
        }
    }

    public function algorithm(): string
    {
        return 'xc20';
    }

    public function encrypt(string $plaintext, string $associatedData, string $key): string
    {
        self::assertKey($key);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $associatedData, $nonce, $key);
    }

    public function decrypt(string $ciphertext, string $associatedData, string $key): string
    {
        self::assertKey($key);
        $nonceBytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($ciphertext) < $nonceBytes + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new IntegrityException('Chiffré invalide.');
        }
        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($ciphertext, $nonceBytes),
                $associatedData,
                substr($ciphertext, 0, $nonceBytes),
                $key,
            );
        } catch (SodiumException) {
            $plaintext = false;
        }
        if ($plaintext === false) {
            throw new IntegrityException('Échec de l\'authentification du chiffré.');
        }

        return $plaintext;
    }

    private static function assertKey(string $key): void
    {
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new IntegrityException('Clé de chiffrement invalide (32 octets attendus).');
        }
    }
}
