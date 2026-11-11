<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\OrgId;
use App\Mail\Mailer;
use App\Mail\Message;
use App\Security\PasswordHasher;
use App\Security\RateLimiter;
use App\Security\SecurityEventLogger;
use App\Support\Clock;
use App\Support\Config;
use App\Support\TypedArray;

/**
 * Compte, sécurité et données (docs/04 §7.4 et §7.6, décision D8) :
 * - changement de mot de passe (mot de passe actuel exigé, autres sessions révoquées) ;
 * - export complet généré À LA VOLÉE (jamais écrit sur le disque), réauthentification, 5 par heure, notification email ;
 * - suppression en deux temps : mot de passe + lien email (1 h) → désactivation immédiate, annulable 7 jours
 *   (mot de passe + lien email), puis purge par le cron à J+7 avec vérification par comptage et registre minimal.
 */
final class AccountService
{
    public const EXPORT_LIMIT = [5, 3600];
    public const TOKEN_TTL = '+1 hour';
    public const GRACE = '+7 days';

    public function __construct(
        private readonly Connection $db,
        private readonly PasswordHasher $passwords,
        private readonly RateLimiter $limiter,
        private readonly SecurityEventLogger $events,
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly ExportService $exporter,
    ) {
    }

    /** @return array{email: string, created_at: string, password_changed_at: string|null, deletion_scheduled_at: string|null} */
    public function profile(int $userId): array
    {
        $u = $this->db->fetchOne('SELECT email, created_at, password_changed_at, deletion_scheduled_at FROM users WHERE id = :id', ['id' => $userId]) ?? [];

        return [
            'email' => TypedArray::string($u, 'email', ''), 'created_at' => TypedArray::string($u, 'created_at', ''),
            'password_changed_at' => TypedArray::nullableString($u, 'password_changed_at'), 'deletion_scheduled_at' => TypedArray::nullableString($u, 'deletion_scheduled_at'),
        ];
    }

    /** @return list<array{at: string, ip: string|null}> dernières connexions (IP tronquée) */
    public function recentLogins(int $userId, int $limit = 10): array
    {
        return array_map(static function (array $r): array {
            $packed = TypedArray::nullableString($r, 'ip');
            $ip = $packed === null ? null : @inet_ntop($packed);
            if (is_string($ip)) {
                $ip = str_contains($ip, ':') ? implode(':', array_slice(explode(':', $ip), 0, 3)) . ':…' : preg_replace('/\.\d+$/', '.…', $ip);
            }

            return ['at' => TypedArray::string($r, 'created_at'), 'ip' => is_string($ip) ? $ip : null];
        }, $this->db->fetchAll(
            "SELECT created_at, ip FROM security_events WHERE user_id = :id AND event = 'auth.login_succeeded' ORDER BY created_at DESC, id DESC LIMIT " . max(1, min(50, $limit)),
            ['id' => $userId],
        ));
    }

    /** @return 'ok'|'invalid_password'|string code de politique de mot de passe */
    public function changePassword(int $userId, string $current, string $new, string $ip): string
    {
        if (!$this->checkPassword($userId, $current)) {
            $this->events->record('auth.login_failed', $userId, ip: $ip, context: ['reason' => 'password_change']);

            return 'invalid_password';
        }
        $policy = $this->passwords->policyError($new);
        if ($policy !== null) {
            return $policy;
        }
        $this->db->run(
            'UPDATE users SET password_hash = :hash, password_changed_at = :now, session_epoch = session_epoch + 1 WHERE id = :id',
            ['hash' => $this->passwords->hash($new), 'now' => $this->now(), 'id' => $userId],
        );
        // Les autres appareils sont déconnectés ; la session courante est conservée.
        $_SESSION['session_epoch'] = TypedArray::int($this->db->fetchOne('SELECT session_epoch FROM users WHERE id = :id', ['id' => $userId]) ?? [], 'session_epoch');
        $this->events->record('auth.password_changed', $userId, ip: $ip);
        $this->notify($userId, 'Votre mot de passe Agency OS a été modifié', "Bonjour,\n\nLe mot de passe de votre compte vient d'être modifié et vos autres sessions ont été fermées.\nSi vous n'êtes pas à l'origine de ce changement, réinitialisez votre mot de passe immédiatement.\n");

        return 'ok';
    }

