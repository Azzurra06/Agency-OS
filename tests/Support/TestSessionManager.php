<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Security\SessionManager;

/**
 * Session simulée pour les tests (aucun en-tête HTTP émis) : même logique d'expiration que la vraie.
 */
final class TestSessionManager extends SessionManager
{
    public function start(): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        $this->enforceLifetime();
    }

    public function regenerate(): void
    {
        unset($_SESSION[self::KEY_CSRF]);
    }

    public function destroy(): void
    {
        $_SESSION = [];
    }
}
