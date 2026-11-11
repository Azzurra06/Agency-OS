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
 * Formules récurrentes (données réelles, modifiables) et scénarios de projection (simulations versionnées).
 * Les données figées des scénarios sont chiffrées (D4) ; les écritures sur un scénario exigent status = 'draft'.
 *
 * @phpstan-type OfferRow array{id: int, public_id: string, name: string, kind: string, billing_period: string, price: string, hours_per_month: string, external_costs_per_month: string, active_contracts: int, contracts_updated_at: string|null, is_active: bool, items: list<string>}
 * @phpstan-type ScenarioRow array{id: int, public_id: string, family_id: int, name: string, horizon_months: int, status: string, version_number: int, finalized_at: string|null, archived_at: string|null, calc_version: string|null, created_at: string, updated_at: string}
 * @phpstan-type EntryRow array{offer_id: int|null, offer_public_id: string|null, starting_clients: string, new_clients_per_month: string, monthly_churn_rate: string}
 */
final class RecurringRepository extends OrgScopedRepository
{
    private const SCEN_COLUMNS = 'id, public_id, family_id, name, horizon_months, status, version_number, finalized_at, archived_at, calc_version, created_at, updated_at';

    public function __construct(Connection $db, private readonly FieldCrypto $crypto, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /** @return list<OfferRow> */
    public function offers(OrgId $org, bool $activeOnly = false): array
    {
        $rows = $this->scopedAll(
            $org,
            'SELECT id, public_id, name, kind, billing_period, price, hours_per_month, external_costs_per_month, active_contracts, contracts_updated_at, is_active
               FROM recurring_offers WHERE organization_id = :org' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY position, id',
        );
        $items = [];
        foreach ($this->scopedAll($org, 'SELECT offer_id, label FROM recurring_offer_items WHERE organization_id = :org ORDER BY position, id') as $item) {
            $items[TypedArray::int($item, 'offer_id')][] = TypedArray::string($item, 'label');
        }

        return array_map(static fn (array $r): array => [
            'id' => TypedArray::int($r, 'id'),
            'public_id' => TypedArray::string($r, 'public_id'),
            'name' => TypedArray::string($r, 'name'),
            'kind' => TypedArray::string($r, 'kind'),
            'billing_period' => TypedArray::string($r, 'billing_period'),
            'price' => TypedArray::string($r, 'price'),
            'hours_per_month' => TypedArray::string($r, 'hours_per_month'),
            'external_costs_per_month' => TypedArray::string($r, 'external_costs_per_month'),
            'active_contracts' => TypedArray::int($r, 'active_contracts'),
            'contracts_updated_at' => TypedArray::nullableString($r, 'contracts_updated_at'),
            'is_active' => TypedArray::int($r, 'is_active') === 1,
            'items' => $items[TypedArray::int($r, 'id')] ?? [],
        ], $rows);
    }

    /** @return OfferRow|null */
    public function offer(OrgId $org, string $publicId): ?array
    {
        if (!PublicId::isValid($publicId)) {
            return null;
        }
        foreach ($this->offers($org) as $offer) {
            if ($offer['public_id'] === $publicId) {
                return $offer;
            }
        }

        return null;
    }

    /**
     * @param array{name: string, kind: string, billing_period: string, price: string, hours_per_month: string, external_costs_per_month: string, active_contracts: int, is_active: bool} $data
     * @param list<string> $items
     */
    public function createOffer(OrgId $org, array $data, array $items): string
    {
        return $this->db->transaction(function () use ($org, $data, $items): string {
            $publicId = PublicId::generate();
            $now = $this->now();
            $position = TypedArray::int($this->scopedOne($org, 'SELECT COUNT(*) AS n FROM recurring_offers WHERE organization_id = :org') ?? [], 'n', 0);
            $this->db->run(
                'INSERT INTO recurring_offers (public_id, organization_id, name, kind, billing_period, price, hours_per_month, external_costs_per_month,
                     active_contracts, contracts_updated_at, is_active, position, created_at, updated_at)
                 VALUES (:pid, :org, :name, :kind, :period, :price, :hours, :ext, :contracts, :now, :active, :pos, :now2, :now3)',
                ['pid' => $publicId, 'org' => $org->value, 'name' => $data['name'], 'kind' => $data['kind'], 'period' => $data['billing_period'],
                 'price' => $data['price'], 'hours' => $data['hours_per_month'], 'ext' => $data['external_costs_per_month'],
                 'contracts' => $data['active_contracts'], 'now' => $now, 'active' => (int) $data['is_active'], 'pos' => $position, 'now2' => $now, 'now3' => $now],
            );
            $this->replaceItems($org, $this->db->lastInsertId(), $items);

            return $publicId;
        });
    }

