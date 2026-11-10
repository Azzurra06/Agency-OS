<?php

declare(strict_types=1);

namespace App\Security;

use App\Support\TypedArray;
use App\Infrastructure\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Lecture de l'identité et de l'appartenance (utilisateur ↔ organisation) pour l'authentification.
 * La jointure sur organization_members garantit qu'un utilisateur n'accède qu'à SES organisations.
 */
class IdentityRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function authContext(int $userId, int $organizationId): ?AuthContext
    {
        $row = $this->db->fetchOne(
            'SELECT u.id AS user_id, u.public_id AS user_public_id, u.status, u.email_verified_at, u.session_epoch,
                    o.id AS org_id, o.public_id AS org_public_id, o.is_demo, o.demo_expires_at, o.status AS org_status, m.role
               FROM organization_members m
               JOIN users u ON u.id = m.user_id
               JOIN organizations o ON o.id = m.organization_id
              WHERE m.user_id = :user AND m.organization_id = :org',
            ['user' => $userId, 'org' => $organizationId],
        );
        if ($row === null || $row['org_status'] !== 'active') {
            return null;
        }
        $utc = new DateTimeZone('UTC');

        $demoExpiresAt = TypedArray::nullableString($row, 'demo_expires_at');

        return new AuthContext(
            userId: TypedArray::int($row, 'user_id'),
            userPublicId: TypedArray::string($row, 'user_public_id'),
            userStatus: TypedArray::string($row, 'status'),
            emailVerified: $row['email_verified_at'] !== null,
            sessionEpoch: TypedArray::int($row, 'session_epoch'),
            organizationId: TypedArray::int($row, 'org_id'),
            organizationPublicId: TypedArray::string($row, 'org_public_id'),
            role: TypedArray::string($row, 'role'),
            isDemo: TypedArray::int($row, 'is_demo') === 1,
            demoExpiresAt: $demoExpiresAt === null ? null : new DateTimeImmutable($demoExpiresAt, $utc),
        );
    }
}
