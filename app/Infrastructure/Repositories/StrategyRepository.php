<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Domain\Strategy\Finding;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\OrgId;
use App\Infrastructure\Database\OrgScopedRepository;
use App\Security\Crypto\FieldContext;
use App\Security\Crypto\FieldCrypto;
use App\Support\Clock;
use App\Support\Decimal;
use App\Support\PublicId;
use App\Support\TypedArray;
use JsonException;

/**
 * Recommandations (preuves et impact CHIFFRÉS, D4) et plan d'action.
 *
 * @phpstan-type RecoRow array{id: int, public_id: string, rule_code: string, subject_key: string, status: string, severity: string, priority_score: int, dimension_code: string|null, evidence: array<string, mixed>, impact_kind: string, impact_amount: string|null, data_quality: string, reappeared: bool, snoozed_until: string|null, dismissed_severity: string|null, first_detected_at: string, last_evaluated_at: string, resolved_at: string|null}
 * @phpstan-type TaskRow array{public_id: string, label: string, is_done: bool}
 * @phpstan-type ActionRow array{id: int, public_id: string, recommendation_id: int|null, title: string, description: string|null, status: string, due_date: string|null, completed_at: string|null, created_at: string, tasks: list<TaskRow>}
 */
final class StrategyRepository extends OrgScopedRepository
{
    private const RECO_COLUMNS = 'id, public_id, rule_code, subject_key, status, severity, priority_score, dimension_code, evidence_enc, impact_kind, impact_amount_enc,
        data_quality, reappeared, snoozed_until, dismissed_severity, first_detected_at, last_evaluated_at, resolved_at';

