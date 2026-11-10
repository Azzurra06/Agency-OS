<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Matrice des permissions par rôle (moindre privilège, refus par défaut).
 * Au MVP seul le rôle « owner » est utilisé ; « admin » et « member » préparent le multi-collaborateurs.
 */
final class Permissions
{
    /** @var array<string, list<string>> */
    private const MATRIX = [
        'owner' => ['*'],
        'admin' => ['app.read', 'organization.write', 'finance.read', 'finance.write', 'diagnostic.write', 'pricing.write', 'recurring.write', 'strategy.write'],
        'member' => ['app.read', 'finance.read', 'diagnostic.write', 'pricing.write', 'recurring.write', 'strategy.write'],
    ];

    public static function allows(string $role, string $permission): bool
    {
        $granted = self::MATRIX[$role] ?? [];

        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }
}
