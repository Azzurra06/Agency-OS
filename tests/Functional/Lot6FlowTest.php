<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Http\Response;
use App\Infrastructure\Database\OrgId;
use Tests\Support\DatabaseTestCase;
use Tests\Support\RequestFactory;
use Tests\Support\TestApp;

/**
 * Parcours du lot 6 : instantanés quotidiens et historique (jours manquants), pages publiques, compte,
 * export RGPD, suppression du compte en deux temps (D8) avec annulation et purge à J+7.
 */
final class Lot6FlowTest extends DatabaseTestCase
{
    private const ORIGIN = ['Origin' => 'https://agency-os.test'];
    private const EMAIL = 'claire@example.test';

    private string $password = '';

    private function send(string $method, string $path, ?array $json = null, array $form = []): Response
    {
        $headers = self::ORIGIN;
        if (!isset($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = str_repeat('e', 64);
        }
        $headers['X-CSRF-Token'] = (string) $_SESSION['_csrf'];
        if ($form !== []) {
            $form['_csrf'] = (string) $_SESSION['_csrf'];
        }
        $body = '';
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($json === [] ? new \stdClass() : $json, JSON_THROW_ON_ERROR);
        }
        $headers['Cookie'] = $this->app->sessions()->cookieName() . '=test';

        return $this->app->kernel()->handle(RequestFactory::make($method, $path, $headers, $body, $form));
    }

    /** Compte de test réel (mot de passe généré à l'exécution), connecté, avec un profil. */
    private function account(): array
    {
        $this->password = bin2hex(random_bytes(10));
        $created = $this->app->auth()->createTestAccount(self::EMAIL, $this->password, 'Agence Claire');
        $this->login();
        $this->app->organizations()->setOnboardingStep(new OrgId((int) $_SESSION['organization_id']), 3, true);
        $this->send('PUT', '/api/v1/financial-profile', ['period_type' => 'closed_fiscal_year', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'revenue' => '180000.00']);

        return $created;
    }

    private function login(?string $password = null): Response
    {
        $_SESSION = ['_csrf' => str_repeat('e', 64)];
        $response = $this->send('POST', '/connexion', form: ['email' => self::EMAIL, 'password' => $password ?? $this->password]);
        $_SESSION['_csrf'] = str_repeat('e', 64);

        return $response;
    }

    /** @return list<array<string, mixed>> emails capturés */
    private function mails(): array
    {
        $files = glob($this->app->config->storagePath('mail-capture') . '/*.json') ?: [];
        sort($files);

        return array_map(static fn (string $f): array => json_decode((string) file_get_contents($f), true, 8, JSON_THROW_ON_ERROR), $files);
    }

    private function clearMails(): void
    {
        foreach (glob($this->app->config->storagePath('mail-capture') . '/*.json') ?: [] as $file) {
            unlink($file);
        }
    }

