<?php

declare(strict_types=1);

namespace App\Cron;

interface Job
{
    /** Nom stable de la tâche (clé dans cron_runs). */
    public function name(): string;

    public function schedule(): Schedule;

    /**
     * Exécute la tâche. Doit être idempotente : une ré-exécution ne crée ni doublon ni écrasement.
     */
    public function run(): JobResult;
}
