<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Support\FrozenClock;
use InvalidArgumentException;
use Tests\Support\DatabaseTestCase;

final class RateLimiterAndEventsTest extends DatabaseTestCase
{
    public function testSlidingWindowAndHashedKeys(): void
    {
        $limiter = $this->app->rateLimiter();
        $clock = $this->app->clock();
        self::assertInstanceOf(FrozenClock::class, $clock);

        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($limiter->tooManyAttempts('login_email_ip', 'claire@example.test|203.0.113.10', 5, 900));
            $limiter->hit('login_email_ip', 'claire@example.test|203.0.113.10');
        }
        self::assertTrue($limiter->tooManyAttempts('login_email_ip', 'claire@example.test|203.0.113.10', 5, 900));
        self::assertTrue($limiter->tooManyAttempts('login_email_ip', 'CLAIRE@example.test|203.0.113.10', 5, 900), 'Clé insensible à la casse.');
        self::assertFalse($limiter->tooManyAttempts('login_email_ip', 'autre@example.test|203.0.113.10', 5, 900));
        self::assertGreaterThan(0, $limiter->retryAfter('login_email_ip', 'claire@example.test|203.0.113.10', 900));

        // Aucune donnée en clair dans la table.
        foreach ($this->app->db()->fetchAll('SELECT key_hash FROM rate_limit_hits') as $row) {
            self::assertSame(32, strlen((string) $row['key_hash']));
            self::assertStringNotContainsString('claire', (string) $row['key_hash']);
        }

        $clock->advance('+16 minutes');
        self::assertFalse($limiter->tooManyAttempts('login_email_ip', 'claire@example.test|203.0.113.10', 5, 900));

        $clock->advance('+1 day');
        self::assertSame(5, $limiter->purgeOlderThan(86400));
    }

    public function testSecurityEventsUseWhitelistedContext(): void
    {
        $this->app->securityEvents()->record('auth.login_failed', ip: '203.0.113.10', userAgent: 'Mozilla/5.0', context: [
            'reason' => 'bad_credentials', 'password' => 'secret', 'revenue' => '180000',
        ]);
        $row = $this->app->db()->fetchOne('SELECT event, ip, user_agent_hash, context FROM security_events');
        self::assertNotNull($row);
        self::assertSame('auth.login_failed', $row['event']);
        self::assertSame('203.0.113.10', inet_ntop((string) $row['ip']));
        self::assertSame(32, strlen((string) $row['user_agent_hash']));
        self::assertSame(['reason' => 'bad_credentials'], json_decode((string) $row['context'], true));
    }

    public function testUnknownSecurityEventIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->app->securityEvents()->record('anything.goes');
    }

    public function testSecurityEventsArePurgedAfterSixMonths(): void
    {
        $this->app->securityEvents()->record('auth.logout');
        $clock = $this->app->clock();
        self::assertInstanceOf(FrozenClock::class, $clock);
        $clock->advance('+5 months');
        self::assertSame(0, $this->app->securityEvents()->purgeOlderThanMonths(6));
        $clock->advance('+2 months');
        self::assertSame(1, $this->app->securityEvents()->purgeOlderThanMonths(6));
    }
}
