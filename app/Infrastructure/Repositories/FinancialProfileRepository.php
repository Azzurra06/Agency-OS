<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Domain\Finance\FinancialProfileData;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\OrgId;
use App\Infrastructure\Database\OrgScopedRepository;
use App\Security\Crypto\FieldContext;
use App\Security\Crypto\FieldCrypto;
use App\Support\Clock;
use App\Support\Decimal;
use App\Support\PublicId;
use App\Support\TypedArray;

/**
 * Profil financier (un seul profil actif par organisation). Les montants sensibles sont chiffrés
 * ici et UNIQUEMENT ici (docs/04-securite.md §7.5) : les services manipulent des valeurs en clair.
 */
final class FinancialProfileRepository extends OrgScopedRepository
{
    /** Colonne chiffrée => propriété de FinancialProfileData. */
    private const ENCRYPTED = [
        'revenue' => 'revenue',
        'fixed_costs' => 'fixedCosts',
        'personnel_costs' => 'personnelCosts',
        'variable_costs_attributable' => 'variableCostsAttributable',
        'variable_costs_general' => 'variableCostsGeneral',
        'owner_comp_total_cost' => 'ownerCompTotalCost',
        'owner_comp_included_amount' => 'ownerCompIncludedAmount',
        'hourly_cost_override' => 'hourlyCostOverride',
        'revenue_target' => 'revenueTarget',
        'recurring_share_declared' => 'recurringShareDeclared',
    ];

    public function __construct(Connection $db, private readonly FieldCrypto $crypto, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    public function find(OrgId $org): ?FinancialProfileData
    {
        $row = $this->scopedOne($org, 'SELECT * FROM financial_profiles WHERE organization_id = :org');
        if ($row === null) {
            return null;
        }
        $publicId = TypedArray::string($row, 'public_id');
        $values = [];
        foreach (self::ENCRYPTED as $column => $property) {
            $values[$property] = $this->crypto->decryptDecimal(
                TypedArray::nullableString($row, $column . '_enc'),
                new FieldContext('financial_profiles', $column, $org->value, $publicId),
            );
        }
        $decimal = static fn (?string $v): ?Decimal => $v === null ? null : Decimal::of($v);
        $year = TypedArray::nullableString($row, 'revenue_target_year');

        return new FinancialProfileData(
            periodType: TypedArray::string($row, 'period_type'),
            periodStart: TypedArray::nullableString($row, 'period_start'),
            periodEnd: TypedArray::nullableString($row, 'period_end'),
            revenue: $values['revenue'],
            fixedCosts: $values['fixedCosts'],
            personnelCosts: $values['personnelCosts'],
            variableCostsAttributable: $values['variableCostsAttributable'],
            variableCostsGeneral: $values['variableCostsGeneral'],
            ownerCompTotalCost: $values['ownerCompTotalCost'],
            ownerCompIncludedAmount: $values['ownerCompIncludedAmount'],
            productionFte: $decimal(TypedArray::nullableString($row, 'production_fte')),
            annualHoursPerFte: $decimal(TypedArray::nullableString($row, 'annual_hours_per_fte')),
            annualHoursIsAssumption: TypedArray::int($row, 'annual_hours_is_assumption') === 1,
            billableRate: $decimal(TypedArray::nullableString($row, 'billable_rate')),
            billableRateIsAssumption: TypedArray::int($row, 'billable_rate_is_assumption') === 1,
            hourlyCostOverride: $values['hourlyCostOverride'],
            revenueTarget: $values['revenueTarget'],
            revenueTargetYear: $year === null ? null : (int) $year,
            recurringShareDeclared: $values['recurringShareDeclared'],
        );
    }

    public function save(OrgId $org, FinancialProfileData $data, ?int $userId): void
    {
        $existing = $this->scopedOne($org, 'SELECT public_id FROM financial_profiles WHERE organization_id = :org');
        $publicId = $existing === null ? PublicId::generate() : TypedArray::string($existing, 'public_id');
        $now = $this->clock->nowUtc()->format('Y-m-d H:i:s');

        $params = [
            'period_type' => $data->periodType,
            'period_start' => $data->periodStart,
            'period_end' => $data->periodEnd,
            'production_fte' => $data->productionFte?->toFixed(2),
            'annual_hours_per_fte' => $data->annualHoursPerFte?->toFixed(2),
            'annual_hours_is_assumption' => (int) $data->annualHoursIsAssumption,
            'billable_rate' => $data->billableRate?->toFixed(4),
            'billable_rate_is_assumption' => (int) $data->billableRateIsAssumption,
            'revenue_target_year' => $data->revenueTargetYear,
            'updated_by' => $userId,
            'now' => $now,
        ];
        foreach (self::ENCRYPTED as $column => $property) {
            /** @var Decimal|null $value */
            $value = $data->{$property};
            $params[$column . '_enc'] = $this->crypto->encryptDecimal($value, new FieldContext('financial_profiles', $column, $org->value, $publicId));
        }
        $assignments = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys(array_diff_key($params, ['now' => 1]))));

