<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Support\FrozenClock;
use Tests\Support\DatabaseTestCase;

final class AuthTest extends DatabaseTestCase
{
    private const IP = '203.0.113.40';
    private const PASSWORD = 'mot-de-passe-de-test-123';

    private function account(bool $verified = true): array
    {
        return $this->app->auth()->createTestAccount('claire@example.test', self::PASSWORD, 'Agence de test', $verified);
    }

    /** @return list<string> */
    private function capturedLinks(string $prefix): array
    {
        $links = [];
        foreach (glob($this->app->config->storagePath('mail-capture') . '/*.json') ?: [] as $file) {
            $mail = json_decode((string) file_get_contents($file), true, 4, JSON_THROW_ON_ERROR);
            if (preg_match('#https://agency-os\.test/' . $prefix . '/([0-9a-f]{64})#', $mail['body'], $m)) {
                $links[] = $m[1];
            }
        }

        return $links;
    }

    public function testSuccessfulLoginRegeneratesSessionAndRecordsEvent(): void
    {
        $account = $this->account();
        $_SESSION['_csrf'] = 'ancien-jeton';
        self::assertSame('ok', $this->app->auth()->attempt('Claire@Example.test', self::PASSWORD, self::IP, 'UA'));
        self::assertSame($account['user_id'], $_SESSION['user_id']);
        self::assertSame($account['organization_id'], $_SESSION['organization_id']);
        self::assertArrayNotHasKey('_csrf', $_SESSION, 'Jeton CSRF renouvelé à la connexion.');
        self::assertSame(1, $this->countRows('security_events', "event = 'auth.login_succeeded'"));
        $hash = (string) $this->app->db()->fetchOne('SELECT password_hash FROM users WHERE id = :id', ['id' => $account['user_id']])['password_hash'];
        self::assertStringStartsWith('$2y$12$', $hash);
    }

    public function testFailuresAreGenericAndLockAfterFiveAttempts(): void
    {
        $this->account();
        self::assertSame('invalid', $this->app->auth()->attempt('inconnu@example.test', self::PASSWORD, self::IP, null));
        for ($i = 0; $i < 5; $i++) {
            self::assertSame('invalid', $this->app->auth()->attempt('claire@example.test', 'mauvais-mot-de-passe', self::IP, null));
        }
        // Bloqué même avec le bon mot de passe (pas d'oracle).
        self::assertSame('locked', $this->app->auth()->attempt('claire@example.test', self::PASSWORD, self::IP, null));
        self::assertArrayNotHasKey('user_id', $_SESSION);
        // Depuis une autre IP, la limite par couple email+IP ne s'applique pas encore.
        self::assertSame('ok', $this->app->auth()->attempt('claire@example.test', self::PASSWORD, '203.0.113.41', null));

        $context = (string) $this->app->db()->fetchOne("SELECT context FROM security_events WHERE event = 'auth.login_failed' LIMIT 1")['context'];
        self::assertStringNotContainsString('claire', $context, 'Email jamais en clair dans le journal.');
    }

    public function testLockExpiresAfterWindow(): void
    {
        $this->account();
        for ($i = 0; $i < 5; $i++) {
            $this->app->auth()->attempt('claire@example.test', 'mauvais-mot-de-passe', self::IP, null);
        }
        $clock = $this->app->clock();
        self::assertInstanceOf(FrozenClock::class, $clock);
        $clock->advance('+16 minutes');
        self::assertSame('ok', $this->app->auth()->attempt('claire@example.test', self::PASSWORD, self::IP, null));
    }

    public function testUnverifiedAndDemoAccountsCannotLogIn(): void
    {
        $this->account(false);
        self::assertSame('invalid', $this->app->auth()->attempt('claire@example.test', self::PASSWORD, self::IP, null));

        $this->app->demos()->start('203.0.113.50', null);
        $email = (string) $this->app->db()->fetchOne('SELECT email FROM users WHERE is_demo = 1')['email'];
        $_SESSION = [];
        self::assertSame('invalid', $this->app->auth()->attempt($email, '', '203.0.113.51', null));
    }

    public function testPasswordResetIsSingleUseExpiresAndRevokesSessions(): void
    {
        $account = $this->account();
        $this->app->auth()->requestPasswordReset('claire@example.test', self::IP);
        $this->app->auth()->requestPasswordReset('inconnu@example.test', self::IP); // aucune différence observable, aucun email
        $tokens = $this->capturedLinks('reinitialisation');
        self::assertCount(1, $tokens);
        self::assertSame(0, $this->countRows('password_resets', 'token_hash = :t', ['t' => $tokens[0]]), 'Seule l\'empreinte du jeton est stockée.');

        self::assertSame('password_too_short', $this->app->auth()->resetPassword($tokens[0], 'court', self::IP));
        self::assertSame('ok', $this->app->auth()->resetPassword($tokens[0], 'nouveau-mot-de-passe-456', self::IP));
        self::assertSame('invalid_token', $this->app->auth()->resetPassword($tokens[0], 'encore-un-autre-789', self::IP), 'Usage unique.');
        self::assertSame(2, (int) $this->app->db()->fetchOne('SELECT session_epoch FROM users WHERE id = :id', ['id' => $account['user_id']])['session_epoch']);
        self::assertSame('ok', $this->app->auth()->attempt('claire@example.test', 'nouveau-mot-de-passe-456', self::IP, null));
        self::assertCount(1, $this->capturedLinks('reinitialisation'));
    }

    public function testResetTokenExpiresAndNewRequestInvalidatesPrevious(): void
    {
        $this->account();
        $this->app->auth()->requestPasswordReset('claire@example.test', self::IP);
        $first = $this->capturedLinks('reinitialisation')[0];
        $clock = $this->app->clock();
        self::assertInstanceOf(FrozenClock::class, $clock);
        $clock->advance('+1 minute');
        $this->app->auth()->requestPasswordReset('claire@example.test', self::IP);
        self::assertNull($this->app->auth()->resetTokenUser($first), 'Une nouvelle demande invalide le jeton précédent.');

        $second = array_values(array_diff($this->capturedLinks('reinitialisation'), [$first]))[0];
        self::assertNotNull($this->app->auth()->resetTokenUser($second));
        $clock->advance('+31 minutes');
        self::assertNull($this->app->auth()->resetTokenUser($second), 'Expiration à 30 minutes.');
    }

    public function testResetRequestsAreRateLimited(): void
    {
        $this->account();
        for ($i = 0; $i < 5; $i++) {
            $this->app->auth()->requestPasswordReset('claire@example.test', self::IP);
        }
        self::assertCount(3, $this->capturedLinks('reinitialisation'), '3 demandes par email et par heure.');
    }

    public function testEmailVerificationFlow(): void
    {
        $account = $this->account(false);
        self::assertTrue($this->app->auth()->sendVerification($account['user_id']));
        self::assertFalse($this->app->auth()->sendVerification($account['user_id']), 'Renvoi limité (1 toutes les 2 minutes).');
        $token = $this->capturedLinks('verification')[0];
        self::assertTrue($this->app->auth()->verifyEmail($token));
        self::assertFalse($this->app->auth()->verifyEmail($token), 'Usage unique.');
        self::assertSame('active', $this->app->db()->fetchOne('SELECT status FROM users WHERE id = :id', ['id' => $account['user_id']])['status']);
        self::assertSame('ok', $this->app->auth()->attempt('claire@example.test', self::PASSWORD, self::IP, null));
    }
}
