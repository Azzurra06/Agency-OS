<?php

declare(strict_types=1);

namespace App\Security;

use InvalidArgumentException;

/**
 * Hachage des mots de passe (docs/04-securite.md §3.1 et §17.4).
 *
 * - Argon2id si disponible ET demandé (hébergement commercial) ;
 * - sinon bcrypt, coût 12 (MVP portfolio, décision S2).
 * password_needs_rehash() fait migrer automatiquement les anciens hachages à la connexion.
 */
final class PasswordHasher
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 128;
    private const BCRYPT_MAX_BYTES = 72;

    private readonly string $algorithm;

    /** @var array<string, int> */
    private readonly array $options;

    public function __construct(bool $preferArgon2id = false)
    {
        if ($preferArgon2id && defined('PASSWORD_ARGON2ID')) {
            $this->algorithm = PASSWORD_ARGON2ID;
            $this->options = ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1];
        } else {
            $this->algorithm = PASSWORD_BCRYPT;
            $this->options = ['cost' => 12];
        }
    }

    public function algorithmName(): string
    {
        return $this->algorithm === PASSWORD_BCRYPT ? 'bcrypt' : 'argon2id';
    }

    /**
     * Vérifie la politique (longueur) ; renvoie un code d'erreur ou null.
     * La vérification des mots de passe courants est faite par le service d'authentification.
     */
    public function policyError(string $password): ?string
    {
        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH) {
            return 'password_too_short';
        }
        if ($length > self::MAX_LENGTH) {
            return 'password_too_long';
        }
        if ($this->algorithm === PASSWORD_BCRYPT && strlen($password) > self::BCRYPT_MAX_BYTES) {
            // Refus explicite plutôt que troncature silencieuse par bcrypt.
            return 'password_too_long';
        }

        return null;
    }

    public function hash(string $password): string
    {
        $error = $this->policyError($password);
        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }

        return password_hash($password, $this->algorithm, $this->options);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }
}
