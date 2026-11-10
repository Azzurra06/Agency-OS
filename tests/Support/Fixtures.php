<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Database\Connection;
use App\Support\PublicId;

/**
 * Création d'utilisateurs et d'organisations de test (données fictives).
 */
final class Fixtures
{
    /**
     * @return array{user_id: int, org_id: int, org_public_id: string}
     */
    public static function member(
        Connection $db,
        string $email,
        string $role = 'owner',
        bool $verified = true,
        bool $isDemo = false,
        ?string $demoExpiresAt = null,
        string $status = 'active',
    ): array {
        $now = '2026-11-12 08:00:00';
        $db->run(
            'INSERT INTO users (public_id, email, password_hash, status, is_demo, email_verified_at, created_at, updated_at)
             VALUES (:pid, :email, NULL, :status, :demo, :verified, :now, :now2)',
            ['pid' => PublicId::generate(), 'email' => $email, 'status' => $status, 'demo' => (int) $isDemo,
             'verified' => $verified ? $now : null, 'now' => $now, 'now2' => $now],
        );
        $userId = $db->lastInsertId();
        $orgPublicId = PublicId::generate();
        $db->run(
            'INSERT INTO organizations (public_id, name, is_demo, demo_expires_at, created_at, updated_at)
             VALUES (:pid, :name, :demo, :expires, :now, :now2)',
            ['pid' => $orgPublicId, 'name' => 'Agence ' . $email, 'demo' => (int) $isDemo, 'expires' => $demoExpiresAt, 'now' => $now, 'now2' => $now],
        );
        $orgId = $db->lastInsertId();
        $db->run(
            'INSERT INTO organization_members (organization_id, user_id, role, created_at) VALUES (:org, :user, :role, :now)',
            ['org' => $orgId, 'user' => $userId, 'role' => $role, 'now' => $now],
        );

        return ['user_id' => $userId, 'org_id' => $orgId, 'org_public_id' => $orgPublicId];
    }

    /**
     * @param array{user_id: int, org_id: int} $member
     */
    public static function login(array $member, int $epoch = 1): void
    {
        $_SESSION = ['user_id' => $member['user_id'], 'organization_id' => $member['org_id'], 'session_epoch' => $epoch];
    }
}