    private function lastLink(string $prefix): string
    {
        foreach (array_reverse($this->mails()) as $mail) {
            if (preg_match('#' . preg_quote($prefix, '#') . '([0-9a-f]{64})#', (string) json_encode($mail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $m) === 1) {
                return $m[1];
            }
        }
        self::fail('Lien introuvable : ' . $prefix);
    }

    public function testDailySnapshotsAndHistoryWithMissingDays(): void
    {
        $this->account();
        $results = $this->app->cronRunner()->runDue();
        self::assertSame(1, $this->countRows('kpi_snapshots'), 'Une organisation réelle, la démo exclue.');
        $raw = $this->app->db()->fetchOne('SELECT revenue_annualized_enc, snapshot_date FROM kpi_snapshots');
        self::assertStringStartsWith('k1.gcm:', (string) $raw['revenue_annualized_enc']);
        self::assertStringNotContainsString('180000', (string) $raw['revenue_annualized_enc']);
        self::assertSame('2026-11-12', $raw['snapshot_date'], 'Date de Paris.');
        $this->app->db()->run("DELETE FROM cron_runs WHERE job = 'snapshot-daily'");
        $this->app->cronRunner()->runDue();
        self::assertSame(1, $this->countRows('kpi_snapshots'), 'Jamais de doublon ni d\'écrasement.');
        self::assertNotEmpty($results);

        $history = json_decode($this->send('GET', '/api/v1/dashboard/history?days=7')->body, true)['data'];
        self::assertCount(7, $history['days']);
        self::assertSame(['date' => '2026-11-06', 'missing' => true], $history['days'][0], 'Jour sans instantané : aucune valeur inventée.');
        self::assertSame('180000.00', $history['days'][6]['revenue_annualized']);
        self::assertNull($history['days'][6]['mrr_contracted'], 'Non calculable : NULL, jamais 0.');
        self::assertStringContainsString('chart-history', $this->send('GET', '/app?jours=7')->body);
    }

    public function testDemoHistoryIsFictiveWithGaps(): void
    {
        $_SESSION = ['_csrf' => str_repeat('e', 64)];
        self::assertSame(303, $this->send('POST', '/demo', form: ['x' => '1'])->status);
        $_SESSION['_csrf'] = str_repeat('e', 64);
        $history = json_decode($this->send('GET', '/api/v1/dashboard/history?days=30')->body, true)['data'];
        self::assertSame(28, $history['count']);
        self::assertCount(2, $history['missing']);
        $body = $this->send('GET', '/app')->body;
        self::assertStringContainsString('Historique fictif', $body);
        self::assertStringContainsString('2 jour(s) sans donnée', $body);
        foreach (['/app/parametres/compte', '/app/parametres/securite', '/app/parametres/donnees'] as $path) {
            self::assertStringContainsString('Non disponible en démonstration', $this->send('GET', $path)->body, $path);
        }
        self::assertSame(403, $this->send('POST', '/app/parametres/donnees/export', form: ['password' => 'x'])->status);
    }

    public function testPublicInformationPages(): void
    {
        $_SESSION = [];
        foreach (['/methodologie' => 'Je ne sais pas', '/mentions-legales' => 'Hostinger International', '/confidentialite' => 'Vos droits'] as $path => $text) {
            $response = $this->app->kernel()->handle(RequestFactory::make('GET', $path));
            self::assertSame(200, $response->status, $path);
            self::assertStringContainsString($text, $response->body);
            self::assertNull($response->header('Set-Cookie'), 'Page publique : aucun cookie.');
        }
        self::assertStringContainsString('à compléter', $this->app->kernel()->handle(RequestFactory::make('GET', '/mentions-legales'))->body, 'Éditeur non configuré : signalé.');
        $configured = TestApp::make(['LEGAL_PUBLISHER' => 'Éditrice Test', 'LEGAL_CONTACT_EMAIL' => 'contact@example.test']);
        $body = $configured->kernel()->handle(RequestFactory::make('GET', '/mentions-legales'))->body;
        self::assertStringContainsString('Éditrice Test', $body);
        self::assertStringNotContainsString('à compléter avant la mise en ligne (configuration', $body);
        self::assertStringContainsString('Évaluable (0 point)', $this->app->kernel()->handle(RequestFactory::make('GET', '/methodologie'))->body);
    }

    public function testPasswordChangeAndExport(): void
    {
        $this->account();
        self::assertSame('/app/parametres/compte', $this->send('POST', '/app/parametres/compte/mot-de-passe', form: ['current_password' => 'mauvais', 'new_password' => 'x', 'new_password_confirmation' => 'x'])->header('Location'));
        self::assertSame('Mot de passe actuel incorrect.', $_SESSION['_flash']['message']);
        $new = bin2hex(random_bytes(10));
        $this->send('POST', '/app/parametres/compte/mot-de-passe', form: ['current_password' => $this->password, 'new_password' => $new, 'new_password_confirmation' => $new]);
        self::assertSame('success', $_SESSION['_flash']['type']);
        self::assertSame(200, $this->send('GET', '/app/parametres/compte')->status, 'La session courante reste valide.');
        $this->password = $new;
        self::assertSame('/app', $this->login()->header('Location'));

        // Export : mot de passe requis, document en pièce jointe, journalisé, notifié.
        self::assertSame('/app/parametres/donnees', $this->send('POST', '/app/parametres/donnees/export', form: ['password' => 'mauvais'])->header('Location'));
        $export = $this->send('POST', '/app/parametres/donnees/export', form: ['password' => $this->password]);
        self::assertSame(200, $export->status);
        self::assertStringContainsString('attachment; filename="agency-os-export-2026-11-12.json"', (string) $export->header('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $export->header('Cache-Control'));
        $data = json_decode($export->body, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(self::EMAIL, $data['account']['email']);
        self::assertSame('180000.00', $data['financial_profile']['values']['revenue'], 'Valeurs déchiffrées dans l\'export.');
        self::assertArrayNotHasKey('password_hash', $data['account']);
        self::assertStringNotContainsString('password_hash', $export->body);
        self::assertSame(1, $this->countRows('security_events', "event = 'account.export'"));
        self::assertStringContainsString('Export de vos données', (string) json_encode($this->mails(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        for ($i = 0; $i < 4; $i++) {
            $this->send('POST', '/app/parametres/donnees/export', form: ['password' => $this->password]);
        }
        self::assertSame(303, $this->send('POST', '/app/parametres/donnees/export', form: ['password' => $this->password])->status, '5 exports par heure au maximum.');
        self::assertStringContainsString('5 exports par heure', $_SESSION['_flash']['message']);
    }

    public function testAccountDeletionWithCancellationThenPurge(): void
    {
        $created = $this->account();
        // Étape 1 : mot de passe + mot de confirmation → lien par email ; rien n'est encore désactivé.
        $this->clearMails();
        $this->send('POST', '/app/parametres/donnees/suppression', form: ['password' => $this->password, 'confirm' => 'non']);
        self::assertSame('error', $_SESSION['_flash']['type']);
        $this->send('POST', '/app/parametres/donnees/suppression', form: ['password' => $this->password, 'confirm' => 'SUPPRIMER']);
        self::assertSame('info', $_SESSION['_flash']['type']);
        self::assertSame('active', $this->app->db()->fetchOne('SELECT status FROM users WHERE email = :e', ['e' => self::EMAIL])['status']);
        $token = $this->lastLink('/compte/suppression/');

        // Étape 2 : le lien affiche une confirmation (GET sans effet), le POST désactive immédiatement.
        $_SESSION = ['_csrf' => str_repeat('e', 64)];
        self::assertStringContainsString('Confirmer la suppression', $this->send('GET', '/compte/suppression/' . $token)->body);
        self::assertSame('active', $this->app->db()->fetchOne('SELECT status FROM users WHERE email = :e', ['e' => self::EMAIL])['status']);
        $done = $this->send('POST', '/compte/suppression', form: ['token' => $token]);
        self::assertStringContainsString('Votre compte est désactivé', $done->body);
        self::assertSame('deletion_scheduled', $this->app->db()->fetchOne('SELECT status FROM users WHERE email = :e', ['e' => self::EMAIL])['status']);
        self::assertStringContainsString('invalide', $this->send('POST', '/compte/suppression', form: ['token' => $token])->body, 'Lien à usage unique.');

        // Plus aucun accès ; la connexion avec le bon mot de passe envoie un lien d'annulation.
        $this->clearMails();
        $login = $this->login();
        self::assertSame('/connexion', $login->header('Location'));
        self::assertStringContainsString('en cours de suppression', $_SESSION['_flash']['message']);
        self::assertSame('/connexion', $this->send('GET', '/app')->header('Location'));
        $cancel = $this->lastLink('/compte/annulation/');
        $_SESSION = ['_csrf' => str_repeat('e', 64)];
        self::assertStringContainsString('réactivé', $this->send('POST', '/compte/annulation', form: ['token' => $cancel])->body);
        self::assertSame('/app', $this->login()->header('Location'), 'Annulation : accès rétabli.');

        // Nouvelle demande confirmée, puis purge à J+7 par la maintenance quotidienne.
        $this->clearMails();
        $this->send('POST', '/app/parametres/donnees/suppression', form: ['password' => $this->password, 'confirm' => 'SUPPRIMER']);
        $this->send('POST', '/compte/suppression', form: ['token' => $this->lastLink('/compte/suppression/')]);
        self::assertSame(0, $this->app->account()->purgeScheduled(), 'Avant J+7 : rien n\'est supprimé.');
        $later = TestApp::make([], '2026-11-19 10:00:00');
        self::assertSame(1, $later->account()->purgeScheduled());
        self::assertSame(0, $this->countRows('users', 'email = :e', ['e' => self::EMAIL]));
        self::assertSame(0, $this->countRows('organizations', 'id = :id', ['id' => $created['organization_id'] ?? 0]));
        self::assertSame(0, $this->countRows('financial_profiles'));
        self::assertSame(1, $this->countRows('deletion_registry'));
        $event = $this->app->db()->fetchOne("SELECT user_id, context FROM security_events WHERE event = 'account.purged'");
        self::assertNull($event['user_id'], 'Événement anonymisé.');
        self::assertStringNotContainsString(self::EMAIL, (string) json_encode($event));
    }
}
