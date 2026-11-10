<?php

declare(strict_types=1);

namespace App\Security\Crypto;

use InvalidArgumentException;

/**
 * Contexte lié à une valeur chiffrée, authentifié comme données associées (AAD).
 */
final class FieldContext
{
    public function __construct(
        public readonly string $table,
        public readonly string $column,
        public readonly int $organizationId,
        public readonly string $rowPublicId,
    ) {
        foreach ([$table, $column] as $name) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
                throw new InvalidArgumentException('Nom de table ou de colonne invalide.');
            }
        }
        if ($organizationId <= 0 || $rowPublicId === '') {
            throw new InvalidArgumentException('Contexte de chiffrement incomplet.');
        }
    }

    public function associatedData(): string
    {
        return implode('|', ['agency-os', 'v1', $this->table, $this->column, (string) $this->organizationId, $this->rowPublicId]);
    }
}
