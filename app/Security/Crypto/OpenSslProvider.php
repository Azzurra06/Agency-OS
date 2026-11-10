<?php

declare(strict_types=1);

namespace App\Security\Crypto;

/**
 * AES-256-GCM via OpenSSL : nonce aléatoire de 12 octets par valeur, tag de 16 octets vérifié.
 * Format brut : nonce (12) ‖ tag (16) ‖ chiffré.
 */
final class OpenSslProvider implements CryptoProvider
{
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    public function algorithm(): string
    {
        return 'gcm';
    }

    public function encrypt(string $plaintext, string $associatedData, string $key): string
    {
        self::assertKey($key);
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $associatedData, self::TAG_BYTES);
        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new IntegrityException('Chiffrement impossible.');
        }

        return $nonce . $tag . $ciphertext;
    }

    public function decrypt(string $ciphertext, string $associatedData, string $key): string
    {
        self::assertKey($key);
        if (strlen($ciphertext) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw new IntegrityException('Chiffré invalide.');
        }
        $nonce = substr($ciphertext, 0, self::NONCE_BYTES);
        $tag = substr($ciphertext, self::NONCE_BYTES, self::TAG_BYTES);
        $data = substr($ciphertext, self::NONCE_BYTES + self::TAG_BYTES);
        $plaintext = openssl_decrypt($data, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $associatedData);
        if ($plaintext === false) {
            throw new IntegrityException('Échec de l\'authentification du chiffré.');
        }

        return $plaintext;
    }

    private static function assertKey(string $key): void
    {
        if (strlen($key) !== 32) {
            throw new IntegrityException('Clé de chiffrement invalide (32 octets attendus).');
        }
    }
}
