<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use InvalidArgumentException;

/**
 * Identifiant interne d'organisation, résolu EXCLUSIVEMENT côté serveur à partir de la session
 * (middleware OrganizationContext). Il n'est jamais construit à partir d'une donnée du navigateur.
 */
final class OrgId
{
    public function __construct(public readonly int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException('Identifiant d\'organisation invalide.');
        }
    }
}