    /**
     * Export complet (JSON), après vérification du mot de passe. Renvoie null si refusé.
     *
     * @return array{status: 'ok', body: string}|array{status: 'invalid_password'|'rate_limited'}
     */
    public function export(int $userId, OrgId $org, string $password, string $ip): array
    {
        if ($this->limiter->tooManyAttempts('export', (string) $userId, ...self::EXPORT_LIMIT)) {
            $this->events->record('ratelimit.exceeded', $userId, $org->value, $ip, context: ['bucket' => 'export']);

            return ['status' => 'rate_limited'];
        }
        $this->limiter->hit('export', (string) $userId);
        if (!$this->checkPassword($userId, $password)) {
            $this->events->record('auth.login_failed', $userId, $org->value, $ip, context: ['reason' => 'export_reauth']);

            return ['status' => 'invalid_password'];
        }
        $body = json_encode($this->exporter->export($org, $userId), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->events->record('account.export', $userId, $org->value, $ip);
        $this->notify($userId, 'Export de vos données Agency OS', "Bonjour,\n\nUn export complet des données de votre compte vient d'être téléchargé.\nSi vous n'êtes pas à l'origine de cette action, changez votre mot de passe immédiatement.\n");

        return ['status' => 'ok', 'body' => $body];
    }

    /** Étape 1 de D8 : mot de passe, puis lien de confirmation (1 h, usage unique) envoyé à l'adresse du compte. */
    public function requestDeletion(int $userId, string $password, string $ip): bool
    {
        if (!$this->checkPassword($userId, $password)) {
            $this->events->record('auth.login_failed', $userId, ip: $ip, context: ['reason' => 'deletion_reauth']);

            return false;
        }
        $token = $this->issueToken($userId, 'delete_confirm');
        $this->notify($userId, 'Confirmez la suppression de votre compte Agency OS',
            "Bonjour,\n\nPour confirmer la suppression de votre compte, ouvrez ce lien (valable 1 heure, utilisable une seule fois) :\n"
            . $this->config->appUrl . '/compte/suppression/' . $token
            . "\n\nLe compte sera désactivé immédiatement, puis supprimé définitivement après 7 jours. Vous pourrez annuler pendant ce délai.\n"
            . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message et changez votre mot de passe.\n");

        return true;
    }

    /** Étape 2 de D8 : le lien est consommé, le compte est désactivé immédiatement (sessions invalidées). Renvoie la date de purge. */
    public function confirmDeletion(string $token, string $ip): ?string
    {
        $userId = $this->consumeToken($token, 'delete_confirm');
        if ($userId === null) {
            return null;
        }
        $now = $this->clock->nowUtc();
        $this->db->run(
            "UPDATE users SET status = 'deletion_scheduled', deletion_scheduled_at = :now, session_epoch = session_epoch + 1 WHERE id = :id AND status = 'active'",
            ['now' => $now->format('Y-m-d H:i:s'), 'id' => $userId],
        );
        $this->events->record('account.deletion_requested', $userId, ip: $ip);
        $purge = $now->modify(self::GRACE)->format('Y-m-d');
        $this->notify($userId, 'Votre compte Agency OS est désactivé', "Bonjour,\n\nVotre compte est désactivé. Ses données seront supprimées définitivement le "
            . $purge . " (UTC).\nPour annuler, connectez-vous avec votre mot de passe avant cette date : un lien d'annulation vous sera envoyé.\n");

        return $purge;
    }

    /** Annulation (étape 3 de D8) : la connexion avec le bon mot de passe déclenche l'envoi d'un lien d'annulation. */
    public function sendCancellationLink(string $email): void
    {
        $user = $this->db->fetchOne("SELECT id FROM users WHERE email = :email AND status = 'deletion_scheduled'", ['email' => mb_strtolower(trim($email))]);
        if ($user === null) {
            return;
        }
        $userId = TypedArray::int($user, 'id');
        $token = $this->issueToken($userId, 'delete_cancel');
        $this->notify($userId, 'Annuler la suppression de votre compte Agency OS',
            "Bonjour,\n\nPour annuler la suppression de votre compte et le réactiver, ouvrez ce lien (valable 1 heure, utilisable une seule fois) :\n"
            . $this->config->appUrl . '/compte/annulation/' . $token . "\n");
    }

    public function cancelDeletion(string $token, string $ip): bool
    {
        $userId = $this->consumeToken($token, 'delete_cancel');
        if ($userId === null) {
            return false;
        }
        $done = $this->db->run(
            "UPDATE users SET status = 'active', deletion_scheduled_at = NULL WHERE id = :id AND status = 'deletion_scheduled'",
            ['id' => $userId],
        )->rowCount() === 1;
        if ($done) {
            $this->events->record('account.deletion_cancelled', $userId, ip: $ip);
        }

        return $done;
    }

    /** Vérifie un lien sans le consommer (affichage de la page de confirmation). */
    public function tokenIsValid(string $token, string $purpose): bool
    {
        return $this->tokenUser($token, $purpose) !== null;
    }

    /**
     * Purge à J+7 (cron) : organisations dont l'utilisateur est le seul membre (cascade sur toutes les tables),
     * adhésions, utilisateur ; vérification par comptage ; registre minimal ; événement anonymisé.
     */
    public function purgeScheduled(int $limit = 100): int
    {
        $users = $this->db->fetchAll(
            "SELECT id FROM users WHERE status = 'deletion_scheduled' AND deletion_scheduled_at <= :limit ORDER BY deletion_scheduled_at LIMIT " . max(1, min(500, $limit)),
            ['limit' => $this->clock->nowUtc()->modify('-7 days')->format('Y-m-d H:i:s')],
        );
        $count = 0;
        foreach ($users as $u) {
            $userId = TypedArray::int($u, 'id');
            $this->db->transaction(function () use ($userId): void {
                $orgs = $this->db->fetchAll(
                    'SELECT o.id, o.public_id FROM organizations o JOIN organization_members m ON m.organization_id = o.id
                      WHERE m.user_id = :id AND NOT EXISTS (SELECT 1 FROM organization_members m2 WHERE m2.organization_id = o.id AND m2.user_id <> :id2)',
                    ['id' => $userId, 'id2' => $userId],
                );
                foreach ($orgs as $o) {
                    $orgId = TypedArray::int($o, 'id');
                    $this->db->run('DELETE FROM organizations WHERE id = :id', ['id' => $orgId]);
                    foreach (['financial_profiles', 'services', 'diagnostics', 'pricing_simulations', 'recurring_offers', 'recommendations', 'action_items', 'kpi_snapshots'] as $table) {
                        $left = TypedArray::int($this->db->fetchOne("SELECT COUNT(*) AS n FROM {$table} WHERE organization_id = :id", ['id' => $orgId]) ?? [], 'n', 0);
                        if ($left !== 0) {
                            throw new \RuntimeException('Purge incomplète.');
                        }
                    }
                    $this->db->run('INSERT INTO deletion_registry (organization_public_id, deleted_at) VALUES (:pid, :now)', ['pid' => TypedArray::string($o, 'public_id'), 'now' => $this->now()]);
                }
                $this->db->run('DELETE FROM organization_members WHERE user_id = :id', ['id' => $userId]);
                $this->db->run('DELETE FROM users WHERE id = :id', ['id' => $userId]);
            });
            $count++;
        }
        if ($count > 0) {
            $this->events->record('account.purged', context: ['count' => $count]); // anonymisé : ni identifiant ni email
        }

        return $count;
    }

    private function checkPassword(int $userId, string $password): bool
    {
        $row = $this->db->fetchOne('SELECT password_hash FROM users WHERE id = :id AND is_demo = 0', ['id' => $userId]);
        $hash = $row === null ? null : TypedArray::nullableString($row, 'password_hash');

        return $this->passwords->verify($password, $hash ?? '$2y$12$' . str_repeat('a', 53)) && $hash !== null;
    }

    private function issueToken(int $userId, string $purpose): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->run('UPDATE account_tokens SET used_at = :now WHERE user_id = :id AND purpose = :p AND used_at IS NULL', ['now' => $this->now(), 'id' => $userId, 'p' => $purpose]);
        $this->db->run(
            'INSERT INTO account_tokens (user_id, purpose, token_hash, expires_at, created_at) VALUES (:id, :p, :hash, :exp, :now)',
            ['id' => $userId, 'p' => $purpose, 'hash' => hash('sha256', $token, true), 'exp' => $this->clock->nowUtc()->modify(self::TOKEN_TTL)->format('Y-m-d H:i:s'), 'now' => $this->now()],
        );

        return $token;
    }

    private function tokenUser(string $token, string $purpose): ?int
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT user_id FROM account_tokens WHERE token_hash = :hash AND purpose = :p AND used_at IS NULL AND expires_at > :now',
            ['hash' => hash('sha256', $token, true), 'p' => $purpose, 'now' => $this->now()],
        );

        return $row === null ? null : TypedArray::int($row, 'user_id');
    }

    private function consumeToken(string $token, string $purpose): ?int
    {
        $userId = $this->tokenUser($token, $purpose);
        if ($userId === null) {
            return null;
        }
        $consumed = $this->db->run(
            'UPDATE account_tokens SET used_at = :now WHERE token_hash = :hash AND purpose = :p AND used_at IS NULL',
            ['now' => $this->now(), 'hash' => hash('sha256', $token, true), 'p' => $purpose],
        )->rowCount();

        return $consumed === 1 ? $userId : null;
    }

    private function notify(int $userId, string $subject, string $body): void
    {
        $email = $this->db->fetchOne('SELECT email FROM users WHERE id = :id', ['id' => $userId]);
        if ($email !== null) {
            $this->mailer->send(new Message(TypedArray::string($email, 'email'), $subject, $body));
        }
    }

    private function now(): string
    {
        return $this->clock->nowUtc()->format('Y-m-d H:i:s');
    }
}
