<?php

declare(strict_types=1);

namespace App\Cron\Jobs;

use App\Application\DemoService;
use App\Cron\Job;
use App\Cron\JobResult;
use App\Cron\Schedule;

/** Purge horaire des démonstrations expirées (complétée par la purge de secours à chaque création). */
final class PurgeDemoJob implements Job
{
    public function __construct(private readonly DemoService $demos)
    {
    }

    public function name(): string
    {
        return 'purge-demo';
    }

    public function schedule(): Schedule
    {
        return Schedule::hourly();
    }

    public function run(): JobResult
    {
        return new JobResult($this->demos->purgeExpired(500));
    }
}
