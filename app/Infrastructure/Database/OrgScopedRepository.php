<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use LogicException;

/**
 * Base de tous les dépôts métier (isolation entre agences, niveau 2 — docs/04-securite.md §4).
 *
 * Chaque requête exige un OrgId et DOIT contenir le paramètre « :org » (filtre
 * « organization_id = :org »). Une requête sans ce paramètre lève une exception :
 * il est impossible d'écrire par inadvertance une requête métier non restreinte.
 */
abstract class OrgScopedRepository
{
    public function __construct(protected readonly Connection $db)
    {
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    protected function scopedOne(OrgId $org, string $sql, array $params = []): ?array
    {
        return $this->db->fetchOne(self::guard($sql), ['org' => $org->value] + $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    protected function scopedAll(OrgId $org, string $sql, array $params = []): array
    {
        return $this->db->fetchAll(self::guard($sql), ['org' => $org->value] + $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function scopedExecute(OrgId $org, string $sql, array $params = []): int
    {
        return $this->db->run(self::guard($sql), ['org' => $org->value] + $params)->rowCount();
    }

    private static function guard(string $sql): string
    {
        if (!preg_match('/organization_id\s*=\s*:org\b/i', $sql)) {
            throw new LogicException('Requête métier sans filtre « organization_id = :org » refusée.');
        }

        return $sql;
    }
}
