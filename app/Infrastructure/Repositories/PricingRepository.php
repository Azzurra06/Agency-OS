<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\OrgId;
use App\Infrastructure\Database\OrgScopedRepository;
use App\Security\Crypto\FieldContext;
use App\Security\Crypto\FieldCrypto;
use App\Support\Clock;
use App\Support\PublicId;
use App\Support\TypedArray;
use JsonException;

/**
 * Simulations de prix, scénarios et lignes (docs/02 §5.3, docs/05 §3.1).
 *
 * - Toute écriture sur un scénario ou une ligne exige que la simulation soit un brouillon (contrôle dans la requête SQL).
 * - Les données figées (frozen_context, frozen_results) sont CHIFFRÉES (D4), avec une AAD liée à la ligne et à l'organisation.
 *
 * @phpstan-type SimulationRow array{id: int, public_id: string, service_id: int|null, family_id: int, name: string, status: string, version_number: int, parent_simulation_id: int|null, reference_scenario_id: int|null, finalized_at: string|null, archived_at: string|null, calc_version: string|null, created_at: string, updated_at: string, service_public_id: string|null, service_name: string|null, service_category: string|null, service_price: string|null, service_volume: int|null}
 * @phpstan-type LineRow array{kind: string, label: string, hours: string|null, amount: string|null}
 * @phpstan-type ScenarioRow array{id: int, public_id: string, label: string, hourly_cost_mode: string, hourly_cost_custom: string|null, contingency_rate: string, target_margin_rate: string, proposed_price: string|null, position: int, lines: list<LineRow>}
 */
final class PricingRepository extends OrgScopedRepository
{
    private const SIM_COLUMNS = 's.id, s.public_id, s.service_id, s.family_id, s.name, s.status, s.version_number, s.parent_simulation_id, s.reference_scenario_id,
        s.finalized_at, s.archived_at, s.calc_version, s.created_at, s.updated_at,
        v.public_id AS service_public_id, v.name AS service_name, v.category AS service_category, v.current_price AS service_price, v.annual_volume AS service_volume';
    private const SIM_FROM = 'pricing_simulations s LEFT JOIN services v ON v.id = s.service_id AND v.organization_id = s.organization_id';

