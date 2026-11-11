<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\OrgId;
use App\Support\Clock;
use App\Support\TypedArray;

/**
 * Identité et réglages de l'organisation. La table organizations est indexée par son propre id :
 * chaque requête filtre explicitement « id = :org », où :org provient de l'OrgId résolu depuis la session.
 */
final class OrganizationRepository
{
    public const ACTIVITY_TYPES = ['wordpress', 'web', 'digital', 'marketing', 'other'];
    public const LEGAL_STATUSES = ['micro', 'ei', 'eurl', 'sarl', 'sasu', 'sas', 'other'];

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /**
     * @return array{public_id: string, name: string, activity_type: string|null, team_size: int|null, legal_status: string|null, is_demo: bool, demo_expires_at: string|null, onboarding_step: int, onboarding_completed: bool}
     */
    public function get(OrgId $org): array
    {
        $row = $this->db->fetchOne(
            'SELECT o.public_id, o.name, o.activity_type, o.team_size, o.legal_status, o.is_demo, o.demo_expires_at,
                    s.onboarding_step, s.onboarding_completed_at
               FROM organizations o LEFT JOIN organization_settings s ON s.organization_id = o.id
              WHERE o.id = :org',
            ['org' => $org->value],
        ) ?? [];
        $team = TypedArray::nullableString($row, 'team_size');

        return [
            'public_id' => TypedArray::string($row, 'public_id'),
            'name' => TypedArray::string($row, 'name'),
            'activity_type' => TypedArray::nullableString($row, 'activity_type'),
            'team_size' => $team === null ? null : (int) $team,
            'legal_status' => TypedArray::nullableString($row, 'legal_status'),
            'is_demo' => TypedArray::int($row, 'is_demo') === 1,
            'demo_expires_at' => TypedArray::nullableString($row, 'demo_expires_at'),
            'onboarding_step' => TypedArray::int($row, 'onboarding_step', 0),
            'onboarding_completed' => TypedArray::nullableString($row, 'onboarding_completed_at') !== null,
        ];
    }

    /**
     * @param array{name?: string, activity_type?: string|null, team_size?: int|null, legal_status?: string|null} $fields
     */
    public function updateIdentity(OrgId $org, array $fields): void
    {
        $sets = [];
        $params = ['now' => $this->clock->nowUtc()->format('Y-m-d H:i:s')];
        foreach (['name', 'activity_type', 'team_size', 'legal_status'] as $column) {
            if (array_key_exists($column, $fields)) {
                $sets[] = "{$column} = :{$column}";
                $params[$column] = $fields[$column];
            }
        }
        if ($sets !== []) {
            $this->db->run('UPDATE organizations SET ' . implode(', ', $sets) . ', updated_at = :now WHERE id = :id', $params + ['id' => $org->value]);
        }
    }

    public function setOnboardingStep(OrgId $org, int $step, bool $completed): void
    {
        $now = $this->clock->nowUtc()->format('Y-m-d H:i:s');
        $this->db->run(
            'INSERT INTO organization_settings (organization_id, onboarding_step, onboarding_completed_at, created_at, updated_at)
             VALUES (:org, :step, :completed, :now, :now2)
             ON DUPLICATE KEY UPDATE onboarding_step = GREATEST(onboarding_step, VALUES(onboarding_step)),
                 onboarding_completed_at = COALESCE(onboarding_completed_at, VALUES(onboarding_completed_at)), updated_at = VALUES(updated_at)',
            ['org' => $org->value, 'step' => $step, 'completed' => $completed ? $now : null, 'now' => $now, 'now2' => $now],
        );
    }
}
