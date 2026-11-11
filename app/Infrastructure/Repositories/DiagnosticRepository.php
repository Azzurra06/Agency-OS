<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Domain\Diagnostic\DiagnosticResult;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\OrgId;
use App\Infrastructure\Database\OrgScopedRepository;
use App\Support\Clock;
use App\Support\PublicId;
use App\Support\TypedArray;
use PDOException;

/**
 * Diagnostics de maturité. Un brouillon unique par organisation (contrainte en base) ; un diagnostic terminé
 * est figé : aucune méthode ne modifie ses réponses ni ses scores (les écritures exigent status = 'draft').
 *
 * @phpstan-type DiagnosticRow array{id: int, public_id: string, questionnaire_version: string, calc_version: string, status: string, completeness: string|null, completed_at: string|null, created_at: string, updated_at: string}
 * @phpstan-type ScoreRow array{dimension_code: string, score: string|null, is_scorable: bool, applicable_count: int, scored_count: int, unknown_excluded_count: int, unknown_evaluable_count: int, reliability: string}
 */
final class DiagnosticRepository extends OrgScopedRepository
{
    private const COLUMNS = 'id, public_id, questionnaire_version, calc_version, status, completeness, completed_at, created_at, updated_at';

    public function __construct(Connection $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /** @return DiagnosticRow|null */
    public function draft(OrgId $org): ?array
    {
        return self::map($this->scopedOne($org, 'SELECT ' . self::COLUMNS . " FROM diagnostics WHERE organization_id = :org AND status = 'draft'"));
    }

    /** @return DiagnosticRow|null */
    public function find(OrgId $org, string $publicId): ?array
    {
        if (!PublicId::isValid($publicId)) {
            return null;
        }

        return self::map($this->scopedOne($org, 'SELECT ' . self::COLUMNS . ' FROM diagnostics WHERE organization_id = :org AND public_id = :pid', ['pid' => $publicId]));
    }

    /**
     * Diagnostics terminés, du plus récent au plus ancien, avec leur score global figé.
     *
     * @return list<array{public_id: string, questionnaire_version: string, completeness: string, completed_at: string, global: string|null, reliability: string|null}>
     */
    public function history(OrgId $org, int $limit = 20): array
    {
        $rows = $this->scopedAll(
            $org,
            "SELECT d.public_id, d.questionnaire_version, d.completeness, d.completed_at, s.score AS global_score, s.reliability
               FROM diagnostics d
               LEFT JOIN diagnostic_scores s ON s.diagnostic_id = d.id AND s.organization_id = d.organization_id AND s.dimension_code = 'global'
              WHERE d.organization_id = :org AND d.status = 'completed'
              ORDER BY d.completed_at DESC, d.id DESC LIMIT " . max(1, min(100, $limit)),
        );

        return array_map(static fn (array $r): array => [
            'public_id' => TypedArray::string($r, 'public_id'),
            'questionnaire_version' => TypedArray::string($r, 'questionnaire_version'),
            'completeness' => TypedArray::string($r, 'completeness'),
            'completed_at' => TypedArray::string($r, 'completed_at'),
            'global' => TypedArray::nullableString($r, 'global_score'),
            'reliability' => TypedArray::nullableString($r, 'reliability'),
        ], $rows);
    }

    /** @return DiagnosticRow|null le diagnostic terminé précédant celui donné (pour la comparaison) */
    public function previousCompleted(OrgId $org, int $diagnosticId, string $completedAt): ?array
    {
        return self::map($this->scopedOne(
            $org,
            'SELECT ' . self::COLUMNS . " FROM diagnostics
              WHERE organization_id = :org AND status = 'completed' AND (completed_at < :at OR (completed_at = :at2 AND id < :id))
              ORDER BY completed_at DESC, id DESC LIMIT 1",
            ['at' => $completedAt, 'at2' => $completedAt, 'id' => $diagnosticId],
        ));
    }

    /** @return DiagnosticRow|null */
    public function latestCompleted(OrgId $org): ?array
    {
        return self::map($this->scopedOne(
            $org,
            'SELECT ' . self::COLUMNS . " FROM diagnostics WHERE organization_id = :org AND status = 'completed' ORDER BY completed_at DESC, id DESC LIMIT 1",
        ));
    }

    /**
     * Crée le brouillon ; si un brouillon existe déjà (requête concurrente), renvoie null.
     */
    public function createDraft(OrgId $org, ?int $userId, string $version, string $calcVersion): ?string
    {
        $publicId = PublicId::generate();
        $now = $this->now();
        try {
            $this->db->run(
                'INSERT INTO diagnostics (public_id, organization_id, questionnaire_version, calc_version, status, started_by, created_at, updated_at)
                 VALUES (:pid, :org, :version, :calc, \'draft\', :user, :now, :now2)',
                ['pid' => $publicId, 'org' => $org->value, 'version' => $version, 'calc' => $calcVersion, 'user' => $userId, 'now' => $now, 'now2' => $now],
            );
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return null; // contrainte « un brouillon par organisation »
            }
            throw $e;
        }

        return $publicId;
    }

    /**
     * @return array<string, array{option: string, prefilled: bool}>
     */
    public function answers(OrgId $org, int $diagnosticId): array
    {
        $rows = $this->scopedAll(
            $org,
            'SELECT question_code, option_code, prefilled_from_profile FROM diagnostic_answers WHERE organization_id = :org AND diagnostic_id = :d',
            ['d' => $diagnosticId],
        );
        $answers = [];
        foreach ($rows as $row) {
            $answers[TypedArray::string($row, 'question_code')] = [
                'option' => TypedArray::string($row, 'option_code'),
                'prefilled' => TypedArray::int($row, 'prefilled_from_profile') === 1,
            ];
        }

        return $answers;
    }

    /**
     * Enregistre (ou remplace) une réponse, uniquement si le diagnostic est un brouillon de l'organisation.
     */
    public function saveAnswer(OrgId $org, int $diagnosticId, string $question, string $option, bool $prefilled): bool
    {
        $now = $this->now();
        $this->scopedExecute(
            $org,
            "INSERT INTO diagnostic_answers (diagnostic_id, organization_id, question_code, option_code, prefilled_from_profile, answered_at)
             SELECT id, organization_id, :q, :opt, :pre, :now FROM diagnostics WHERE id = :d AND organization_id = :org AND status = 'draft'
             ON DUPLICATE KEY UPDATE option_code = VALUES(option_code), prefilled_from_profile = VALUES(prefilled_from_profile), answered_at = VALUES(answered_at)",
            ['q' => $question, 'opt' => $option, 'pre' => (int) $prefilled, 'now' => $now, 'd' => $diagnosticId],
        );

        return $this->scopedExecute(
            $org,
            "UPDATE diagnostics SET updated_at = :now WHERE id = :d AND organization_id = :org AND status = 'draft'",
            ['now' => $now, 'd' => $diagnosticId],
        ) > 0;
    }

    /** Supprime un brouillon (et ses réponses, par cascade). Un diagnostic terminé n'est jamais supprimé ici. */
    public function discardDraft(OrgId $org, int $diagnosticId): bool
    {
        return $this->scopedExecute($org, "DELETE FROM diagnostics WHERE id = :d AND organization_id = :org AND status = 'draft'", ['d' => $diagnosticId]) > 0;
    }

    /**
     * Clôture : passe le brouillon à « completed » et écrit les scores figés (2 décimales), dans une transaction.
     * Renvoie false si le diagnostic n'était plus un brouillon (double clic, autre onglet).
     */
    public function complete(OrgId $org, int $diagnosticId, DiagnosticResult $result, string $calcVersion): bool
    {
        return $this->db->transaction(function () use ($org, $diagnosticId, $result, $calcVersion): bool {
            $updated = $this->scopedExecute(
                $org,
                "UPDATE diagnostics SET status = 'completed', completeness = :c, calc_version = :calc, completed_at = :now, updated_at = :now2
                  WHERE id = :d AND organization_id = :org AND status = 'draft'",
                ['c' => $result->completeness(), 'calc' => $calcVersion, 'now' => $this->now(), 'now2' => $this->now(), 'd' => $diagnosticId],
            );
            if ($updated !== 1) {
                return false;
            }
            $sql = 'INSERT INTO diagnostic_scores (diagnostic_id, organization_id, dimension_code, score, is_scorable, applicable_count, scored_count,
                        unknown_excluded_count, unknown_evaluable_count, reliability)
                    VALUES (:d, :org, :code, :score, :scorable, :a, :n, :ue, :up, :f)';
            $totals = ['a' => 0, 'n' => 0, 'ue' => 0, 'up' => 0];
            foreach ($result->dimensions as $code => $d) {
                $this->db->run($sql, [
                    'd' => $diagnosticId, 'org' => $org->value, 'code' => $code, 'score' => $d->score?->toFixed(2), 'scorable' => (int) $d->scorable,
                    'a' => $d->applicable, 'n' => $d->scored, 'ue' => $d->unknownExcluded, 'up' => $d->unknownEvaluable, 'f' => $d->reliability->toFixed(4),
                ]);
                $totals['a'] += $d->applicable;
                $totals['n'] += $d->scored;
                $totals['ue'] += $d->unknownExcluded;
                $totals['up'] += $d->unknownEvaluable;
            }
            $this->db->run($sql, [
                'd' => $diagnosticId, 'org' => $org->value, 'code' => 'global', 'score' => $result->global?->toFixed(2), 'scorable' => (int) ($result->global !== null),
                'a' => $totals['a'], 'n' => $totals['n'], 'ue' => $totals['ue'], 'up' => $totals['up'], 'f' => $result->globalReliability->toFixed(4),
            ]);

            return true;
        });
    }

