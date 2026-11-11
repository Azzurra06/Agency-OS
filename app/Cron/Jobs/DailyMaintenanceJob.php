<?php

declare(strict_types=1);

namespace App\Cron\Jobs;

use App\Application\AccountService;
use App\Application\StrategyService;
use App\Cron\Job;
use App\Cron\JobResult;
use App\Cron\Schedule;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\OrgId;
use App\Support\TypedArray;
use Throwable;

/**
 * Maintenance quotidienne « dès 02:30 », heure de Paris (docs/05 §4.2) :
 * purge des comptes dont la suppression est confirmée depuis 7 jours (D8), puis réévaluation des recommandations
 * de chaque organisation réelle (reports arrivés à échéance, données devenues anciennes).
 */
final class DailyMaintenanceJob implements Job
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly StrategyService $strategy,
        private readonly Connection $db,
    ) {
    }

    public function name(): string
    {
        return 'daily-maintenance';
    }

    public function schedule(): Schedule
    {
        return Schedule::dailyFrom('02:30');
    }

    public function run(): JobResult
    {
        $ok = $this->accounts->purgeScheduled();
        $failed = 0;
        foreach ($this->db->fetchAll("SELECT id FROM organizations WHERE is_demo = 0 AND status = 'active' ORDER BY id LIMIT 1000") as $row) {
            try {
                $this->strategy->evaluate(new OrgId(TypedArray::int($row, 'id')));
                $ok++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return new JobResult($ok, $failed);
    }
}
