<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\ValidationException;
use App\Infrastructure\Database\OrgId;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;

final class FinancialProfileTest extends DatabaseTestCase
{
    /** @return array<string, mixed> */
    private static function lumenInput(): array
    {
        return [
            'period_type' => 'closed_fiscal_year', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31',
            'revenue' => '180000.00', 'fixed_costs' => '30000.00', 'personnel_costs' => '60000.00',
            'variable_costs_attributable' => '12000.00', 'variable_costs_general' => '3600.00',
            'owner_comp_total_cost' => '50000.00', 'owner_comp_included_amount' => '0.00',
            'production_fte' => '2.00', 'billable_rate' => '65.00', 'billable_rate_is_assumption' => true,
        ];
    }

    public function testSensitiveAmountsAreEncryptedAtRest(): void
    {
        $member = Fixtures::member($this->app->db(), 'a@example.test');
        $org = new OrgId($member['org_id']);
        $this->app->financialProfiles()->update($org, self::lumenInput(), $member['user_id']);

        $raw = $this->app->db()->fetchOne('SELECT * FROM financial_profiles WHERE organization_id = :o', ['o' => $member['org_id']]);
        self::assertNotNull($raw);
        $dump = json_encode($raw, JSON_THROW_ON_ERROR);
        foreach (['180000', '30000', '60000', '50000'] as $amount) {
            self::assertStringNotContainsString($amount, $dump, 'Montant en clair dans la base.');
        }
        self::assertStringStartsWith('k1.gcm:', (string) $raw['revenue_enc']);

        $derived = $this->app->financialProfiles()->derived($org);
        self::assertSame('24400.00', $derived['estimated_result']['value']);
        self::assertSame('67.01', $derived['hourly_cost']['value']);
        self::assertSame(['annual_hours_per_fte', 'billable_rate'], $derived['hourly_cost']['assumptions']);
        self::assertSame('2.00', $derived['general_variable_rate']['value']);
        self::assertSame('Exercice clos · 01/01/2025 → 31/12/2025 · 12 mois', $derived['period']['label']);
    }

    public function testJournalRecordsOnlyRealChangesEncrypted(): void
    {
        $member = Fixtures::member($this->app->db(), 'a@example.test');
        $org = new OrgId($member['org_id']);
        $service = $this->app->financialProfiles();
        $service->update($org, self::lumenInput(), $member['user_id'], 'onboarding');
        $initial = $this->countRows('financial_profile_changes');
        self::assertGreaterThan(5, $initial);

        $service->update($org, ['revenue' => '180000', 'fixed_costs' => '30000.00'], $member['user_id']); // valeurs identiques
        self::assertSame($initial, $this->countRows('financial_profile_changes'), 'Aucun changement réel : rien à journaliser.');

        $service->update($org, ['revenue' => '200000.00'], $member['user_id']);
        $changes = $this->app->financialProfiles()->get($org);
        self::assertSame('200000', $changes->revenue?->toString());
        $journal = (new \App\Infrastructure\Repositories\FinancialProfileRepository($this->app->db(), $this->app->fieldCrypto(), $this->app->clock()))->changes($org, 1);
        self::assertSame('revenue', $journal[0]['field']);
        self::assertSame('180000', $journal[0]['old']);
        self::assertSame('200000', $journal[0]['new']);
        self::assertSame('settings', $journal[0]['origin']);

        $raw = json_encode($this->app->db()->fetchAll('SELECT old_value_enc, new_value_enc FROM financial_profile_changes'), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('200000', $raw);
    }

    public function testValidationAndCoherence(): void
    {
        $member = Fixtures::member($this->app->db(), 'a@example.test');
        $org = new OrgId($member['org_id']);
        $cases = [
            [['revenue' => 180000], 'revenue', 'decimal_must_be_string'],
            [['revenue' => '-5'], 'revenue', 'too_small'],
            [['billable_rate' => '150'], 'billable_rate', 'too_large'],
            [['organization_id' => 2], 'organization_id', 'unknown_field'],
            [['period_start' => '2025-01-01', 'period_end' => '2024-01-01'], 'period_end', 'invalid_period'],
            [['period_start' => '2025-01-01'], 'period_end', 'required'],
            [['owner_comp_total_cost' => '10000', 'owner_comp_included_amount' => '20000'], 'owner_comp_included_amount', 'exceeds_owner_total'],
        ];
        foreach ($cases as [$input, $field, $code]) {
            try {
                $this->app->financialProfiles()->update($org, $input, $member['user_id']);
                self::fail('Validation attendue pour ' . $field);
            } catch (ValidationException $e) {
                self::assertSame($code, $e->errors[$field] ?? null, json_encode($e->errors));
            }
        }
        self::assertSame(0, $this->countRows('financial_profiles'), 'Rien n\'est enregistré en cas d\'erreur.');
    }

    public function testProfilesAreIsolatedBetweenOrganisations(): void
    {
        $a = Fixtures::member($this->app->db(), 'a@example.test');
        $b = Fixtures::member($this->app->db(), 'b@example.test');
        $this->app->financialProfiles()->update(new OrgId($a['org_id']), self::lumenInput(), $a['user_id']);
        self::assertNull($this->app->financialProfiles()->get(new OrgId($b['org_id']))->revenue);

        // Valeur chiffrée recopiée de A vers B : refusée au déchiffrement (AAD liée à l'organisation).
        $this->app->financialProfiles()->update(new OrgId($b['org_id']), ['revenue' => '1.00'], $b['user_id']);
        $this->app->db()->run(
            'UPDATE financial_profiles b JOIN financial_profiles a ON a.organization_id = :a SET b.revenue_enc = a.revenue_enc WHERE b.organization_id = :b',
            ['a' => $a['org_id'], 'b' => $b['org_id']],
        );
        $this->expectException(\App\Security\Crypto\IntegrityException::class);
        $this->app->financialProfiles()->get(new OrgId($b['org_id']));
    }
}
