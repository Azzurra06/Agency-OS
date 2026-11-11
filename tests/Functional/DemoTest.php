<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Infrastructure\Database\OrgId;
use App\Support\FrozenClock;
use Tests\Support\DatabaseTestCase;

final class DemoTest extends DatabaseTestCase
{
    private const IP = '203.0.113.20';

    public function testStartCreatesIsolatedFictitiousSpaceAndLogsIn(): void
    {
        self::assertSame('ok', $this->app->demos()->start(self::IP, 'Mozilla/5.0'));
        $orgId = (int) $_SESSION['organization_id'];
        self::assertGreaterThan(0, $orgId);
        self::assertSame(1, $_SESSION['session_epoch']);

        $org = $this->app->db()->fetchOne('SELECT name, is_demo, demo_expires_at FROM organizations WHERE id = :id', ['id' => $orgId]);
        self::assertSame('Agence Lumen', $org['name']);
        self::assertSame(1, (int) $org['is_demo']);
        self::assertSame('2026-11-13 09:00:00', $org['demo_expires_at'], 'Expiration à 24 h.');

        $derived = $this->app->financialProfiles()->derived(new OrgId($orgId));
        self::assertSame('24400.00', $derived['estimated_result']['value']);
        self::assertSame('67.01', $derived['hourly_cost']['value']);
        self::assertSame(100, $derived['completeness']['rate']);
        self::assertCount(5, $this->app->serviceRepository()->all(new OrgId($orgId)));
        self::assertSame(1, $this->countRows('security_events', "event = 'demo.created'"));

        $user = $this->app->db()->fetchOne('SELECT email, password_hash, is_demo FROM users WHERE id = :id', ['id' => $_SESSION['user_id']]);
        self::assertNull($user['password_hash'], 'Un compte de démo ne peut pas se connecter par mot de passe.');
        self::assertStringEndsWith('@demo.invalid', (string) $user['email']);
    }

    public function testRateLimitPerIp(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertSame('ok', $this->app->demos()->start(self::IP, null));
        }
        self::assertSame('rate_limited', $this->app->demos()->start(self::IP, null));
        self::assertSame('ok', $this->app->demos()->start('203.0.113.99', null));
    }

    public function testResetRestoresDataWithoutExtendingExpiry(): void
    {
        $this->app->demos()->start(self::IP, null);
        $org = new OrgId((int) $_SESSION['organization_id']);
        $this->app->financialProfiles()->update($org, ['revenue' => '1.00'], (int) $_SESSION['user_id']);
        $before = $this->app->db()->fetchOne('SELECT demo_expires_at FROM organizations WHERE id = :id', ['id' => $org->value]);

        $clock = $this->app->clock();
        self::assertInstanceOf(FrozenClock::class, $clock);
        $clock->advance('+3 hours');
        $this->app->demos()->reset($org, (int) $_SESSION['user_id']);

        self::assertSame('180000', $this->app->financialProfiles()->get($org)->revenue?->toString());
        self::assertCount(5, $this->app->serviceRepository()->all($org));
        self::assertSame($before, $this->app->db()->fetchOne('SELECT demo_expires_at FROM organizations WHERE id = :id', ['id' => $org->value]));
    }

    public function testExpiredDemosArePurgedCompletely(): void
    {
        $this->app->demos()->start(self::IP, null);
        $expiredOrg = (int) $_SESSION['organization_id'];
        $expiredUser = (int) $_SESSION['user_id'];
        $clock = $this->app->clock();
        self::assertInstanceOf(FrozenClock::class, $clock);
        $clock->advance('+23 hours');
        $this->app->demos()->start('203.0.113.30', null);
        $liveOrg = (int) $_SESSION['organization_id'];

        $clock->advance('+2 hours'); // la première a expiré, pas la seconde
        self::assertSame(1, $this->app->demos()->purgeExpired());

        foreach (['organization_members', 'organization_settings', 'financial_profiles', 'financial_profile_changes', 'services'] as $table) {
            self::assertSame(0, $this->countRows($table, 'organization_id = :o', ['o' => $expiredOrg]), $table);
            self::assertGreaterThan(0, $this->countRows($table, 'organization_id = :o', ['o' => $liveOrg]), $table . ' (démo active intacte)');
        }
        self::assertSame(0, $this->countRows('organizations', 'id = :o', ['o' => $expiredOrg]));
        self::assertSame(0, $this->countRows('users', 'id = :u', ['u' => $expiredUser]), 'Utilisateur de démo orphelin supprimé.');
        self::assertSame(1, $this->countRows('security_events', "event = 'demo.purged'"));
    }

    public function testFallbackPurgeRunsOnDemoCreation(): void
    {
        $this->app->demos()->start(self::IP, null);
        $old = (int) $_SESSION['organization_id'];
        $clock = $this->app->clock();
        self::assertInstanceOf(FrozenClock::class, $clock);
        $clock->advance('+25 hours');
        $this->app->demos()->start('203.0.113.31', null); // sans cron : purge de secours
        self::assertSame(0, $this->countRows('organizations', 'id = :o', ['o' => $old]));
    }

    public function testDemoServiceQuota(): void
    {
        $this->app->demos()->start(self::IP, null);
        $org = new OrgId((int) $_SESSION['organization_id']);
        for ($i = 0; $i < 25; $i++) {
            $this->app->catalog()->create($org, ['name' => 'Prestation ' . $i, 'category' => 'other'], true);
        }
        $this->expectException(\App\Application\ValidationException::class);
        $this->app->catalog()->create($org, ['name' => 'De trop', 'category' => 'other'], true);
    }
}
