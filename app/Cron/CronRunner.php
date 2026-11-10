<?php

declare(strict_types=1);

namespace App\Cron;

use App\Support\TypedArray;
use App\Infrastructure\Database\Connection;
use App\Security\SecurityEventLogger;
use App\Support\Clock;
use App\Support\Logger;
use PDOException;
use Throwable;

/**
 * Exécuteur des tâches planifiées, appelé toutes les 15 minutes par UNE ligne cron (bin/cron.php).
 *
 * - échéances évaluées en heure de Paris (Schedule) ;
 * - anti-doublon : (job, run_key) unique dans cron_runs ; une exécution réussie n'est jamais rejouée ;
 *   une exécution « partial » ou « failed » est reprise aux passages suivants de la même période ;
 * - verrou MySQL GET_LOCK : deux exécutions simultanées d'une même tâche sont impossibles ;
 * - échecs journalisés (sans données) et signalés dans le journal de sécurité ;
 * - battement (« heartbeat ») enregistré à chaque passage.
 */
final class CronRunner
{
    /** @var list<Job> */
    private array $jobs;

    /**
     * @param list<Job> $jobs
     */
    public function __construct(
        private readonly Connection $db,
        private readonly Logger $logger,
        private readonly SecurityEventLogger $events,
        private readonly Clock $clock,
        array $jobs,
    ) {
        $this->jobs = $jobs;
    }

    /**
     * @return array<string, string> statut par tâche pour ce passage (« skipped » si non due ou déjà faite)
     */
    public function runDue(): array
    {
        $local = $this->clock->nowLocal();
        $report = ['heartbeat' => $this->heartbeat($local->format('Y-m-d\TH:i'))];

        foreach ($this->jobs as $job) {
            $key = $job->schedule()->runKey($local);
            $report[$job->name()] = $key === null ? 'skipped' : $this->runOnce($job, $key);
        }

        return $report;
    }

    private function runOnce(Job $job, string $key): string
    {
        $existing = $this->db->fetchOne('SELECT status, started_at FROM cron_runs WHERE job = :job AND run_key = :key', ['job' => $job->name(), 'key' => $key]);
        if ($existing !== null && $existing['status'] === 'succeeded') {
            return 'skipped';
        }
        // Une exécution « running » depuis plus d'une heure est considérée comme échouée (processus interrompu).
        $staleBefore = $this->clock->nowUtc()->modify('-1 hour')->format('Y-m-d H:i:s');
        if ($existing !== null && $existing['status'] === 'running' && TypedArray::string($existing, 'started_at') > $staleBefore) {
            return 'skipped';
        }
        $lock = $this->db->fetchOne('SELECT GET_LOCK(:name, 0) AS acquired', ['name' => 'aos_cron_' . $job->name()]);
        if (TypedArray::int($lock ?? [], 'acquired', 0) !== 1) {
            return 'locked';
        }

        try {
            $now = $this->clock->nowUtc()->format('Y-m-d H:i:s');
            if ($existing === null) {
                try {
                    $this->db->run(
                        'INSERT INTO cron_runs (job, run_key, status, started_at) VALUES (:job, :key, \'running\', :now)',
                        ['job' => $job->name(), 'key' => $key, 'now' => $now],
                    );
                } catch (PDOException) {
                    return 'skipped'; // Une autre exécution a enregistré cette période entre-temps.
                }
            } else {
                $this->db->run(
                    'UPDATE cron_runs SET status = \'running\', started_at = :now, finished_at = NULL WHERE job = :job AND run_key = :key',
                    ['job' => $job->name(), 'key' => $key, 'now' => $now],
                );
            }

            try {
                $result = $job->run();
                $status = $result->status();
                $this->finish($job, $key, $status, $result->itemsOk, $result->itemsFailed, null);
                if ($status !== 'succeeded') {
                    $this->events->record('cron.failed', context: ['job' => $job->name(), 'count' => $result->itemsFailed]);
                }

                return $status;
            } catch (Throwable $e) {
                $ref = strtoupper(bin2hex(random_bytes(4)));
                $this->logger->exception($e, $ref);
                $this->finish($job, $key, 'failed', 0, 0, $ref);
                $this->events->record('cron.failed', context: ['job' => $job->name(), 'reason' => 'exception']);

                return 'failed';
            }
        } finally {
            $this->db->fetchOne('SELECT RELEASE_LOCK(:name) AS released', ['name' => 'aos_cron_' . $job->name()]);
        }
    }

    private function finish(Job $job, string $key, string $status, int $ok, int $failed, ?string $ref): void
    {
        $this->db->run(
            'UPDATE cron_runs SET status = :status, finished_at = :now, items_ok = :ok, items_failed = :failed, error_ref = :ref
              WHERE job = :job AND run_key = :key',
            ['status' => $status, 'now' => $this->clock->nowUtc()->format('Y-m-d H:i:s'), 'ok' => $ok, 'failed' => $failed,
             'ref' => $ref, 'job' => $job->name(), 'key' => $key],
        );
    }

    private function heartbeat(string $key): string
    {
        try {
            $this->db->run(
                'INSERT INTO cron_runs (job, run_key, status, started_at, finished_at) VALUES (\'heartbeat\', :key, \'succeeded\', :now, :now2)',
                ['key' => $key, 'now' => $this->clock->nowUtc()->format('Y-m-d H:i:s'), 'now2' => $this->clock->nowUtc()->format('Y-m-d H:i:s')],
            );

            return 'succeeded';
        } catch (PDOException) {
            return 'skipped';
        }
    }

    /** Dernier battement (UTC), pour l'alerte « cron absent depuis plus de 26 h ». */
    public function lastHeartbeat(): ?string
    {
        $row = $this->db->fetchOne('SELECT MAX(started_at) AS at FROM cron_runs WHERE job = \'heartbeat\'');

        return $row === null ? null : TypedArray::nullableString($row, 'at');
    }
}