    public function __construct(Connection $db, private readonly FieldCrypto $crypto, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /** @return list<RecoRow> */
    public function recommendations(OrgId $org): array
    {
        return array_map(
            fn (array $r): array => $this->mapReco($org, $r),
            $this->scopedAll($org, 'SELECT ' . self::RECO_COLUMNS . ' FROM recommendations WHERE organization_id = :org ORDER BY priority_score DESC, first_detected_at ASC, id ASC'),
        );
    }

    /** @return RecoRow|null */
    public function recommendation(OrgId $org, string $publicId): ?array
    {
        if (!PublicId::isValid($publicId)) {
            return null;
        }
        $row = $this->scopedOne($org, 'SELECT ' . self::RECO_COLUMNS . ' FROM recommendations WHERE organization_id = :org AND public_id = :pid', ['pid' => $publicId]);

        return $row === null ? null : $this->mapReco($org, $row);
    }

    /** @return RecoRow|null */
    public function recommendationById(OrgId $org, int $id): ?array
    {
        $row = $this->scopedOne($org, 'SELECT ' . self::RECO_COLUMNS . ' FROM recommendations WHERE organization_id = :org AND id = :id', ['id' => $id]);

        return $row === null ? null : $this->mapReco($org, $row);
    }

    /**
     * Réconciliation (docs/02 §7.3), dans une transaction : création, mise à jour, réapparition, résolution automatique.
     *
     * @param list<Finding> $findings
     * @return array{created: int, updated: int, reappeared: int, resolved: int}
     */
    public function reconcile(OrgId $org, array $findings, int $ruleVersion, string $today): array
    {
        return $this->db->transaction(function () use ($org, $findings, $ruleVersion, $today): array {
            $now = $this->now();
            $counts = ['created' => 0, 'updated' => 0, 'reappeared' => 0, 'resolved' => 0];
            // Reports arrivés à échéance : retour à « nouvelle ».
            $this->scopedExecute($org, "UPDATE recommendations SET status = 'new', snoozed_until = NULL WHERE organization_id = :org AND status = 'snoozed' AND snoozed_until <= :today", ['today' => $today]);
            $existing = [];
            foreach ($this->scopedAll($org, 'SELECT id, public_id, rule_code, subject_key, status, severity, dismissed_severity FROM recommendations WHERE organization_id = :org FOR UPDATE') as $r) {
                $existing[TypedArray::string($r, 'rule_code') . '|' . TypedArray::string($r, 'subject_key')] = $r;
            }
            $seen = [];
            $rank = ['info' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3];
            foreach ($findings as $f) {
                $key = $f->ruleCode . '|' . $f->subjectKey;
                $seen[$key] = true;
                $row = $existing[$key] ?? null;
                if ($row === null) {
                    $publicId = PublicId::generate();
                    $this->db->run(
                        'INSERT INTO recommendations (public_id, organization_id, rule_code, subject_key, provider, rule_version, status, severity, priority_score, dimension_code,
                             evidence_enc, impact_kind, impact_amount_enc, data_quality, first_detected_at, last_evaluated_at)
                         VALUES (:pid, :org, :code, :subject, \'rules\', :v, \'new\', :sev, :prio, :dim, :ev, :kind, :impact, :q, :now, :now2)',
                        ['pid' => $publicId, 'org' => $org->value, 'code' => $f->ruleCode, 'subject' => $f->subjectKey, 'v' => $ruleVersion, 'sev' => $f->severity,
                         'prio' => $f->priority, 'dim' => $f->dimension] + $this->payload($org, $publicId, $f) + ['q' => $f->dataQuality, 'now' => $now, 'now2' => $now],
                    );
                    $counts['created']++;
                    continue;
                }
                $publicId = TypedArray::string($row, 'public_id');
                $status = TypedArray::string($row, 'status');
                $newStatus = $status;
                $reappeared = null;
                if (in_array($status, ['auto_resolved', 'done'], true)) {
                    $newStatus = 'new';
                    $reappeared = 1;
                    $counts['reappeared']++;
                } elseif ($status === 'dismissed') {
                    $dismissedAt = TypedArray::nullableString($row, 'dismissed_severity') ?? TypedArray::string($row, 'severity');
                    if ($rank[$f->severity] > ($rank[$dismissedAt] ?? 0)) {
                        $newStatus = 'new'; // la sévérité a augmenté depuis qu'elle a été ignorée
                        $reappeared = 1;
                        $counts['reappeared']++;
                    }
                }
                $this->scopedExecute(
                    $org,
                    'UPDATE recommendations SET status = :status, severity = :sev, priority_score = :prio, dimension_code = :dim, rule_version = :v, evidence_enc = :ev,
                            impact_kind = :kind, impact_amount_enc = :impact, data_quality = :q, last_evaluated_at = :now, resolved_at = IF(:reset1 = 1, NULL, resolved_at),
                            reappeared = COALESCE(:re, reappeared), dismissed_severity = IF(:reset2 = 1, NULL, dismissed_severity)
                      WHERE id = :id AND organization_id = :org',
                    ['status' => $newStatus, 'sev' => $f->severity, 'prio' => $f->priority, 'dim' => $f->dimension, 'v' => $ruleVersion]
                    + $this->payload($org, $publicId, $f)
                    + ['q' => $f->dataQuality, 'now' => $now, 'reset1' => (int) ($newStatus !== $status && $newStatus === 'new'), 're' => $reappeared,
                       'reset2' => (int) ($newStatus !== $status && $newStatus === 'new'), 'id' => TypedArray::int($row, 'id')],
                );
                $counts['updated']++;
            }
            foreach ($existing as $key => $row) {
                if (!isset($seen[$key]) && in_array(TypedArray::string($row, 'status'), ['new', 'accepted', 'snoozed'], true)) {
                    $this->scopedExecute(
                        $org,
                        "UPDATE recommendations SET status = 'auto_resolved', resolved_at = :now, last_evaluated_at = :now2 WHERE id = :id AND organization_id = :org",
                        ['now' => $now, 'now2' => $now, 'id' => TypedArray::int($row, 'id')],
                    );
                    $counts['resolved']++;
                }
            }

            return $counts;
        });
    }

    public function setStatus(OrgId $org, int $id, string $status, ?string $snoozedUntil = null, ?string $dismissedSeverity = null): void
    {
        $this->scopedExecute(
            $org,
            'UPDATE recommendations SET status = :status, snoozed_until = :until, dismissed_severity = :dsev,
                    resolved_at = :resolved, reappeared = IF(:keep = 1, reappeared, 0) WHERE id = :id AND organization_id = :org',
            // Comparaisons faites en PHP : aucune comparaison paramètre / littéral texte en SQL (collations différentes selon le serveur).
            ['status' => $status, 'until' => $snoozedUntil, 'dsev' => $dismissedSeverity, 'resolved' => $status === 'done' ? $this->now() : null,
             'keep' => (int) ($status === 'new'), 'id' => $id],
        );
    }

    /* ------------------------------------------------------------------ Plan d'action */

    public function planId(OrgId $org): int
    {
        $row = $this->scopedOne($org, 'SELECT id FROM action_plans WHERE organization_id = :org');
        if ($row !== null) {
            return TypedArray::int($row, 'id');
        }
        $this->db->run('INSERT IGNORE INTO action_plans (organization_id, name, created_at) VALUES (:org, \'Plan d\'\'action\', :now)', ['org' => $org->value, 'now' => $this->now()]);

        return TypedArray::int($this->scopedOne($org, 'SELECT id FROM action_plans WHERE organization_id = :org') ?? [], 'id');
    }

    /** @return list<ActionRow> */
    public function actions(OrgId $org): array
    {
        $tasks = [];
        foreach ($this->scopedAll($org, 'SELECT action_item_id, public_id, label, is_done FROM action_item_tasks WHERE organization_id = :org ORDER BY position, id') as $t) {
            $tasks[TypedArray::int($t, 'action_item_id')][] = ['public_id' => TypedArray::string($t, 'public_id'), 'label' => TypedArray::string($t, 'label'), 'is_done' => TypedArray::int($t, 'is_done') === 1];
        }

        return array_map(static function (array $r) use ($tasks): array {
            $reco = TypedArray::nullableString($r, 'recommendation_id');
            $id = TypedArray::int($r, 'id');

            return [
                'id' => $id, 'public_id' => TypedArray::string($r, 'public_id'), 'recommendation_id' => $reco === null ? null : (int) $reco,
                'title' => TypedArray::string($r, 'title'), 'description' => TypedArray::nullableString($r, 'description'), 'status' => TypedArray::string($r, 'status'),
                'due_date' => TypedArray::nullableString($r, 'due_date'), 'completed_at' => TypedArray::nullableString($r, 'completed_at'),
                'created_at' => TypedArray::string($r, 'created_at'), 'tasks' => $tasks[$id] ?? [],
            ];
        }, $this->scopedAll($org, 'SELECT id, public_id, recommendation_id, title, description, status, due_date, completed_at, created_at FROM action_items WHERE organization_id = :org ORDER BY position, id'));
    }

    /** @return ActionRow|null */
    public function action(OrgId $org, string $publicId): ?array
    {
        foreach ($this->actions($org) as $a) {
            if ($a['public_id'] === $publicId) {
                return $a;
            }
        }

        return null;
    }

    public function actionForRecommendation(OrgId $org, int $recoId): ?string
    {
        $row = $this->scopedOne($org, 'SELECT public_id FROM action_items WHERE organization_id = :org AND recommendation_id = :r', ['r' => $recoId]);

        return $row === null ? null : TypedArray::string($row, 'public_id');
    }

    /** @param list<string> $tasks */
    public function createAction(OrgId $org, ?int $recoId, string $title, ?string $description, ?string $dueDate, array $tasks): string
    {
        return $this->db->transaction(function () use ($org, $recoId, $title, $description, $dueDate, $tasks): string {
            $plan = $this->planId($org);
            $publicId = PublicId::generate();
            $now = $this->now();
            $position = TypedArray::int($this->scopedOne($org, 'SELECT COUNT(*) AS n FROM action_items WHERE organization_id = :org') ?? [], 'n', 0);
            $this->db->run(
                'INSERT INTO action_items (public_id, organization_id, plan_id, recommendation_id, title, description, status, due_date, position, created_at, updated_at)
                 VALUES (:pid, :org, :plan, :reco, :title, :descr, \'todo\', :due, :pos, :now, :now2)',
                ['pid' => $publicId, 'org' => $org->value, 'plan' => $plan, 'reco' => $recoId, 'title' => $title, 'descr' => $description, 'due' => $dueDate, 'pos' => $position, 'now' => $now, 'now2' => $now],
            );
            $id = $this->db->lastInsertId();
            foreach ($tasks as $i => $label) {
                $this->insertTask($org, $id, $label, $i + 1);
            }

            return $publicId;
        });
    }

    /** @param array{title?: string, description?: string|null, status?: string, due_date?: string|null} $fields */
    public function updateAction(OrgId $org, int $id, array $fields): void
    {
        $sets = [];
        $params = ['id' => $id, 'now' => $this->now()];
        foreach (['title', 'description', 'status', 'due_date'] as $column) {
            if (array_key_exists($column, $fields)) {
                $sets[] = "{$column} = :{$column}";
                $params[$column] = $fields[$column];
            }
        }
        if (isset($fields['status'])) {
            $sets[] = 'completed_at = ' . ($fields['status'] === 'done' ? ':done_at' : 'NULL');
            if ($fields['status'] === 'done') {
                $params['done_at'] = $this->now();
            }
        }
        if ($sets !== []) {
            $this->scopedExecute($org, 'UPDATE action_items SET ' . implode(', ', $sets) . ', updated_at = :now WHERE id = :id AND organization_id = :org', $params);
        }
    }

    public function deleteAction(OrgId $org, int $id): void
    {
        $this->scopedExecute($org, 'DELETE FROM action_items WHERE id = :id AND organization_id = :org', ['id' => $id]);
    }

    public function addTask(OrgId $org, int $actionId, string $label): string
    {
        $position = TypedArray::int($this->scopedOne($org, 'SELECT COUNT(*) AS n FROM action_item_tasks WHERE organization_id = :org AND action_item_id = :a', ['a' => $actionId]) ?? [], 'n', 0);

        return $this->insertTask($org, $actionId, $label, $position + 1);
    }

    /** @return array{action_item_id: int}|null */
    public function task(OrgId $org, string $publicId): ?array
    {
        if (!PublicId::isValid($publicId)) {
            return null;
        }
        $row = $this->scopedOne($org, 'SELECT action_item_id FROM action_item_tasks WHERE organization_id = :org AND public_id = :pid', ['pid' => $publicId]);

        return $row === null ? null : ['action_item_id' => TypedArray::int($row, 'action_item_id')];
    }

    public function setTask(OrgId $org, string $publicId, bool $done): void
    {
        $this->scopedExecute($org, 'UPDATE action_item_tasks SET is_done = :d WHERE organization_id = :org AND public_id = :pid', ['d' => (int) $done, 'pid' => $publicId]);
    }

    public function deleteTask(OrgId $org, string $publicId): void
    {
        $this->scopedExecute($org, 'DELETE FROM action_item_tasks WHERE organization_id = :org AND public_id = :pid', ['pid' => $publicId]);
    }

    /** Vrai si l'identifiant existe dans une AUTRE organisation (sondage, journalisé). */
    public function existsElsewhere(string $publicId): bool
    {
        if (!PublicId::isValid($publicId)) {
            return false;
        }
        foreach (['recommendations', 'action_items', 'action_item_tasks'] as $table) {
            if ($this->db->fetchOne("SELECT id FROM {$table} WHERE public_id = :pid", ['pid' => $publicId]) !== null) {
                return true;
            }
        }

        return false;
    }

    private function insertTask(OrgId $org, int $actionId, string $label, int $position): string
    {
        $publicId = PublicId::generate();
        $this->db->run(
            'INSERT INTO action_item_tasks (public_id, action_item_id, organization_id, label, is_done, position) VALUES (:pid, :a, :org, :label, 0, :pos)',
            ['pid' => $publicId, 'a' => $actionId, 'org' => $org->value, 'label' => $label, 'pos' => $position],
        );

        return $publicId;
    }

    /** @return array{ev: string, kind: string, impact: string|null} */
    private function payload(OrgId $org, string $publicId, Finding $f): array
    {
        return [
            'ev' => $this->crypto->encrypt(json_encode($f->evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), new FieldContext('recommendations', 'evidence', $org->value, $publicId)),
            'kind' => $f->impactAmount === null ? 'qualitative' : 'quantified',
            'impact' => $this->crypto->encryptDecimal($f->impactAmount?->round(2), new FieldContext('recommendations', 'impact_amount', $org->value, $publicId)),
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return RecoRow
     */
    private function mapReco(OrgId $org, array $r): array
    {
        $publicId = TypedArray::string($r, 'public_id');
        try {
            $evidence = json_decode($this->crypto->decrypt(TypedArray::string($r, 'evidence_enc'), new FieldContext('recommendations', 'evidence', $org->value, $publicId)), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $evidence = [];
        }
        $impact = $this->crypto->decryptDecimal(TypedArray::nullableString($r, 'impact_amount_enc'), new FieldContext('recommendations', 'impact_amount', $org->value, $publicId));

        /** @var array<string, mixed> $evidenceArray */
        $evidenceArray = is_array($evidence) ? $evidence : [];

        return [
            'id' => TypedArray::int($r, 'id'), 'public_id' => $publicId, 'rule_code' => TypedArray::string($r, 'rule_code'),
            'subject_key' => TypedArray::string($r, 'subject_key'), 'status' => TypedArray::string($r, 'status'), 'severity' => TypedArray::string($r, 'severity'),
            'priority_score' => TypedArray::int($r, 'priority_score'), 'dimension_code' => TypedArray::nullableString($r, 'dimension_code'),
            'evidence' => $evidenceArray, 'impact_kind' => TypedArray::string($r, 'impact_kind'),
            'impact_amount' => $impact instanceof Decimal ? $impact->toFixed(2) : null, 'data_quality' => TypedArray::string($r, 'data_quality'),
            'reappeared' => TypedArray::int($r, 'reappeared') === 1, 'snoozed_until' => TypedArray::nullableString($r, 'snoozed_until'),
            'dismissed_severity' => TypedArray::nullableString($r, 'dismissed_severity'), 'first_detected_at' => TypedArray::string($r, 'first_detected_at'),
            'last_evaluated_at' => TypedArray::string($r, 'last_evaluated_at'), 'resolved_at' => TypedArray::nullableString($r, 'resolved_at'),
        ];
    }

    private function now(): string
    {
        return $this->clock->nowUtc()->format('Y-m-d H:i:s');
    }
}
