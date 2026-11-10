<?php

declare(strict_types=1);

namespace App\Security;

use App\Support\TypedArray;
use App\Infrastructure\Database\Connection;
use App\Support\Clock;

/**
 * Limitation des tentatives par fenêtre glissante (docs/04-securite.md §3.3 et §5).
 * Les clés (email, IP…) sont hachées par HMAC avec APP_KEY : aucune donnée en clair n'est stockée.
 */
class RateLimiter
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $appKey,
        private readonly Clock $clock = new Clock(),
    ) {
    }

    /** Nombre de tentatives enregistrées dans la fenêtre. */
    public function attempts(string $bucket, string $key, int $windowSeconds): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS n FROM rate_limit_hits WHERE bucket = :bucket AND key_hash = :key AND hit_at > :since',
            ['bucket' => $bucket, 'key' => $this->hashKey($bucket, $key), 'since' => $this->since($windowSeconds)],
        );

        return TypedArray::int($row ?? [], 'n', 0);
    }

    public function tooManyAttempts(string $bucket, string $key, int $limit, int $windowSeconds): bool
    {
        return $this->attempts($bucket, $key, $windowSeconds) >= $limit;
    }

    public function hit(string $bucket, string $key): void
    {
        $this->db->run(
            'INSERT INTO rate_limit_hits (bucket, key_hash, hit_at) VALUES (:bucket, :key, :now)',
            ['bucket' => $bucket, 'key' => $this->hashKey($bucket, $key), 'now' => $this->clock->nowUtc()->format('Y-m-d H:i:s')],
        );
    }

    public function clear(string $bucket, string $key): void
    {
        $this->db->run(
            'DELETE FROM rate_limit_hits WHERE bucket = :bucket AND key_hash = :key',
            ['bucket' => $bucket, 'key' => $this->hashKey($bucket, $key)],
        );
    }

    /** Secondes à attendre avant que la fenêtre libère une tentative (en-tête Retry-After). */
    public function retryAfter(string $bucket, string $key, int $windowSeconds): int
    {
        $row = $this->db->fetchOne(
            'SELECT MIN(hit_at) AS oldest FROM rate_limit_hits WHERE bucket = :bucket AND key_hash = :key AND hit_at > :since',
            ['bucket' => $bucket, 'key' => $this->hashKey($bucket, $key), 'since' => $this->since($windowSeconds)],
        );
        $oldestAt = $row === null ? null : TypedArray::nullableString($row, 'oldest');
        if ($oldestAt === null) {
            return 0;
        }
        $oldest = strtotime($oldestAt . ' UTC');

        return max(1, ($oldest === false ? 0 : $oldest) + $windowSeconds - $this->clock->nowUtc()->getTimestamp());
    }

    /** Purge des tentatives de plus de 24 h (tâche quotidienne). */
    public function purgeOlderThan(int $seconds = 86400): int
    {
        return $this->db->run('DELETE FROM rate_limit_hits WHERE hit_at < :before', ['before' => $this->since($seconds)])->rowCount();
    }

    private function hashKey(string $bucket, string $key): string
    {
        return hash_hmac('sha256', $bucket . '|' . mb_strtolower($key), $this->appKey, true);
    }

    private function since(int $windowSeconds): string
    {
        return $this->clock->nowUtc()->modify(sprintf('-%d seconds', $windowSeconds))->format('Y-m-d H:i:s');
    }
}
