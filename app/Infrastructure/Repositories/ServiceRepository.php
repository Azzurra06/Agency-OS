<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\OrgId;
use App\Infrastructure\Database\OrgScopedRepository;
use App\Support\Clock;
use App\Support\Decimal;
use App\Support\PublicId;
use App\Support\TypedArray;

/**
 * Catalogue des prestations ponctuelles (prix pratiqué = source unique du « prix actuel »).
 */
final class ServiceRepository extends OrgScopedRepository
{
    public const CATEGORIES = ['showcase', 'ecommerce', 'redesign', 'seo', 'custom', 'other'];

    public function __construct(Connection $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /**
     * @return list<array{public_id: string, name: string, category: string, current_price: string|null, annual_volume: int|null, is_active: bool}>
     */
    public function all(OrgId $org, bool $activeOnly = false): array
    {
        $rows = $this->scopedAll(
            $org,
            'SELECT public_id, name, category, current_price, annual_volume, is_active FROM services
              WHERE organization_id = :org' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY position, id',
        );

        return array_map(static function (array $row): array {
            $volume = TypedArray::nullableString($row, 'annual_volume');

            return [
                'public_id' => TypedArray::string($row, 'public_id'),
                'name' => TypedArray::string($row, 'name'),
                'category' => TypedArray::string($row, 'category'),
                'current_price' => TypedArray::nullableString($row, 'current_price'),
                'annual_volume' => $volume === null ? null : (int) $volume,
                'is_active' => TypedArray::int($row, 'is_active') === 1,
            ];
        }, $rows);
    }

    public function exists(OrgId $org, string $publicId): bool
    {
        return $this->scopedOne($org, 'SELECT id FROM services WHERE organization_id = :org AND public_id = :pid', ['pid' => $publicId]) !== null;
    }

    public function create(OrgId $org, string $name, string $category, ?Decimal $price, ?int $volume): string
    {
        $publicId = PublicId::generate();
        $now = $this->clock->nowUtc()->format('Y-m-d H:i:s');
        $position = TypedArray::int($this->scopedOne($org, 'SELECT COUNT(*) AS n FROM services WHERE organization_id = :org') ?? [], 'n', 0);
        $this->db->run(
            'INSERT INTO services (public_id, organization_id, name, category, current_price, annual_volume, position, created_at, updated_at)
             VALUES (:pid, :org, :name, :category, :price, :volume, :position, :now, :now2)',
            ['pid' => $publicId, 'org' => $org->value, 'name' => $name, 'category' => $category, 'price' => $price?->toFixed(2),
             'volume' => $volume, 'position' => $position, 'now' => $now, 'now2' => $now],
        );

        return $publicId;
    }

    /**
     * @param array<string, mixed> $fields name, category, current_price (Decimal|null), annual_volume (int|null), is_active (bool)
     */
    public function update(OrgId $org, string $publicId, array $fields): bool
    {
        $allowed = ['name', 'category', 'current_price', 'annual_volume', 'is_active'];
        $sets = [];
        $params = ['pid' => $publicId, 'now' => $this->clock->nowUtc()->format('Y-m-d H:i:s')];
        foreach ($allowed as $column) {
            if (!array_key_exists($column, $fields)) {
                continue;
            }
            $value = $fields[$column];
            $params[$column] = match (true) {
                $value instanceof Decimal => $value->toFixed(2),
                is_bool($value) => (int) $value,
                default => $value,
            };
            $sets[] = "{$column} = :{$column}";
        }
        if ($sets === []) {
            return $this->exists($org, $publicId);
        }

        return $this->scopedExecute($org, 'UPDATE services SET ' . implode(', ', $sets) . ', updated_at = :now WHERE organization_id = :org AND public_id = :pid', $params) > 0
            || $this->exists($org, $publicId);
    }

    public function delete(OrgId $org, string $publicId): bool
    {
        return $this->scopedExecute($org, 'DELETE FROM services WHERE organization_id = :org AND public_id = :pid', ['pid' => $publicId]) > 0;
    }

    public function hasPricedService(OrgId $org): bool
    {
        return $this->scopedOne($org, 'SELECT id FROM services WHERE organization_id = :org AND is_active = 1 AND current_price IS NOT NULL LIMIT 1') !== null;
    }
}
