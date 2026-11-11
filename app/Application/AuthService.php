<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Database\Connection;
use App\Mail\Mailer;
use App\Mail\Message;
use App\Security\PasswordHasher;
use App\Security\RateLimiter;
use App\Security\SecurityEventLogger;
use App\Security\SessionManager;
use App\Support\Clock;
use App\Support\Config;
use App\Support\PublicId;
use App\Support\TypedArray;
use RuntimeException;

/**
 * Authentification (docs/04-securite.md §3) :
 *  - message unique et temps constant, que l'email existe ou non ;
 *  - limitation par email+IP, par email et par IP ; blocage même si le mot de passe est correct ;
 *  - régénération de session, époque de session (révocation globale) ;
 *  - réinitialisation par jeton à usage unique (SHA-256 stocké, 30 min), lien construit depuis APP_URL ;
 *  - vérification de l'email par jeton à usage unique (24 h), renvois limités.
 */
final class AuthService
{
    public const LIMITS = [
        'login_email_ip' => [5, 900],
        'login_email' => [20, 3600],
        'login_ip' => [50, 3600],
        'reset_email' => [3, 3600],
        'reset_ip' => [10, 3600],
        'verify_resend_account' => [5, 86400],
        'verify_resend_quick' => [1, 120],
    ];

    private const RESET_TTL = '+30 minutes';
    private const VERIFY_TTL = '+24 hours';

    public function __construct(
        private readonly Connection $db,
        private readonly PasswordHasher $passwords,
        private readonly RateLimiter $limiter,
        private readonly SecurityEventLogger $events,
        private readonly SessionManager $sessions,
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return 'ok'|'invalid'|'locked'|'deletion_scheduled'
     */
    public function attempt(string $email, string $password, string $ip, ?string $userAgent): string
    {
        $email = mb_strtolower(trim($email));
        $keys = ['login_email_ip' => $email . '|' . $ip, 'login_email' => $email, 'login_ip' => $ip];
        foreach ($keys as $bucket => $key) {
            [$limit, $window] = self::LIMITS[$bucket];
            if ($this->limiter->tooManyAttempts($bucket, $key, $limit, $window)) {
                // Blocage appliqué même si le mot de passe est correct (pas d'oracle).
                $this->passwords->verify($password, self::dummyHash());
                $this->events->record('auth.locked', ip: $ip, userAgent: $userAgent, context: ['bucket' => $bucket, 'email_hmac' => $this->emailHmac($email)]);

                return 'locked';
            }
        }

        $user = $this->db->fetchOne(
            "SELECT u.id, u.password_hash, u.status, u.email_verified_at, u.session_epoch, u.is_demo, m.organization_id
               FROM users u JOIN organization_members m ON m.user_id = u.id
              WHERE u.email = :email ORDER BY m.created_at LIMIT 1",
            ['email' => $email],
        );
        $hash = $user === null ? null : TypedArray::nullableString($user, 'password_hash');
        $valid = $this->passwords->verify($password, $hash ?? self::dummyHash()); // temps constant
        $usable = $user !== null && $hash !== null && $valid
            && TypedArray::string($user, 'status') === 'active'
            && TypedArray::nullableString($user, 'email_verified_at') !== null
            && TypedArray::int($user, 'is_demo') === 0;

        if ($user !== null && $hash !== null && $valid && TypedArray::string($user, 'status') === 'deletion_scheduled') {
            // Compte en cours de suppression (D8) : pas de session ; l'appelant envoie un lien d'annulation par email.
            $this->events->record('auth.login_failed', TypedArray::int($user, 'id'), ip: $ip, userAgent: $userAgent, context: ['reason' => 'deletion_scheduled']);

            return 'deletion_scheduled';
        }
        if (!$usable || $user === null || $hash === null) {
            foreach ($keys as $bucket => $key) {
                $this->limiter->hit($bucket, $key);
            }
            $this->events->record('auth.login_failed', ip: $ip, userAgent: $userAgent, context: ['email_hmac' => $this->emailHmac($email)]);

            return 'invalid';
        }

        $userId = TypedArray::int($user, 'id');
        if ($this->passwords->needsRehash($hash)) {
            $this->db->run('UPDATE users SET password_hash = :hash WHERE id = :id', ['hash' => $this->passwords->hash($password), 'id' => $userId]);
        }
        $this->limiter->clear('login_email_ip', $keys['login_email_ip']);
        $this->sessions->regenerate();
        $_SESSION['user_id'] = $userId;
        $_SESSION['organization_id'] = TypedArray::int($user, 'organization_id');
        $_SESSION['session_epoch'] = TypedArray::int($user, 'session_epoch');
        $this->db->run('UPDATE users SET last_login_at = :now WHERE id = :id', ['now' => $this->now(), 'id' => $userId]);
        $this->events->record('auth.login_succeeded', $userId, TypedArray::int($user, 'organization_id'), $ip, $userAgent);

        return 'ok';
    }

    public function logout(?int $userId, ?int $organizationId, string $ip): void
    {
        if ($userId !== null) {
            $this->events->record('auth.logout', $userId, $organizationId, $ip);
        }
        $this->sessions->destroy();
    }

    /** Révoque toutes les sessions de l'utilisateur (changement de mot de passe, « déconnecter tous les appareils »). */
    public function revokeAllSessions(int $userId): void
    {
        $this->db->run('UPDATE users SET session_epoch = session_epoch + 1 WHERE id = :id', ['id' => $userId]);
        $this->events->record('auth.sessions_revoked', $userId);
    }

    /**
     * Demande de réinitialisation : réponse identique que l'email existe ou non.
     */
    public function requestPasswordReset(string $email, string $ip): void
    {
        $email = mb_strtolower(trim($email));
        if ($this->limiter->tooManyAttempts('reset_email', $email, ...self::LIMITS['reset_email'])
            || $this->limiter->tooManyAttempts('reset_ip', $ip, ...self::LIMITS['reset_ip'])) {
            $this->events->record('ratelimit.exceeded', ip: $ip, context: ['bucket' => 'reset']);

            return;
        }
        $this->limiter->hit('reset_email', $email);
        $this->limiter->hit('reset_ip', $ip);

        $user = $this->db->fetchOne("SELECT id FROM users WHERE email = :email AND status = 'active' AND is_demo = 0", ['email' => $email]);
        if ($user === null) {
            return;
        }
        $userId = TypedArray::int($user, 'id');
        $token = bin2hex(random_bytes(32));
        $this->db->transaction(function () use ($userId, $token, $ip): void {
            $this->db->run('UPDATE password_resets SET used_at = :now WHERE user_id = :id AND used_at IS NULL', ['now' => $this->now(), 'id' => $userId]);
            $packed = @inet_pton($ip);
            $this->db->run(
                'INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip, created_at) VALUES (:id, :hash, :expires, :ip, :now)',
                ['id' => $userId, 'hash' => hash('sha256', $token, true), 'expires' => $this->clock->nowUtc()->modify(self::RESET_TTL)->format('Y-m-d H:i:s'),
                 'ip' => $packed === false ? null : $packed, 'now' => $this->now()],
            );
        });
        $this->mailer->send(new Message(
            $email,
            'Réinitialisation de votre mot de passe Agency OS',
            "Bonjour,\n\nPour choisir un nouveau mot de passe, ouvrez ce lien (valable 30 minutes, utilisable une seule fois) :\n"
            . $this->config->appUrl . '/reinitialisation/' . $token
            . "\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe reste inchangé.\n",
        ));
        $this->events->record('auth.password_reset_requested', $userId, ip: $ip);
    }

    /** Vérifie un jeton de réinitialisation sans le consommer ; renvoie l'utilisateur concerné. */
    public function resetTokenUser(string $token): ?int
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT user_id FROM password_resets WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now',
            ['hash' => hash('sha256', $token, true), 'now' => $this->now()],
        );

