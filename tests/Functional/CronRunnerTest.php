<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Cron\CronRunner;
use App\Cron\Job;
use App\Cron\JobResult;
use App\Cron\Schedule;
use App\Support\FrozenClock;
use RuntimeException;
use Tests\Support\DatabaseTestCase;

final class CronRunnerTest extends DatabaseTestCase
{
    private function runner(FrozenClock $clock, Job ...$jobs): CronRunner
    {
        return new CronRunner($this->app->db(), $this->app->logger(), $this->app->securityEvents(), $clock, $jobs);
    }

    private static function job(string $name, callable $run, string $from = '02:15'): Job
    {
        return new class ($name, $run, $from) implements Job {
            public int $calls = 0;

            public function __construct(private readonly string $jobName, private $callback, private readonly string $from)
            {
            }

            public function name(): string
            {
                return $this->jobName;
            }

            public function schedule(): Schedule
            {
                return Schedule::dailyFrom($this->from);
            }

            public function run(): JobResult
            {
                $this->calls++;

                return ($this->callback)($this->calls);
            }
        };
    }

    public function testDailyJobRunsOncePerParisDay(): void
    {
        $clock = new FrozenClock('2026-11-12 01:00:00'); // 02:00 à Paris
        $job = self::job('snapshot-daily', static fn () => new JobResult(3));
        $runner = $this->runner($clock, $job);

        self::assertSame('skipped', $runner->runDue()['snapshot-daily']);
        $clock->advance('+15 minutes'); // 02:15
        self::assertSame('succeeded', $runner->runDue()['snapshot-daily']);
        $clock->advance('+15 minutes');
        self::assertSame('skipped', $runner->runDue()['snapshot-daily'], 'Jamais rejouée le même jour.');
        self::assertSame(1, $job->calls);
        self::assertSame(1, $this->countRows('cron_runs', "job = 'snapshot-daily'"));
    }

    public function testPartialRunIsResumedLaterTheSameDay(): void
    {
        $clock = new FrozenClock('2026-11-12 01:15:00');
        $job = self::job('snapshot-daily', static fn (int $call) => $call === 1 ? new JobResult(8, 2) : new JobResult(2));
        $runner = $this->runner($clock, $job);

        self::assertSame('partial', $runner->runDue()['snapshot-daily']);
        self::assertSame(1, $this->countRows('security_events', "event = 'cron.failed'"));
        $clock->advance('+15 minutes');
        self::assertSame('succeeded', $runner->runDue()['snapshot-daily']);
        self::assertSame(1, $this->countRows('cron_runs', "job = 'snapshot-daily'"), 'Toujours une seule ligne par jour.');
    }

    public function testExceptionIsLoggedWithoutCrashingOtherJobs(): void
    {
        $clock = new FrozenClock('2026-11-12 02:00:00');
        $broken = self::job('broken', static fn () => throw new RuntimeException('Montant 24400 invalide'));
        $healthy = self::job('healthy', static fn () => new JobResult(1));
        $report = $this->runner($clock, $broken, $healthy)->runDue();

        self::assertSame('failed', $report['broken']);
        self::assertSame('succeeded', $report['healthy']);
        $row = $this->app->db()->fetchOne("SELECT error_ref FROM cron_runs WHERE job = 'broken'");
        self::assertMatchesRegularExpression('/^[0-9A-F]{8}$/', (string) ($row['error_ref'] ?? ''));
    }

    public function testFallBackDayRunsOnlyOnce(): void
    {
        // 25/10/2026 : 02:15 Paris survient à 00:15 UTC puis à 01:15 UTC.
        $clock = new FrozenClock('2026-10-25 00:15:00');
        $job = self::job('snapshot-daily', static fn () => new JobResult(1));
        $runner = $this->runner($clock, $job);
        $runner->runDue();
        $clock->advance('+1 hour');
        $runner->runDue();
        self::assertSame(1, $job->calls);
    }

    public function testSpringForwardDayStillRuns(): void
    {
        // 28/03/2027 : il n'y a pas de 02:15 à Paris ; le passage de 01:00 UTC (03:00 Paris) exécute la tâche.
        $clock = new FrozenClock('2027-03-28 00:45:00');
        $job = self::job('snapshot-daily', static fn () => new JobResult(1));
        $runner = $this->runner($clock, $job);
        self::assertSame('skipped', $runner->runDue()['snapshot-daily']);
        $clock->advance('+15 minutes');
        self::assertSame('succeeded', $runner->runDue()['snapshot-daily']);
    }

    public function testHeartbeatIsRecordedOncePerPass(): void
    {
        $clock = new FrozenClock('2026-11-12 09:00:00');
        $runner = $this->runner($clock);
        self::assertSame('succeeded', $runner->runDue()['heartbeat']);
        self::assertSame('skipped', $runner->runDue()['heartbeat']);
        self::assertSame('2026-11-12 09:00:00', $runner->lastHeartbeat());
    }

    public function testStaleRunningExecutionIsRetried(): void
    {
        $clock = new FrozenClock('2026-11-12 02:00:00');
        $this->app->db()->run("INSERT INTO cron_runs (job, run_key, status, started_at) VALUES ('snapshot-daily', '2026-11-12', 'running', '2026-11-12 01:20:00')");
        $job = self::job('snapshot-daily', static fn () => new JobResult(1));
        self::assertSame('skipped', $this->runner($clock, $job)->runDue()['snapshot-daily'], 'Exécution en cours récente : on attend.');
        $clock->advance('+1 hour');
        self::assertSame('succeeded', $this->runner($clock, $job)->runDue()['snapshot-daily'], 'Exécution bloquée depuis plus d\'une heure : reprise.');
    }
}