    public function __construct(Connection $db, private readonly FieldCrypto $crypto, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /** @return list<SimulationRow> */
    public function simulations(OrgId $org): array
    {
        $rows = $this->scopedAll($org, 'SELECT ' . self::SIM_COLUMNS . ' FROM ' . self::SIM_FROM . ' WHERE s.organization_id = :org ORDER BY s.updated_at DESC, s.id DESC');

        return array_map(self::mapSimulation(...), $rows);
    }

    /** @return SimulationRow|null */
    public function find(OrgId $org, string $publicId): ?array
    {
        if (!PublicId::isValid($publicId)) {
            return null;
        }
        $row = $this->scopedOne($org, 'SELECT ' . self::SIM_COLUMNS . ' FROM ' . self::SIM_FROM . ' WHERE s.organization_id = :org AND s.public_id = :pid', ['pid' => $publicId]);

        return $row === null ? null : self::mapSimulation($row);
    }

    /** @return SimulationRow|null */
    public function findById(OrgId $org, int $id): ?array
    {
        $row = $this->scopedOne($org, 'SELECT ' . self::SIM_COLUMNS . ' FROM ' . self::SIM_FROM . ' WHERE s.organization_id = :org AND s.id = :id', ['id' => $id]);

        return $row === null ? null : self::mapSimulation($row);
    }

    /** @return list<SimulationRow> versions d'une même famille, de la plus récente à la plus ancienne */
    public function versions(OrgId $org, int $familyId): array
    {
        $rows = $this->scopedAll($org, 'SELECT ' . self::SIM_COLUMNS . ' FROM ' . self::SIM_FROM . ' WHERE s.organization_id = :org AND s.family_id = :f ORDER BY s.version_number DESC', ['f' => $familyId]);

        return array_map(self::mapSimulation(...), $rows);
    }

    /** Identifiant interne d'une prestation de l'organisation (null si absente ou d'une autre organisation). */
    public function serviceId(OrgId $org, string $servicePublicId): ?int
    {
        $row = $this->scopedOne($org, 'SELECT id FROM services WHERE organization_id = :org AND public_id = :pid', ['pid' => $servicePublicId]);

        return $row === null ? null : TypedArray::int($row, 'id');
    }

    /**
     * @return array{id: int, public_id: string}
     */
    public function createSimulation(OrgId $org, ?int $serviceId, string $name, ?int $familyId = null, int $version = 1, ?int $parentId = null): array
    {
        $publicId = PublicId::generate();
        $now = $this->now();
        $this->db->run(
            'INSERT INTO pricing_simulations (public_id, organization_id, service_id, family_id, name, status, version_number, parent_simulation_id, created_at, updated_at)
             VALUES (:pid, :org, :service, :family, :name, \'draft\', :version, :parent, :now, :now2)',
            ['pid' => $publicId, 'org' => $org->value, 'service' => $serviceId, 'family' => $familyId, 'name' => $name, 'version' => $version,
             'parent' => $parentId, 'now' => $now, 'now2' => $now],
        );
        $id = $this->db->lastInsertId();
        if ($familyId === null) {
            $this->scopedExecute($org, 'UPDATE pricing_simulations SET family_id = id WHERE id = :id AND organization_id = :org', ['id' => $id]);
        }

        return ['id' => $id, 'public_id' => $publicId];
    }

    public function rename(OrgId $org, int $simulationId, string $name): bool
    {
        return $this->scopedExecute(
            $org,
            "UPDATE pricing_simulations SET name = :name, updated_at = :now WHERE id = :id AND organization_id = :org AND status = 'draft'",
            ['name' => $name, 'now' => $this->now(), 'id' => $simulationId],
        ) > 0;
    }

    public function setReference(OrgId $org, int $simulationId, int $scenarioId): bool
    {
        return $this->scopedExecute(
            $org,
            "UPDATE pricing_simulations SET reference_scenario_id = :scen, updated_at = :now
              WHERE id = :id AND organization_id = :org AND status = 'draft'
                AND EXISTS (SELECT 1 FROM pricing_scenarios c WHERE c.id = :scen2 AND c.simulation_id = :id2 AND c.organization_id = :org2)",
            ['scen' => $scenarioId, 'now' => $this->now(), 'id' => $simulationId, 'scen2' => $scenarioId, 'id2' => $simulationId, 'org2' => $org->value],
        ) > 0;
    }

    public function deleteDraft(OrgId $org, int $simulationId): bool
    {
        return $this->scopedExecute($org, "DELETE FROM pricing_simulations WHERE id = :id AND organization_id = :org AND status = 'draft'", ['id' => $simulationId]) > 0;
    }

    /** @return list<ScenarioRow> */
    public function scenarios(OrgId $org, int $simulationId): array
    {
        $rows = $this->scopedAll(
            $org,
            'SELECT id, public_id, label, hourly_cost_mode, hourly_cost_custom, contingency_rate, target_margin_rate, proposed_price, position
               FROM pricing_scenarios WHERE organization_id = :org AND simulation_id = :sim ORDER BY position, id',
            ['sim' => $simulationId],
        );
        $lines = [];
        foreach ($this->scopedAll(
            $org,
            'SELECT l.scenario_id, l.kind, l.label, l.hours, l.amount FROM pricing_scenario_lines l
               JOIN pricing_scenarios c ON c.id = l.scenario_id AND c.organization_id = l.organization_id
              WHERE l.organization_id = :org AND c.simulation_id = :sim ORDER BY l.position, l.id',
            ['sim' => $simulationId],
        ) as $l) {
            $lines[TypedArray::int($l, 'scenario_id')][] = [
                'kind' => TypedArray::string($l, 'kind'),
                'label' => TypedArray::string($l, 'label'),
                'hours' => TypedArray::nullableString($l, 'hours'),
                'amount' => TypedArray::nullableString($l, 'amount'),
            ];
        }

        return array_map(static function (array $r) use ($lines): array {
            $id = TypedArray::int($r, 'id');

            return [
                'id' => $id,
                'public_id' => TypedArray::string($r, 'public_id'),
                'label' => TypedArray::string($r, 'label'),
                'hourly_cost_mode' => TypedArray::string($r, 'hourly_cost_mode'),
                'hourly_cost_custom' => TypedArray::nullableString($r, 'hourly_cost_custom'),
                'contingency_rate' => TypedArray::string($r, 'contingency_rate'),
                'target_margin_rate' => TypedArray::string($r, 'target_margin_rate'),
                'proposed_price' => TypedArray::nullableString($r, 'proposed_price'),
                'position' => TypedArray::int($r, 'position'),
                'lines' => $lines[$id] ?? [],
            ];
        }, $rows);
    }

    /**
     * Crée un scénario dans une simulation brouillon.
     *
     * @param array{label: string, hourly_cost_mode: string, hourly_cost_custom: string|null, contingency_rate: string, target_margin_rate: string, proposed_price: string|null} $data
     * @param list<LineRow> $lines
     * @return array{id: int, public_id: string}|null null si la simulation n'est plus un brouillon
     */
    public function createScenario(OrgId $org, int $simulationId, array $data, array $lines): ?array
    {
        return $this->db->transaction(function () use ($org, $simulationId, $data, $lines): ?array {
            $sim = $this->scopedOne($org, "SELECT id FROM pricing_simulations WHERE id = :id AND organization_id = :org AND status = 'draft' FOR UPDATE", ['id' => $simulationId]);
            if ($sim === null) {
                return null;
            }
            $position = TypedArray::int($this->scopedOne($org, 'SELECT COALESCE(MAX(position), 0) + 1 AS p FROM pricing_scenarios WHERE organization_id = :org AND simulation_id = :sim', ['sim' => $simulationId]) ?? [], 'p', 1);
            $publicId = PublicId::generate();
            $now = $this->now();
            $this->db->run(
                'INSERT INTO pricing_scenarios (public_id, simulation_id, organization_id, label, hourly_cost_mode, hourly_cost_custom, contingency_rate, target_margin_rate, proposed_price, position, created_at, updated_at)
                 VALUES (:pid, :sim, :org, :label, :mode, :custom, :cont, :margin, :price, :pos, :now, :now2)',
                ['pid' => $publicId, 'sim' => $simulationId, 'org' => $org->value, 'label' => $data['label'], 'mode' => $data['hourly_cost_mode'],
                 'custom' => $data['hourly_cost_custom'], 'cont' => $data['contingency_rate'], 'margin' => $data['target_margin_rate'],
                 'price' => $data['proposed_price'], 'pos' => $position, 'now' => $now, 'now2' => $now],
            );
            $id = $this->db->lastInsertId();
            $this->insertLines($org, $id, $lines);
            $this->touch($org, $simulationId);

            return ['id' => $id, 'public_id' => $publicId];
        });
    }

    /**
     * Remplace un scénario (paramètres et lignes) — uniquement si sa simulation est un brouillon.
     *
     * @param array{label: string, hourly_cost_mode: string, hourly_cost_custom: string|null, contingency_rate: string, target_margin_rate: string, proposed_price: string|null} $data
     * @param list<LineRow> $lines
     */
    public function updateScenario(OrgId $org, int $scenarioId, array $data, array $lines): bool
    {
        return $this->db->transaction(function () use ($org, $scenarioId, $data, $lines): bool {
            $row = $this->scopedOne(
                $org,
                "SELECT c.simulation_id FROM pricing_scenarios c JOIN pricing_simulations s ON s.id = c.simulation_id AND s.organization_id = c.organization_id
                  WHERE c.organization_id = :org AND c.id = :id AND s.status = 'draft' FOR UPDATE",
                ['id' => $scenarioId],
            );
            if ($row === null) {
                return false;
            }
            $this->scopedExecute(
                $org,
                'UPDATE pricing_scenarios SET label = :label, hourly_cost_mode = :mode, hourly_cost_custom = :custom, contingency_rate = :cont,
                        target_margin_rate = :margin, proposed_price = :price, updated_at = :now WHERE id = :id AND organization_id = :org',
                ['label' => $data['label'], 'mode' => $data['hourly_cost_mode'], 'custom' => $data['hourly_cost_custom'], 'cont' => $data['contingency_rate'],
                 'margin' => $data['target_margin_rate'], 'price' => $data['proposed_price'], 'now' => $this->now(), 'id' => $scenarioId],
            );
            $this->scopedExecute($org, 'DELETE FROM pricing_scenario_lines WHERE organization_id = :org AND scenario_id = :id', ['id' => $scenarioId]);
            $this->insertLines($org, $scenarioId, $lines);
            $this->touch($org, TypedArray::int($row, 'simulation_id'));

            return true;
        });
    }

    public function deleteScenario(OrgId $org, int $scenarioId): bool
    {
        return $this->db->transaction(function () use ($org, $scenarioId): bool {
            $row = $this->scopedOne(
                $org,
                "SELECT c.simulation_id FROM pricing_scenarios c JOIN pricing_simulations s ON s.id = c.simulation_id AND s.organization_id = c.organization_id
                  WHERE c.organization_id = :org AND c.id = :id AND s.status = 'draft' FOR UPDATE",
                ['id' => $scenarioId],
            );
            if ($row === null) {
                return false;
            }
            $simulationId = TypedArray::int($row, 'simulation_id');
            $this->scopedExecute($org, 'UPDATE pricing_simulations SET reference_scenario_id = NULL WHERE id = :id AND organization_id = :org AND reference_scenario_id = :scen', ['id' => $simulationId, 'scen' => $scenarioId]);
            $this->scopedExecute($org, 'DELETE FROM pricing_scenarios WHERE id = :id AND organization_id = :org', ['id' => $scenarioId]);
            $this->touch($org, $simulationId);

            return true;
        });
    }

    /**
     * Finalisation : une seule transaction. Gèle le contexte et les résultats (chiffrés), passe le brouillon en
     * « finalized », et la simulation précédemment active de la même prestation (ou de la même famille) en « superseded ».
     *
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $resultsByScenario
     * @param array<int, string> $scenarioPublicIds
     */
    public function finalize(OrgId $org, int $simulationId, string $simulationPublicId, ?int $userId, string $calcVersion, array $context, array $resultsByScenario, array $scenarioPublicIds): bool
    {
        return $this->db->transaction(function () use ($org, $simulationId, $simulationPublicId, $userId, $calcVersion, $context, $resultsByScenario, $scenarioPublicIds): bool {
            $sim = $this->scopedOne($org, "SELECT service_id, family_id FROM pricing_simulations WHERE id = :id AND organization_id = :org AND status = 'draft' FOR UPDATE", ['id' => $simulationId]);
            if ($sim === null) {
                return false;
            }
            $serviceId = TypedArray::nullableString($sim, 'service_id');
            $now = $this->now();
            $this->scopedExecute(
                $org,
                "UPDATE pricing_simulations SET status = 'superseded', superseded_by = :by, updated_at = :now
                  WHERE organization_id = :org AND status = 'finalized' AND id <> :id AND (family_id = :family" . ($serviceId !== null ? ' OR service_id = :service' : '') . ')',
                ['by' => $simulationId, 'now' => $now, 'id' => $simulationId, 'family' => TypedArray::int($sim, 'family_id')] + ($serviceId !== null ? ['service' => (int) $serviceId] : []),
            );
            $this->scopedExecute(
                $org,
                "UPDATE pricing_simulations SET status = 'finalized', finalized_at = :now, finalized_by = :user, calc_version = :calc, frozen_context_enc = :ctx, updated_at = :now2
                  WHERE id = :id AND organization_id = :org AND status = 'draft'",
                ['now' => $now, 'user' => $userId, 'calc' => $calcVersion, 'now2' => $now, 'id' => $simulationId,
                 'ctx' => $this->crypto->encrypt(self::json($context), new FieldContext('pricing_simulations', 'frozen_context', $org->value, $simulationPublicId))],
            );
            foreach ($resultsByScenario as $scenarioId => $results) {
                $this->scopedExecute(
                    $org,
                    'UPDATE pricing_scenarios SET frozen_results_enc = :res WHERE id = :id AND organization_id = :org',
                    ['id' => $scenarioId, 'res' => $this->crypto->encrypt(self::json($results), new FieldContext('pricing_scenarios', 'frozen_results', $org->value, $scenarioPublicIds[$scenarioId] ?? ''))],
                );
            }

            return true;
        });
    }

    public function archive(OrgId $org, int $simulationId): bool
    {
        return $this->scopedExecute(
            $org,
            "UPDATE pricing_simulations SET status = 'archived', archived_at = :now, updated_at = :now2 WHERE id = :id AND organization_id = :org AND status = 'finalized'",
            ['now' => $this->now(), 'now2' => $this->now(), 'id' => $simulationId],
        ) > 0;
    }

    /** @return array<string, mixed>|null contexte figé déchiffré */
    public function frozenContext(OrgId $org, int $simulationId, string $publicId): ?array
    {
        $row = $this->scopedOne($org, 'SELECT frozen_context_enc FROM pricing_simulations WHERE id = :id AND organization_id = :org', ['id' => $simulationId]);
        $stored = $row === null ? null : TypedArray::nullableString($row, 'frozen_context_enc');

        return $stored === null ? null : self::decode($this->crypto->decrypt($stored, new FieldContext('pricing_simulations', 'frozen_context', $org->value, $publicId)));
    }

    /** @return array<string, mixed>|null résultats figés déchiffrés d'un scénario */
    public function frozenResults(OrgId $org, int $scenarioId, string $publicId): ?array
    {
        $row = $this->scopedOne($org, 'SELECT frozen_results_enc FROM pricing_scenarios WHERE id = :id AND organization_id = :org', ['id' => $scenarioId]);
        $stored = $row === null ? null : TypedArray::nullableString($row, 'frozen_results_enc');

        return $stored === null ? null : self::decode($this->crypto->decrypt($stored, new FieldContext('pricing_scenarios', 'frozen_results', $org->value, $publicId)));
    }

    /** @return array{id: int, simulation_id: int, public_id: string}|null */
    public function findScenario(OrgId $org, string $publicId): ?array
    {
        if (!PublicId::isValid($publicId)) {
            return null;
        }
        $row = $this->scopedOne($org, 'SELECT id, simulation_id, public_id FROM pricing_scenarios WHERE organization_id = :org AND public_id = :pid', ['pid' => $publicId]);

        return $row === null ? null : ['id' => TypedArray::int($row, 'id'), 'simulation_id' => TypedArray::int($row, 'simulation_id'), 'public_id' => TypedArray::string($row, 'public_id')];
    }

    /** Vrai si l'identifiant existe dans une AUTRE organisation (sondage, journalisé). */
    public function existsElsewhere(string $publicId): bool
    {
        return PublicId::isValid($publicId)
            && ($this->db->fetchOne('SELECT id FROM pricing_simulations WHERE public_id = :pid', ['pid' => $publicId]) !== null
                || $this->db->fetchOne('SELECT id FROM pricing_scenarios WHERE public_id = :pid', ['pid' => $publicId]) !== null);
    }

    /** @param list<LineRow> $lines */
    private function insertLines(OrgId $org, int $scenarioId, array $lines): void
    {
        foreach ($lines as $i => $line) {
            $this->db->run(
                'INSERT INTO pricing_scenario_lines (scenario_id, organization_id, kind, label, hours, amount, position) VALUES (:scen, :org, :kind, :label, :hours, :amount, :pos)',
                ['scen' => $scenarioId, 'org' => $org->value, 'kind' => $line['kind'], 'label' => $line['label'], 'hours' => $line['hours'], 'amount' => $line['amount'], 'pos' => $i + 1],
            );
        }
    }

    private function touch(OrgId $org, int $simulationId): void
    {
        $this->scopedExecute($org, 'UPDATE pricing_simulations SET updated_at = :now WHERE id = :id AND organization_id = :org', ['now' => $this->now(), 'id' => $simulationId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return SimulationRow
     */
    private static function mapSimulation(array $row): array
    {
        $int = static fn (string $k): ?int => ($v = TypedArray::nullableString($row, $k)) === null ? null : (int) $v;

        return [
            'id' => TypedArray::int($row, 'id'),
            'public_id' => TypedArray::string($row, 'public_id'),
            'service_id' => $int('service_id'),
            'family_id' => $int('family_id') ?? TypedArray::int($row, 'id'),
            'name' => TypedArray::string($row, 'name'),
            'status' => TypedArray::string($row, 'status'),
            'version_number' => TypedArray::int($row, 'version_number'),
            'parent_simulation_id' => $int('parent_simulation_id'),
            'reference_scenario_id' => $int('reference_scenario_id'),
            'finalized_at' => TypedArray::nullableString($row, 'finalized_at'),
            'archived_at' => TypedArray::nullableString($row, 'archived_at'),
            'calc_version' => TypedArray::nullableString($row, 'calc_version'),
            'created_at' => TypedArray::string($row, 'created_at'),
            'updated_at' => TypedArray::string($row, 'updated_at'),
            'service_public_id' => TypedArray::nullableString($row, 'service_public_id'),
            'service_name' => TypedArray::nullableString($row, 'service_name'),
            'service_category' => TypedArray::nullableString($row, 'service_category'),
            'service_price' => TypedArray::nullableString($row, 'service_price'),
            'service_volume' => $int('service_volume'),
        ];
    }

    /** @param array<array-key, mixed> $data */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }

        /** @var array<string, mixed> */
        return is_array($data) ? $data : [];
    }

    private function now(): string
    {
        return $this->clock->nowUtc()->format('Y-m-d H:i:s');
    }
}