        return $row === null ? null : TypedArray::int($row, 'user_id');
    }

    /**
     * Consomme le jeton, change le mot de passe, révoque toutes les sessions et prévient par email.
     *
     * @return 'ok'|'invalid_token'|string code d'erreur de politique de mot de passe
     */
    public function resetPassword(string $token, string $newPassword, string $ip): string
    {
        $userId = $this->resetTokenUser($token);
        if ($userId === null) {
            return 'invalid_token';
        }
        $policy = $this->passwords->policyError($newPassword);
        if ($policy !== null) {
            return $policy;
        }
        $email = $this->db->transaction(function () use ($token, $userId, $newPassword): string {
            $consumed = $this->db->run(
                'UPDATE password_resets SET used_at = :now WHERE token_hash = :hash AND used_at IS NULL',
                ['now' => $this->now(), 'hash' => hash('sha256', $token, true)],
            )->rowCount();
            if ($consumed !== 1) {
                throw new RuntimeException('Jeton déjà utilisé.');
            }
            $this->db->run(
                'UPDATE users SET password_hash = :hash, password_changed_at = :now, session_epoch = session_epoch + 1 WHERE id = :id',
                ['hash' => $this->passwords->hash($newPassword), 'now' => $this->now(), 'id' => $userId],
            );

            return TypedArray::string($this->db->fetchOne('SELECT email FROM users WHERE id = :id', ['id' => $userId]) ?? [], 'email');
        });
        $this->events->record('auth.password_reset_completed', $userId, ip: $ip);
        $this->mailer->send(new Message($email, 'Votre mot de passe Agency OS a été modifié',
            "Bonjour,\n\nLe mot de passe de votre compte vient d'être modifié et toutes vos sessions ont été fermées.\n"
            . "Si vous n'êtes pas à l'origine de ce changement, contactez-nous immédiatement.\n"));

        return 'ok';
    }

    /** Envoie (ou renvoie) un lien de vérification de l'email, avec limitation des renvois. */
    public function sendVerification(int $userId): bool
    {
        $key = (string) $userId;
        if ($this->limiter->tooManyAttempts('verify_resend_quick', $key, ...self::LIMITS['verify_resend_quick'])
            || $this->limiter->tooManyAttempts('verify_resend_account', $key, ...self::LIMITS['verify_resend_account'])) {
            return false;
        }
        $user = $this->db->fetchOne('SELECT email, email_verified_at FROM users WHERE id = :id AND is_demo = 0', ['id' => $userId]);
        if ($user === null || TypedArray::nullableString($user, 'email_verified_at') !== null) {
            return false;
        }
        $this->limiter->hit('verify_resend_quick', $key);
        $this->limiter->hit('verify_resend_account', $key);
        $token = bin2hex(random_bytes(32));
        $this->db->transaction(function () use ($userId, $token): void {
            $this->db->run('UPDATE email_verifications SET used_at = :now WHERE user_id = :id AND used_at IS NULL', ['now' => $this->now(), 'id' => $userId]);
            $this->db->run(
                'INSERT INTO email_verifications (user_id, token_hash, expires_at, created_at) VALUES (:id, :hash, :expires, :now)',
                ['id' => $userId, 'hash' => hash('sha256', $token, true), 'expires' => $this->clock->nowUtc()->modify(self::VERIFY_TTL)->format('Y-m-d H:i:s'), 'now' => $this->now()],
            );
        });
        $this->mailer->send(new Message(TypedArray::string($user, 'email'), 'Vérifiez votre adresse email',
            "Bonjour,\n\nPour activer votre compte Agency OS, ouvrez ce lien (valable 24 heures, utilisable une seule fois) :\n"
            . $this->config->appUrl . '/verification/' . $token . "\n"));

        return true;
    }

    public function verifyEmail(string $token): bool
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return false;
        }

        return $this->db->transaction(function () use ($token): bool {
            $row = $this->db->fetchOne(
                'SELECT id, user_id FROM email_verifications WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now',
                ['hash' => hash('sha256', $token, true), 'now' => $this->now()],
            );
            if ($row === null) {
                return false;
            }
            $userId = TypedArray::int($row, 'user_id');
            $this->db->run('UPDATE email_verifications SET used_at = :now WHERE id = :id', ['now' => $this->now(), 'id' => TypedArray::int($row, 'id')]);
            $this->db->run(
                "UPDATE users SET email_verified_at = :now, status = IF(status = 'pending_verification', 'active', status) WHERE id = :id",
                ['now' => $this->now(), 'id' => $userId],
            );
            $this->events->record('auth.email_verified', $userId);

            return true;
        });
    }

    /**
     * Compte de test (profil portfolio : seul moyen de créer un compte, en ligne de commande).
     *
     * @return array{user_id: int, organization_id: int}
     */
    public function createTestAccount(string $email, string $password, string $agencyName, bool $verified = true): array
    {
        $policy = $this->passwords->policyError($password);
        if ($policy !== null) {
            throw new RuntimeException($policy);
        }
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Adresse email invalide.');
        }

        return $this->db->transaction(function () use ($email, $password, $agencyName, $verified): array {
            $now = $this->now();
            $this->db->run(
                'INSERT INTO users (public_id, email, password_hash, status, email_verified_at, created_at, updated_at)
                 VALUES (:pid, :email, :hash, :status, :verified, :now, :now2)',
                ['pid' => PublicId::generate(), 'email' => $email, 'hash' => $this->passwords->hash($password),
                 'status' => $verified ? 'active' : 'pending_verification', 'verified' => $verified ? $now : null, 'now' => $now, 'now2' => $now],
            );
            $userId = $this->db->lastInsertId();
            $this->db->run(
                'INSERT INTO organizations (public_id, name, created_at, updated_at) VALUES (:pid, :name, :now, :now2)',
                ['pid' => PublicId::generate(), 'name' => mb_substr($agencyName, 0, 150), 'now' => $now, 'now2' => $now],
            );
            $orgId = $this->db->lastInsertId();
            $this->db->run('INSERT INTO organization_members (organization_id, user_id, role, created_at) VALUES (:org, :user, \'owner\', :now)',
                ['org' => $orgId, 'user' => $userId, 'now' => $now]);
            $this->db->run('INSERT INTO organization_settings (organization_id, created_at, updated_at) VALUES (:org, :now, :now2)',
                ['org' => $orgId, 'now' => $now, 'now2' => $now]);

            return ['user_id' => $userId, 'organization_id' => $orgId];
        });
    }

    private function emailHmac(string $email): string
    {
        return substr(hash_hmac('sha256', $email, $this->config->appKey), 0, 16);
    }

    private function now(): string
    {
        return $this->clock->nowUtc()->format('Y-m-d H:i:s');
    }

    /** Hachage factice (bcrypt coût 12) pour égaliser le temps de réponse quand l'email est inconnu. */
    private static function dummyHash(): string
    {
        return '$2y$12$7xksBhALjZ1hyFATC8rHcOR/T8oQQr5m.SYFAabcYUkL0R.codmdG';
    }
}
