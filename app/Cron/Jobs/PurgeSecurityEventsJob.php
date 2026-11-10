<?php

declare(strict_types=1);

namespace App\Cron\Jobs;

use App\Cron\Job;
use App\Cron\JobResult;
use App\Cron\Schedule;
use App\Security\SecurityEventLogger;

/** Purge quotidienne du journal de sécurité au-delà de 6 mois (docs/04-securite.md §9.3). */
final class PurgeSecurityEventsJob implements Job
{
    public function __construct(private readonly SecurityEventLogger $events)
    {
    }

    public function name(): string
    {
        return 'purge-security-events';
    }

    public function schedule(): Schedule
    {
        return Schedule::dailyFrom('02:30');
    }

    public function run(): JobResult
    {
        return new JobResult($this->events->purgeOlderThanMonths(6));
    }
}
