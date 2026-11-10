<?php

declare(strict_types=1);

namespace App\Security;

use App\Infrastructure\Database\Connection;
use App\Support\Clock;
use App\Support\Logger;
use InvalidArgumentException;
use Throwable;

/**
 * Journal de sécurité en base (table security_events, insertion seule — docs/04-securite.md §10.1).
 * Le contexte passe par une liste blanche : jamais de mot de passe, jeton, cookie, montant ni email en clair.
 */
class SecurityEventLogger
{
    private const EVENTS = [
        'auth.login_succeeded', 'auth.login_failed', 'auth.locked', 'auth.logout', 'auth.password_changed',
        'auth.password_reset_requested', 'auth.password_reset_completed', 'auth.email_verified', 'auth.sessions_revoked',
        'authz.denied', 'authz.foreign_resource', 'csrf.rejected', 'ratelimit.exceeded', 'request.too_large',
        'account.export', 'account.deletion_requested', 'account.deletion_cancelled', 'account.purged',
        'finance.profile_changed', 'data.deleted', 'demo.created', 'demo.purged', 'backup.failed',
        'cron.failed', 'config.drift', 'crypto.integrity_failure',
    ];

    private const CONTEXT_KEYS = ['route', 'method', 'reason', 'bucket', 'resource', 'resource_public_id', 'job', 'count', 'email_hmac'];

    public function __construct(
        private readonly Connection $db,
        private readonly Logger $logger,
        private readonly Clock $clock = new Clock(),
    ) {
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function record(
        string $event,
        ?int $userId = null,
        ?int $organizationId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $requestId = null,
        array $context = [],
    ): void {
        if (!in_array($event, self::EVENTS, true)) {
            throw new InvalidArgumentException('Événement de sécurité inconnu.');
        }
        $clean = array_intersect_key($context, array_flip(self::CONTEXT_KEYS));
        $packedIp = $ip !== null ? @inet_pton($ip) : false;

        try {
            $this->db->run(
                'INSERT INTO security_events (event, user_id, organization_id, ip, user_agent_hash, request_id, context, created_at)
                 VALUES (:event, :user, :org, :ip, :ua, :request, :context, :now)',
                [
                    'event' => $event,
                    'user' => $userId,
                    'org' => $organizationId,
                    'ip' => $packedIp === false ? null : $packedIp,
                    'ua' => $userAgent === null ? null : hash('sha256', $userAgent, true),
                    'request' => $requestId,
                    'context' => $clean === [] ? null : json_encode($clean, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'now' => $this->clock->nowUtc()->format('Y-m-d H:i:s'),
                ],
            );
        } catch (Throwable $e) {
            // Ne jamais faire échouer la requête pour un défaut de journalisation : trace technique uniquement.
            $this->logger->error('Échec d\'écriture du journal de sécurité', ['event' => $event, 'exception' => $e::class]);
        }
    }

    public function purgeOlderThanMonths(int $months = 6): int
    {
        $before = $this->clock->nowUtc()->modify(sprintf('-%d months', $months))->format('Y-m-d H:i:s');

        return $this->db->run('DELETE FROM security_events WHERE created_at < :before', ['before' => $before])->rowCount();
    }
}