    /**
     * @param array{name: string, kind: string, billing_period: string, price: string, hours_per_month: string, external_costs_per_month: string, is_active: bool} $data
     * @param list<string> $items
     */
    public function updateOffer(OrgId $org, int $offerId, array $data, array $items): void
    {
        $this->db->transaction(function () use ($org, $offerId, $data, $items): void {
            $this->scopedExecute(
                $org,
                'UPDATE recurring_offers SET name = :name, kind = :kind, billing_period = :period, price = :price, hours_per_month = :hours,
                        external_costs_per_month = :ext, is_active = :active, updated_at = :now WHERE id = :id AND organization_id = :org',
                ['name' => $data['name'], 'kind' => $data['kind'], 'period' => $data['billing_period'], 'price' => $data['price'], 'hours' => $data['hours_per_month'],
                 'ext' => $data['external_costs_per_month'], 'active' => (int) $data['is_active'], 'now' => $this->now(), 'id' => $offerId],
            );
            $this->replaceItems($org, $offerId, $items);
        });
    }

    /** Met à jour le nombre de contrats réellement signés (données réelles : pas de cycle de vie). */
    public function setContracts(OrgId $org, int $offerId, int $contracts): void
    {
        $now = $this->now();
        $this->scopedExecute(
            $org,
            'UPDATE recurring_offers SET active_contracts = :n, contracts_updated_at = :now, updated_at = :now2 WHERE id = :id AND organization_id = :org',
            ['n' => $contracts, 'now' => $now, 'now2' => $now, 'id' => $offerId],
        );
    }

    /** Supprime une formule ; les brouillons perdent ses paramètres, les scénarios figés restent lisibles (contexte figé). */
    public function deleteOffer(OrgId $org, int $offerId): bool
    {
        return $this->db->transaction(function () use ($org, $offerId): bool {
            $this->scopedExecute(
                $org,
                "DELETE so FROM recurring_scenario_offers so JOIN recurring_scenarios s ON s.id = so.scenario_id AND s.organization_id = so.organization_id
                  WHERE so.organization_id = :org AND so.offer_id = :id AND s.status = 'draft'",
                ['id' => $offerId],
            );

            return $this->scopedExecute($org, 'DELETE FROM recurring_offers WHERE id = :id AND organization_id = :org', ['id' => $offerId]) > 0;
        });
    }

    /** @return list<ScenarioRow> */
    public function scenarios(OrgId $org): array
    {
        return array_map(self::mapScenario(...), $this->scopedAll($org, 'SELECT ' . self::SCEN_COLUMNS . ' FROM recurring_scenarios WHERE organization_id = :org ORDER BY updated_at DESC, id DESC'));
    }

    /** @return ScenarioRow|null */
    public function scenario(OrgId $org, string $publicId): ?array
    {
        if (!PublicId::isValid($publicId)) {
            return null;
        }
        $row = $this->scopedOne($org, 'SELECT ' . self::SCEN_COLUMNS . ' FROM recurring_scenarios WHERE organization_id = :org AND public_id = :pid', ['pid' => $publicId]);

        return $row === null ? null : self::mapScenario($row);
    }

    /** @return list<ScenarioRow> */
    public function versions(OrgId $org, int $familyId): array
    {
        return array_map(self::mapScenario(...), $this->scopedAll(
            $org,
            'SELECT ' . self::SCEN_COLUMNS . ' FROM recurring_scenarios WHERE organization_id = :org AND family_id = :f ORDER BY version_number DESC',
            ['f' => $familyId],
        ));
    }

    /** @return list<EntryRow> */
    public function entries(OrgId $org, int $scenarioId): array
    {
        return array_map(static function (array $r): array {
            $offerId = TypedArray::nullableString($r, 'offer_id');

            return [
                'offer_id' => $offerId === null ? null : (int) $offerId,
                'offer_public_id' => TypedArray::nullableString($r, 'offer_public_id'),
                'starting_clients' => TypedArray::string($r, 'starting_clients'),
                'new_clients_per_month' => TypedArray::string($r, 'new_clients_per_month'),
                'monthly_churn_rate' => TypedArray::string($r, 'monthly_churn_rate'),
            ];
        }, $this->scopedAll(
            $org,
            'SELECT so.offer_id, o.public_id AS offer_public_id, so.starting_clients, so.new_clients_per_month, so.monthly_churn_rate
               FROM recurring_scenario_offers so LEFT JOIN recurring_offers o ON o.id = so.offer_id AND o.organization_id = so.organization_id
              WHERE so.organization_id = :org AND so.scenario_id = :s ORDER BY so.id',
            ['s' => $scenarioId],
        ));
    }

