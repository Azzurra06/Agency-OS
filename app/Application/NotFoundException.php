<?php

declare(strict_types=1);

namespace App\Application;

use RuntimeException;

/**
 * Ressource absente de l'organisation courante (ou d'une autre organisation : réponse identique, 404).
 */
final class NotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ressource introuvable.');
    }
}
