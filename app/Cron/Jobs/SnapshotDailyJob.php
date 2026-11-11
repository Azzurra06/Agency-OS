<?php

declare(strict_types=1);

namespace App\Cron\Jobs;

use App\Application\HistoryService;
use App\Cron\Job;
use App\Cron\JobResult;
use App\Cron\Schedule;
use App\Infrastructure\Database\OrgId;
use App\Infrastructure\Repositories\SnapshotRepository;
use App\Support\Clock;
use DateTimeZone;
use Throwable;

/**
 * Instantané quotidien « dès 02:15 », heure de Paris (docs/05 §4.2-4.3) : organisations réelles sans instantané du jour,
 * par lots ; un échec rend l'exécution « partial », reprise aux passages suivants du même jour. Jamais d'écrasement.
 */
final class SnapshotDailyJob implements Job
{
    public const BATCH = 50;

    public function __construct(
        private readonly SnapshotRepository $snapshots,
        private readonly HistoryService $history,
        private readonly Clock $clock,
    ) {
    }

    public function name(): string
    {
        return 'snapshot-daily';
    }

    public function schedule(): Schedule
    {
        return Schedule::dailyFrom('02:15');
    }

    public function run(): JobResult
    {
        $date = $this->clock->nowUtc()->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d');
        $ok = 0;
        $failed = 0;
        foreach ($this->snapshots->organizationsWithoutSnapshot($date, self::BATCH * 4) as $org) {
            try {
                $this->history->capture(new OrgId($org['id']), $org['public_id'], $date);
                $ok++;
            } catch (Throwable) {
                $failed++; // l'erreur est reprise au prochain passage ; aucun détail financier n'est journalisé
            }
        }

        return new JobResult($ok, $failed);
    }
}
