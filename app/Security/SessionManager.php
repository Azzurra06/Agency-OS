<?php

declare(strict_types=1);

namespace App\Security;

use App\Support\TypedArray;
use App\Support\Clock;
use App\Support\Config;
use RuntimeException;

/**
 * Sessions PHP durcies (docs/04-securite.md §3.4). Les réglages sont appliqués PAR LE CODE,
 * indépendamment de la configuration partagée de l'hébergement (docs/04-securite.md §6.3).
 *
 * - cookie « __Host-aos_sid » (HTTPS) : Secure, HttpOnly, SameSite=Lax, Path=/, sans Domain ;
 * - mode strict, cookies uniquement, identifiant long ;
 * - stockage dans storage/sessions (hors du répertoire public, propre à l'application) ;
 * - expiration après inactivité et durée maximale ; régénération de l'identifiant à la connexion.
 */
class SessionManager
{
    public const KEY_STARTED = '_started_at';
    public const KEY_LAST_SEEN = '_last_seen_at';
    public const KEY_CSRF = '_csrf';

    public function __construct(private readonly Config $config, private readonly Clock $clock = new Clock())
    {
    }

    public function cookieName(): string
    {
        return $this->config->sessionSecure ? '__Host-aos_sid' : 'aos_sid_dev';
    }

    /**
     * @return array<string, string|int|bool>
     */
    public function iniSettings(): array
    {
        return [
            'session.use_strict_mode' => '1',
            'session.use_only_cookies' => '1',
            'session.use_trans_sid' => '0',
            'session.cookie_httponly' => '1',
            'session.cookie_secure' => $this->config->sessionSecure ? '1' : '0',
            'session.cookie_samesite' => 'Lax',
            'session.sid_length' => '48',
            'session.sid_bits_per_character' => '6',
            'session.gc_maxlifetime' => (string) ($this->config->sessionIdleMinutes * 60),
            'session.save_path' => $this->config->storagePath('sessions'),
            'session.cache_limiter' => 'nocache',
        ];
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $path = $this->config->storagePath('sessions');
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Répertoire de sessions indisponible.');
        }
        foreach ($this->iniSettings() as $key => $value) {
            // sid_length et sid_bits_per_character sont dépréciés à partir de PHP 8.4 : ignorés si refusés.
            @ini_set($key, (string) $value);
        }
        session_name($this->cookieName());
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->config->sessionSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        $this->enforceLifetime();
    }

    /** Expiration par inactivité ou durée maximale : la session est détruite puis recréée vide. */
    public function enforceLifetime(): void
    {
        $now = $this->clock->nowUtc()->getTimestamp();
        $started = TypedArray::int($_SESSION, self::KEY_STARTED, 0);
        $lastSeen = TypedArray::int($_SESSION, self::KEY_LAST_SEEN, 0);
        $idleExpired = $lastSeen > 0 && ($now - $lastSeen) > $this->config->sessionIdleMinutes * 60;
        $absoluteExpired = $started > 0 && ($now - $started) > $this->config->sessionAbsoluteHours * 3600;

        if ($idleExpired || $absoluteExpired) {
            $this->destroy();
            session_start();
            $_SESSION['_expired'] = true;
        }
        $_SESSION[self::KEY_STARTED] ??= $now;
        $_SESSION[self::KEY_LAST_SEEN] = $now;
    }

    /** À appeler à la connexion, à la réauthentification et au changement de mot de passe. */
    public function regenerate(): void
    {
        session_regenerate_id(true);
        $_SESSION[self::KEY_STARTED] = $this->clock->nowUtc()->getTimestamp();
        unset($_SESSION[self::KEY_CSRF]);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            if (!headers_sent()) {
                setcookie($this->cookieName(), '', [
                    'expires' => 1,
                    'path' => $params['path'],
                    'secure' => $params['secure'],
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            session_destroy();
        }
    }
}
