<?php

declare(strict_types=1);

namespace App\Security\Crypto;

use RuntimeException;

/**
 * Échec de déchiffrement ou d'authentification. Jamais remplacé silencieusement par 0 ou NULL.
 */
final class IntegrityException extends RuntimeException
{
}