        if ($existing === null) {
            $columns = array_keys(array_diff_key($params, ['now' => 1]));
            $this->db->run(
                'INSERT INTO financial_profiles (public_id, organization_id, ' . implode(', ', $columns) . ', created_at, updated_at)
                 VALUES (:public_id, :organization_id, :' . implode(', :', $columns) . ', :now, :now2)',
                $params + ['public_id' => $publicId, 'organization_id' => $org->value, 'now2' => $now],
            );

            return;
        }
        $this->scopedExecute($org, "UPDATE financial_profiles SET {$assignments}, updated_at = :now WHERE organization_id = :org", $params);
    }

    /**
     * Journal des modifications (valeurs chiffrées, insertion seule).
     *
     * @param array<string, array{0: string|null, 1: string|null}> $changes champ => [ancienne, nouvelle]
     */
    public function appendChanges(OrgId $org, string $origin, ?int $userId, array $changes): void
    {
        $changeSet = PublicId::generate();
        $now = $this->clock->nowUtc()->format('Y-m-d H:i:s');
        foreach ($changes as $field => [$old, $new]) {
            $rowId = $changeSet . ':' . $field;
            $this->db->run(
                'INSERT INTO financial_profile_changes (organization_id, change_set_id, user_id, origin, field, old_value_enc, new_value_enc, changed_at)
                 VALUES (:org, :set, :user, :origin, :field, :old, :new, :now)',
                [
                    'org' => $org->value, 'set' => $changeSet, 'user' => $userId, 'origin' => $origin, 'field' => $field,
                    'old' => $old === null ? null : $this->crypto->encrypt($old, new FieldContext('financial_profile_changes', 'old_value', $org->value, $rowId)),
                    'new' => $new === null ? null : $this->crypto->encrypt($new, new FieldContext('financial_profile_changes', 'new_value', $org->value, $rowId)),
                    'now' => $now,
                ],
            );
        }
    }

    /**
     * @return list<array{field: string, old: string|null, new: string|null, origin: string, changed_at: string}>
     */
    public function changes(OrgId $org, int $limit = 50): array
    {
        $rows = $this->scopedAll(
            $org,
            'SELECT change_set_id, field, origin, old_value_enc, new_value_enc, changed_at FROM financial_profile_changes
              WHERE organization_id = :org ORDER BY changed_at DESC, id DESC LIMIT ' . max(1, min(200, $limit)),
        );
        $result = [];
        foreach ($rows as $row) {
            $rowId = TypedArray::string($row, 'change_set_id') . ':' . TypedArray::string($row, 'field');
            $old = TypedArray::nullableString($row, 'old_value_enc');
            $new = TypedArray::nullableString($row, 'new_value_enc');
            $result[] = [
                'field' => TypedArray::string($row, 'field'),
                'old' => $old === null ? null : $this->crypto->decrypt($old, new FieldContext('financial_profile_changes', 'old_value', $org->value, $rowId)),
                'new' => $new === null ? null : $this->crypto->decrypt($new, new FieldContext('financial_profile_changes', 'new_value', $org->value, $rowId)),
                'origin' => TypedArray::string($row, 'origin'),
                'changed_at' => TypedArray::string($row, 'changed_at'),
            ];
        }

        return $result;
    }
}
