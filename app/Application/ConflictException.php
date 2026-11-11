<?php

declare(strict_types=1);

namespace App\Application;

use RuntimeException;

/**
 * Modification refusée sur une ressource figée (simulation finalisée, remplacée ou archivée) : réponse 409.
 */
final class ConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cette version est figée : créez une nouvelle version pour la modifier.');
    }
}
