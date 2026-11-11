<?php

declare(strict_types=1);

namespace App\Application;

use RuntimeException;

/**
 * Erreurs de validation par champ, traduites en réponse 422 par les contrôleurs.
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Données invalides.');
    }
}
