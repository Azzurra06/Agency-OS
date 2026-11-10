<?php

declare(strict_types=1);

namespace App\Security;

use DateTimeImmutable;

/**
 * Identité vérifiée de la requête en cours (construite par le middleware Authenticate).
 */
final class AuthContext
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userPublicId,
        public readonly string $userStatus,
        public readonly bool $emailVerified,
        public readonly int $sessionEpoch,
        public readonly int $organizationId,
        public readonly string $organizationPublicId,
        public readonly string $role,
        public readonly bool $isDemo,
        public readonly ?DateTimeImmutable $demoExpiresAt,
    ) {
    }

    public function canSignIn(): bool
    {
        return $this->userStatus === 'active' && ($this->emailVerified || $this->isDemo);
    }
}