    /**
     * @param list<array{offer_id: int, starting_clients: string, new_clients_per_month: string, monthly_churn_rate: string}> $entries
     * @return array{id: int, public_id: string}
     */
    public function createScenario(OrgId $org, string $name, int $horizon, array $entries, ?int $familyId = null, int $version = 1, ?int $parentId = null): array
    {
        return $this->db->transaction(function () use ($org, $name, $horizon, $entries, $familyId, $version, $parentId): array {
            $publicId = PublicId::generate();
            $now = $this->now();
            $this->db->run(
                'INSERT INTO recurring_scenarios (public_id, organization_id, family_id, name, horizon_months, status, version_number, parent_scenario_id, created_at, updated_at)
                 VALUES (:pid, :org, :family, :name, :h, \'draft\', :v, :parent, :now, :now2)',
                ['pid' => $publicId, 'org' => $org->value, 'family' => $familyId, 'name' => $name, 'h' => $horizon, 'v' => $version, 'parent' => $parentId, 'now' => $now, 'now2' => $now],
            );
            $id = $this->db->lastInsertId();
            if ($familyId === null) {
                $this->scopedExecute($org, 'UPDATE recurring_scenarios SET family_id = id WHERE id = :id AND organization_id = :org', ['id' => $id]);
            }
            $this->insertEntries($org, $id, $entries);

            return ['id' => $id, 'public_id' => $publicId];
        });
    }

    /**
     * Remplace le nom, l'horizon et les paramètres d'un brouillon. Faux si le scénario n'est plus un brouillon.
     *
     * @param list<array{offer_id: int, starting_clients: string, new_clients_per_month: string, monthly_churn_rate: string}> $entries
     */
    public function updateScenario(OrgId $org, int $scenarioId, string $name, int $horizon, array $entries): bool
    {
        return $this->db->transaction(function () use ($org, $scenarioId, $name, $horizon, $entries): bool {
            $draft = $this->scopedOne($org, "SELECT id FROM recurring_scenarios WHERE id = :id AND organization_id = :org AND status = 'draft' FOR UPDATE", ['id' => $scenarioId]);
            if ($draft === null) {
                return false;
            }
            $this->scopedExecute(
                $org,
                'UPDATE recurring_scenarios SET name = :name, horizon_months = :h, updated_at = :now WHERE id = :id AND organization_id = :org',
                ['name' => $name, 'h' => $horizon, 'now' => $this->now(), 'id' => $scenarioId],
            );
            $this->scopedExecute($org, 'DELETE FROM recurring_scenario_offers WHERE organization_id = :org AND scenario_id = :id', ['id' => $scenarioId]);
            $this->insertEntries($org, $scenarioId, $entries);

            return true;
        });
    }

    public function deleteDraft(OrgId $org, int $scenarioId): bool
    {
        return $this->scopedExecute($org, "DELETE FROM recurring_scenarios WHERE id = :id AND organization_id = :org AND status = 'draft'", ['id' => $scenarioId]) > 0;
    }

    /**
     * Finalisation (une transaction) : contexte et résultats figés et chiffrés ; la version finalisée précédente
     * de la même famille passe en « superseded ».
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $results
     */
    public function finalize(OrgId $org, int $scenarioId, string $publicId, int $familyId, ?int $userId, string $calcVersion, array $context, array $results): bool
    {
        return $this->db->transaction(function () use ($org, $scenarioId, $publicId, $familyId, $userId, $calcVersion, $context, $results): bool {
            $now = $this->now();
            $updated = $this->scopedExecute(
                $org,
                "UPDATE recurring_scenarios SET status = 'finalized', finalized_at = :now, finalized_by = :user, calc_version = :calc,
                        frozen_context_enc = :ctx, frozen_results_enc = :res, updated_at = :now2
                  WHERE id = :id AND organization_id = :org AND status = 'draft'",
                ['now' => $now, 'user' => $userId, 'calc' => $calcVersion, 'now2' => $now, 'id' => $scenarioId,
                 'ctx' => $this->crypto->encrypt(self::json($context), new FieldContext('recurring_scenarios', 'frozen_context', $org->value, $publicId)),
                 'res' => $this->crypto->encrypt(self::json($results), new FieldContext('recurring_scenarios', 'frozen_results', $org->value, $publicId))],
            );
            if ($updated !== 1) {
                return false;
            }
            $this->scopedExecute(
                $org,
                "UPDATE recurring_scenarios SET status = 'superseded', updated_at = :now WHERE organization_id = :org AND family_id = :f AND status = 'finalized' AND id <> :id",
                ['now' => $now, 'f' => $familyId, 'id' => $scenarioId],
            );

            return true;
        });
    }

    public function archive(OrgId $org, int $scenarioId): bool
    {
        return $this->scopedExecute(
            $org,
            "UPDATE recurring_scenarios SET status = 'archived', archived_at = :now, updated_at = :now2 WHERE id = :id AND organization_id = :org AND status = 'finalized'",
            ['now' => $this->now(), 'now2' => $this->now(), 'id' => $scenarioId],
        ) > 0;
    }

    /** @return array{context: array<string, mixed>, results: array<string, mixed>}|null */
    public function frozen(OrgId $org, int $scenarioId, string $publicId): ?array
    {
        $row = $this->scopedOne($org, 'SELECT frozen_context_enc, frozen_results_enc FROM recurring_scenarios WHERE id = :id AND organization_id = :org', ['id' => $scenarioId]);
        $ctx = $row === null ? null : TypedArray::nullableString($row, 'frozen_context_enc');
        $res = $row === null ? null : TypedArray::nullableString($row, 'frozen_results_enc');
        if ($ctx === null || $res === null) {
            return null;
        }

        return [
            'context' => self::decode($this->crypto->decrypt($ctx, new FieldContext('recurring_scenarios', 'frozen_context', $org->value, $publicId))),
            'results' => self::decode($this->crypto->decrypt($res, new FieldContext('recurring_scenarios', 'frozen_results', $org->value, $publicId))),
        ];
    }

    /** Vrai si l'identifiant existe dans une AUTRE organisation (sondage, journalisé). */
    public function existsElsewhere(string $publicId): bool
    {
        return PublicId::isValid($publicId)
            && ($this->db->fetchOne('SELECT id FROM recurring_offers WHERE public_id = :pid', ['pid' => $publicId]) !== null
                || $this->db->fetchOne('SELECT id FROM recurring_scenarios WHERE public_id = :pid', ['pid' => $publicId]) !== null);
    }

    /** @param list<string> $items */
    private function replaceItems(OrgId $org, int $offerId, array $items): void
    {
        $this->scopedExecute($org, 'DELETE FROM recurring_offer_items WHERE organization_id = :org AND offer_id = :id', ['id' => $offerId]);
        foreach ($items as $i => $label) {
            $this->db->run(
                'INSERT INTO recurring_offer_items (offer_id, organization_id, label, position) VALUES (:offer, :org, :label, :pos)',
                ['offer' => $offerId, 'org' => $org->value, 'label' => $label, 'pos' => $i + 1],
            );
        }
    }

    /** @param list<array{offer_id: int, starting_clients: string, new_clients_per_month: string, monthly_churn_rate: string}> $entries */
    private function insertEntries(OrgId $org, int $scenarioId, array $entries): void
    {
        foreach ($entries as $e) {
            $this->db->run(
                'INSERT INTO recurring_scenario_offers (scenario_id, organization_id, offer_id, starting_clients, new_clients_per_month, monthly_churn_rate)
                 VALUES (:s, :org, :offer, :start, :new, :churn)',
                ['s' => $scenarioId, 'org' => $org->value, 'offer' => $e['offer_id'], 'start' => $e['starting_clients'], 'new' => $e['new_clients_per_month'], 'churn' => $e['monthly_churn_rate']],
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return ScenarioRow
     */
    private static function mapScenario(array $row): array
    {
        $family = TypedArray::nullableString($row, 'family_id');

        return [
            'id' => TypedArray::int($row, 'id'),
            'public_id' => TypedArray::string($row, 'public_id'),
            'family_id' => $family === null ? TypedArray::int($row, 'id') : (int) $family,
            'name' => TypedArray::string($row, 'name'),
            'horizon_months' => TypedArray::int($row, 'horizon_months'),
            'status' => TypedArray::string($row, 'status'),
            'version_number' => TypedArray::int($row, 'version_number'),
            'finalized_at' => TypedArray::nullableString($row, 'finalized_at'),
            'archived_at' => TypedArray::nullableString($row, 'archived_at'),
            'calc_version' => TypedArray::nullableString($row, 'calc_version'),
            'created_at' => TypedArray::string($row, 'created_at'),
            'updated_at' => TypedArray::string($row, 'updated_at'),
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
