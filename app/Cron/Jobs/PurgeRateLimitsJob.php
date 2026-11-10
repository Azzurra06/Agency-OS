<?php

declare(strict_types=1);

namespace App\Cron\Jobs;

use App\Cron\Job;
use App\Cron\JobResult;
use App\Cron\Schedule;
use App\Security\RateLimiter;

/** Purge quotidienne des tentatives de plus de 24 h (minimisation, docs/04-securite.md §9.3). */
final class PurgeRateLimitsJob implements Job
{
    public function __construct(private readonly RateLimiter $limiter)
    {
    }

    public function name(): string
    {
        return 'purge-rate-limits';
    }

    public function schedule(): Schedule
    {
        return Schedule::dailyFrom('02:30');
    }

    public function run(): JobResult
    {
        return new JobResult($this->limiter->purgeOlderThan(86400));
    }
}