    /**
     * Scores figés d'un diagnostic terminé, indexés par dimension (« global » compris).
     *
     * @return array<string, ScoreRow>
     */
    public function scores(OrgId $org, int $diagnosticId): array
    {
        $rows = $this->scopedAll(
            $org,
            'SELECT dimension_code, score, is_scorable, applicable_count, scored_count, unknown_excluded_count, unknown_evaluable_count, reliability
               FROM diagnostic_scores WHERE organization_id = :org AND diagnostic_id = :d',
            ['d' => $diagnosticId],
        );
        $scores = [];
        foreach ($rows as $r) {
            $code = TypedArray::string($r, 'dimension_code');
            $scores[$code] = [
                'dimension_code' => $code,
                'score' => TypedArray::nullableString($r, 'score'),
                'is_scorable' => TypedArray::int($r, 'is_scorable') === 1,
                'applicable_count' => TypedArray::int($r, 'applicable_count'),
                'scored_count' => TypedArray::int($r, 'scored_count'),
                'unknown_excluded_count' => TypedArray::int($r, 'unknown_excluded_count'),
                'unknown_evaluable_count' => TypedArray::int($r, 'unknown_evaluable_count'),
                'reliability' => TypedArray::string($r, 'reliability'),
            ];
        }

        return $scores;
    }

    /** Vrai si le diagnostic existe dans une AUTRE organisation (sondage d'identifiant, journalisé). */
    public function existsElsewhere(string $publicId): bool
    {
        return PublicId::isValid($publicId) && $this->db->fetchOne('SELECT id FROM diagnostics WHERE public_id = :pid', ['pid' => $publicId]) !== null;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return DiagnosticRow|null
     */
    private static function map(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'id' => TypedArray::int($row, 'id'),
            'public_id' => TypedArray::string($row, 'public_id'),
            'questionnaire_version' => TypedArray::string($row, 'questionnaire_version'),
            'calc_version' => TypedArray::string($row, 'calc_version'),
            'status' => TypedArray::string($row, 'status'),
            'completeness' => TypedArray::nullableString($row, 'completeness'),
            'completed_at' => TypedArray::nullableString($row, 'completed_at'),
            'created_at' => TypedArray::string($row, 'created_at'),
            'updated_at' => TypedArray::string($row, 'updated_at'),
        ];
    }

    private function now(): string
    {
        return $this->clock->nowUtc()->format('Y-m-d H:i:s');
    }
}
