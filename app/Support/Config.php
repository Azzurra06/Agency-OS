<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Configuration typée de l'application, construite à partir de config/.env.
 *
 * Profils (docs/06-roadmap.md) :
 *  - portfolio  : données fictives uniquement, inscription publique désactivée, aucun email envoyé ;
 *  - production : SaaS commercial (après la checklist « données réelles », docs/04-securite.md §14.3).
 */
final class Config
{
    public const PROFILE_PORTFOLIO = 'portfolio';
    public const PROFILE_PRODUCTION = 'production';

    public function __construct(
        public readonly string $env,
        public readonly string $profile,
        public readonly bool $realDataAllowed,
        public readonly string $appUrl,
        public readonly string $appKey,
        public readonly string $basePath,
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        public readonly bool $sessionSecure,
        public readonly int $sessionIdleMinutes,
        public readonly int $sessionAbsoluteHours,
        public readonly string $cryptoProvider,
        public readonly string $keysPath,
        public readonly string $mailTransport,
        public readonly string $mailFrom,
        public readonly string $mailAllowlist,
        public readonly string $smtpHost,
        public readonly int $smtpPort,
        public readonly string $smtpUser,
        public readonly string $smtpPassword,
        public readonly string $timezone,
        /** Mentions légales (facultatives en développement ; affichées « à compléter » si absentes). */
        public readonly string $legalPublisher = '',
        public readonly string $legalContactEmail = '',
        public readonly string $legalAddress = '',
        public readonly string $legalRegistration = '',
    ) {
    }

    /** Vrai si les mentions légales obligatoires sont renseignées (éditeur et contact). */
    public function legalComplete(): bool
    {
        return $this->legalPublisher !== '' && $this->legalContactEmail !== '';
    }

    public static function fromEnv(Env $env, string $basePath): self
    {
        $config = new self(
            env: $env->get('APP_ENV', 'production') ?? 'production',
            profile: $env->get('APP_PROFILE', self::PROFILE_PORTFOLIO) ?? self::PROFILE_PORTFOLIO,
            realDataAllowed: $env->bool('APP_REAL_DATA_ALLOWED', false),
            appUrl: rtrim($env->require('APP_URL'), '/'),
            appKey: $env->require('APP_KEY'),
            basePath: $basePath,
            dbHost: $env->get('DB_HOST', '127.0.0.1') ?? '127.0.0.1',
            dbPort: $env->int('DB_PORT', 3306),
            dbName: $env->require('DB_NAME'),
            dbUser: $env->require('DB_USER'),
            dbPassword: $env->get('DB_PASSWORD', '') ?? '',
            sessionSecure: $env->bool('SESSION_SECURE', true),
            sessionIdleMinutes: $env->int('SESSION_IDLE_MINUTES', 60),
            sessionAbsoluteHours: $env->int('SESSION_ABSOLUTE_HOURS', 12),
            cryptoProvider: $env->get('CRYPTO_PROVIDER', 'openssl') ?? 'openssl',
            // Vide = valeur par défaut (documenté dans config/.env.example) : jamais un chemin vide.
            keysPath: ($env->get('KEYS_PATH') ?? '') !== '' ? (string) $env->get('KEYS_PATH') : $basePath . '/config/keys',
            mailTransport: $env->get('MAIL_TRANSPORT', 'capture') ?? 'capture',
            mailFrom: $env->get('MAIL_FROM', 'no-reply@example.invalid') ?? 'no-reply@example.invalid',
            mailAllowlist: $env->get('MAIL_ALLOWLIST', '') ?? '',
            smtpHost: $env->get('SMTP_HOST', '') ?? '',
            smtpPort: $env->int('SMTP_PORT', 465),
            smtpUser: $env->get('SMTP_USER', '') ?? '',
            smtpPassword: $env->get('SMTP_PASSWORD', '') ?? '',
            timezone: $env->get('APP_TIMEZONE', 'Europe/Paris') ?? 'Europe/Paris',
            legalPublisher: $env->get('LEGAL_PUBLISHER', '') ?? '',
            legalContactEmail: $env->get('LEGAL_CONTACT_EMAIL', '') ?? '',
            legalAddress: $env->get('LEGAL_ADDRESS', '') ?? '',
            legalRegistration: $env->get('LEGAL_REGISTRATION', '') ?? '',
        );
        $config->assertCoherent();

        return $config;
    }

    public function isPortfolio(): bool
    {
        return $this->profile === self::PROFILE_PORTFOLIO;
    }

    public function isProduction(): bool
    {
        return $this->env === 'production';
    }

    public function isTesting(): bool
    {
        return $this->env === 'testing';
    }

    public function storagePath(string $relative = ''): string
    {
        return $this->basePath . '/storage' . ($relative === '' ? '' : '/' . ltrim($relative, '/'));
    }

    /**
     * Refuse les combinaisons dangereuses avant tout traitement (docs/06-roadmap.md §2).
     */
    private function assertCoherent(): void
    {
        if (!in_array($this->profile, [self::PROFILE_PORTFOLIO, self::PROFILE_PRODUCTION], true)) {
            throw new RuntimeException('APP_PROFILE invalide.');
        }
        if (!in_array($this->env, ['production', 'development', 'testing'], true)) {
            throw new RuntimeException('APP_ENV invalide.');
        }
        if (strlen($this->appKey) < 32) {
            throw new RuntimeException('APP_KEY doit contenir au moins 32 caractères aléatoires.');
        }
        if (!in_array($this->cryptoProvider, ['openssl', 'sodium'], true)) {
            throw new RuntimeException('CRYPTO_PROVIDER invalide.');
        }
        if (!in_array($this->mailTransport, ['capture', 'null', 'smtp'], true)) {
            throw new RuntimeException('MAIL_TRANSPORT invalide.');
        }
        if ($this->isPortfolio()) {
            if ($this->realDataAllowed) {
                throw new RuntimeException('Le profil portfolio n\'accepte pas de données réelles (APP_REAL_DATA_ALLOWED).');
            }
            if ($this->mailTransport === 'smtp') {
                throw new RuntimeException('Le profil portfolio n\'envoie aucun email réel (MAIL_TRANSPORT).');
            }
        }
        if ($this->isProduction()) {
            if (!str_starts_with($this->appUrl, 'https://')) {
                throw new RuntimeException('APP_URL doit utiliser HTTPS en production.');
            }
            if (!$this->sessionSecure) {
                throw new RuntimeException('SESSION_SECURE doit être activé en production.');
            }
        }
    }
}
