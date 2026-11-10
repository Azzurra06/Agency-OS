<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Protection CSRF (docs/04-securite.md §5) :
 *  - jeton synchronisé par session (32 octets aléatoires), comparé en temps constant ;
 *  - vérification de l'origine (en-tête Origin, ou Referer à défaut) par rapport à APP_URL ;
 *  - les méthodes sûres (GET, HEAD, OPTIONS) ne modifient jamais de données et ne sont pas contrôlées.
 */
final class CsrfGuard
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly string $appUrl)
    {
    }

    /**
     * @param array<string, mixed> $session
     */
    public function token(array &$session): string
    {
        $token = $session[SessionManager::KEY_CSRF] ?? null;
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $session[SessionManager::KEY_CSRF] = $token;
        }

        return $token;
    }

    public static function isSafeMethod(string $method): bool
    {
        return in_array(strtoupper($method), self::SAFE_METHODS, true);
    }

    /**
     * @param array<string, mixed> $session
     * @return string|null raison du refus, ou null si la requête est acceptée
     */
    public function rejectionReason(string $method, ?string $submittedToken, ?string $origin, ?string $referer, array $session): ?string
    {
        if (self::isSafeMethod($method)) {
            return null;
        }
        $expected = $session[SessionManager::KEY_CSRF] ?? null;
        if (!is_string($expected) || $submittedToken === null || !hash_equals($expected, $submittedToken)) {
            return 'token';
        }
        $source = $origin ?? ($referer !== null ? self::originOf($referer) : null);
        if ($source === null || !hash_equals(self::originOf($this->appUrl), $source)) {
            return 'origin';
        }

        return null;
    }

    private static function originOf(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }
}
