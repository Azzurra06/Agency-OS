<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\OrgId;
use App\Infrastructure\Database\OrgScopedRepository;
use App\Security\Crypto\FieldContext;
use App\Security\Crypto\FieldCrypto;
use App\Support\Decimal;
use App\Support\TypedArray;
use JsonException;
use PDOException;

/**
 * Instantanés quotidiens (insertion seule, jamais d'écrasement). Valeurs financières et contexte chiffrés (D4) ;
 * l'AAD lie chaque valeur à l'organisation et à la date.
 *
 * @phpstan-type Snapshot array{date: string, captured_at: string, maturity_score: string|null, maturity_reliability: string|null, revenue_annualized: string|null, total_costs_annualized: string|null, estimated_result: string|null, mrr_contracted: string|null, recurring_share_declared: string|null, hourly_cost: string|null, action_progress: string|null}
 */
final class SnapshotRepository extends OrgScopedRepository
{
    public const ENCRYPTED = ['revenue_annualized', 'total_costs_annualized', 'estimated_result', 'mrr_contracted', 'recurring_share_declared', 'hourly_cost'];

    public function __construct(Connection $db, private readonly FieldCrypto $crypto)
    {
        parent::__construct($db);
    }

    /**
     * Insère l'instantané du jour. Un doublon (contrainte UNIQUE) est un succès sans écrasement : renvoie false.
     *
     * @param array<string, Decimal|null> $values clés de ENCRYPTED + maturity_score, maturity_reliability, action_progress
     * @param array<string, mixed> $context
     */
    public function insert(OrgId $org, string $orgPublicId, string $date, string $capturedAt, array $values, array $context, string $calcVersion): bool
    {
        $params = ['org' => $org->value, 'date' => $date, 'captured' => $capturedAt, 'calc' => $calcVersion,
            'maturity' => ($values['maturity_score'] ?? null)?->toFixed(2), 'reliability' => ($values['maturity_reliability'] ?? null)?->toFixed(4),
            'progress' => ($values['action_progress'] ?? null)?->toFixed(2),
            'ctx' => $this->crypto->encrypt(json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), new FieldContext('kpi_snapshots', 'context', $org->value, $orgPublicId . '|' . $date))];
        foreach (self::ENCRYPTED as $column) {
            $params[$column] = $this->crypto->encryptDecimal(($values[$column] ?? null)?->round(2), new FieldContext('kpi_snapshots', $column, $org->value, $orgPublicId . '|' . $date));
        }
        try {
            $this->db->run(
                'INSERT INTO kpi_snapshots (organization_id, snapshot_date, captured_at, maturity_score, maturity_reliability, revenue_annualized_enc, total_costs_annualized_enc,
                     estimated_result_enc, mrr_contracted_enc, recurring_share_declared_enc, hourly_cost_enc, action_progress, calc_version, context_enc)
                 VALUES (:org, :date, :captured, :maturity, :reliability, :revenue_annualized, :total_costs_annualized, :estimated_result, :mrr_contracted,
                     :recurring_share_declared, :hourly_cost, :progress, :calc, :ctx)',
                $params,
            );
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    /** @return list<Snapshot> instantanés entre deux dates incluses */
    public function between(OrgId $org, string $orgPublicId, string $from, string $to): array
    {
        $rows = $this->scopedAll(
            $org,
            'SELECT snapshot_date, captured_at, maturity_score, maturity_reliability, revenue_annualized_enc, total_costs_annualized_enc, estimated_result_enc,
                    mrr_contracted_enc, recurring_share_declared_enc, hourly_cost_enc, action_progress
               FROM kpi_snapshots WHERE organization_id = :org AND snapshot_date BETWEEN :from AND :to ORDER BY snapshot_date',
            ['from' => $from, 'to' => $to],
        );

        return array_map(function (array $r) use ($org, $orgPublicId): array {
            $date = TypedArray::string($r, 'snapshot_date');
            $out = [
                'date' => $date, 'captured_at' => TypedArray::string($r, 'captured_at'),
                'maturity_score' => TypedArray::nullableString($r, 'maturity_score'), 'maturity_reliability' => TypedArray::nullableString($r, 'maturity_reliability'),
                'action_progress' => TypedArray::nullableString($r, 'action_progress'),
                'revenue_annualized' => null, 'total_costs_annualized' => null, 'estimated_result' => null, 'mrr_contracted' => null, 'recurring_share_declared' => null, 'hourly_cost' => null,
            ];
            foreach (self::ENCRYPTED as $column) {
                $value = $this->crypto->decryptDecimal(TypedArray::nullableString($r, $column . '_enc'), new FieldContext('kpi_snapshots', $column, $org->value, $orgPublicId . '|' . $date));
                $out[$column] = $value?->toFixed(2);
            }

            return $out;
        }, $rows);
    }

    /** @return array<string, mixed>|null contexte déchiffré d'un instantané (export) */
    public function context(OrgId $org, string $orgPublicId, string $date): ?array
    {
        $row = $this->scopedOne($org, 'SELECT context_enc FROM kpi_snapshots WHERE organization_id = :org AND snapshot_date = :d', ['d' => $date]);
        $stored = $row === null ? null : TypedArray::nullableString($row, 'context_enc');
        if ($stored === null) {
            return null;
        }
        try {
            $data = json_decode($this->crypto->decrypt($stored, new FieldContext('kpi_snapshots', 'context', $org->value, $orgPublicId . '|' . $date)), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        /** @var array<string, mixed>|null */
        return is_array($data) ? $data : null;
    }

    /**
     * Organisations réelles (hors démo) actives sans instantané pour la date donnée.
     *
     * @return list<array{id: int, public_id: string}>
     */
    public function organizationsWithoutSnapshot(string $date, int $limit): array
    {
        return array_map(static fn (array $r): array => ['id' => TypedArray::int($r, 'id'), 'public_id' => TypedArray::string($r, 'public_id')], $this->db->fetchAll(
            "SELECT o.id, o.public_id FROM organizations o
              WHERE o.is_demo = 0 AND o.status = 'active'
                AND NOT EXISTS (SELECT 1 FROM kpi_snapshots k WHERE k.organization_id = o.id AND k.snapshot_date = :d)
              ORDER BY o.id LIMIT " . max(1, min(500, $limit)),
            ['d' => $date],
        ));
    }
}
