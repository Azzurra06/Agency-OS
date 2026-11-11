<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\OrgId;
use App\Infrastructure\Repositories\ServiceRepository;
use App\Security\RateLimiter;
use App\Security\SecurityEventLogger;
use App\Security\SessionManager;
use App\Support\Clock;
use App\Support\Decimal;
use App\Support\PublicId;
use App\Support\TypedArray;

/**
 * Espaces de démonstration (docs/04-securite.md §11) :
 *  - une organisation et un utilisateur fictifs par visiteur, mêmes contrôles d'isolation qu'un compte réel ;
 *  - données ENTIÈREMENT fictives (agence « Lumen »), expiration après 24 h, accès refusé au-delà ;
 *  - 5 démos par IP et par heure, 500 démos actives au maximum ;
 *  - purge : tâche horaire ET purge de secours à chaque création ; suppression en cascade.
 */
final class DemoService
{
    public const LIFETIME = '+24 hours';
    public const MAX_ACTIVE = 500;
    public const PER_IP_PER_HOUR = 5;
    public const MAX_SERVICES = 30;

    /**
     * Réponses fictives de Lumen au questionnaire v1 (cas DIAG-1 de docs/03 §6.3) : score 58, forces Fidélisation
     * et Organisation, axes Stratégie et Rentabilité ; « Je ne sais pas » (inconnue) sur la dépendance au dirigeant.
     */
    public const LUMEN_DIAGNOSTIC = [
        'pos.target' => 'n2', 'pos.specialisation' => 'n2', 'pos.packaged_offers' => 'n1', 'pos.differentiation' => 'n2', 'pos.portfolio_fit' => 'n1',
        'acq.lead_sources' => 'n2', 'acq.sales_process' => 'n2', 'acq.conversion' => 'n2', 'acq.pipeline' => 'n2', 'acq.visibility' => 'n2',
        'fin.pricing_method' => 'n1', 'fin.hourly_cost' => 'n2', 'fin.time_tracking' => 'n1', 'fin.project_profitability' => 'n1', 'fin.steering' => 'n1',
        'org.production_process' => 'n3', 'org.project_tools' => 'n2', 'org.scope_changes' => 'n2', 'org.roles' => 'n2', 'org.owner_dependency' => 'unknown',
        'ret.maintenance_offer' => 'n2', 'ret.renewal' => 'n3', 'ret.contract_review' => 'n2', 'ret.upsell' => 'n3', 'ret.satisfaction' => 'n2',
        'str.objectives' => 'n1', 'str.vision' => 'n1', 'str.review' => 'n1', 'str.capacity_plan' => 'n1', 'str.market_watch' => 'n1',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly FinancialProfileService $profiles,
        private readonly ServiceRepository $services,
        private readonly DiagnosticService $diagnostics,
        private readonly PricingService $pricing,
        private readonly RateLimiter $limiter,
        private readonly SecurityEventLogger $events,
        private readonly SessionManager $sessions,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return 'ok'|'rate_limited'|'unavailable'
     */
    public function start(string $ip, ?string $userAgent): string
    {
        if ($this->limiter->tooManyAttempts('demo_ip', $ip, self::PER_IP_PER_HOUR, 3600)) {
            $this->events->record('ratelimit.exceeded', ip: $ip, context: ['bucket' => 'demo_ip']);

            return 'rate_limited';
        }
        $this->purgeExpired(20); // purge de secours, indépendante du cron
        $active = TypedArray::int($this->db->fetchOne(
            'SELECT COUNT(*) AS n FROM organizations WHERE is_demo = 1 AND demo_expires_at > :now',
            ['now' => $this->now()],
        ) ?? [], 'n', 0);
        if ($active >= self::MAX_ACTIVE) {
            return 'unavailable';
        }
        $this->limiter->hit('demo_ip', $ip);

        [$userId, $orgId] = $this->db->transaction(function (): array {
            $now = $this->now();
            $expires = $this->clock->nowUtc()->modify(self::LIFETIME)->format('Y-m-d H:i:s');
            $userPublicId = PublicId::generate();
            $this->db->run(
                'INSERT INTO users (public_id, email, password_hash, status, is_demo, created_at, updated_at)
                 VALUES (:pid, :email, NULL, \'active\', 1, :now, :now2)',
                ['pid' => $userPublicId, 'email' => 'demo-' . strtolower($userPublicId) . '@demo.invalid', 'now' => $now, 'now2' => $now],
            );
            $userId = $this->db->lastInsertId();
            $this->db->run(
                'INSERT INTO organizations (public_id, name, activity_type, team_size, legal_status, is_demo, demo_expires_at, created_at, updated_at)
                 VALUES (:pid, \'Agence Lumen\', \'wordpress\', 4, \'sarl\', 1, :expires, :now, :now2)',
                ['pid' => PublicId::generate(), 'expires' => $expires, 'now' => $now, 'now2' => $now],
            );
            $orgId = $this->db->lastInsertId();
            $this->db->run('INSERT INTO organization_members (organization_id, user_id, role, created_at) VALUES (:org, :user, \'owner\', :now)',
                ['org' => $orgId, 'user' => $userId, 'now' => $now]);
            $this->db->run('INSERT INTO organization_settings (organization_id, onboarding_step, onboarding_completed_at, created_at, updated_at) VALUES (:org, 3, :now, :now2, :now3)',
                ['org' => $orgId, 'now' => $now, 'now2' => $now, 'now3' => $now]);

            return [$userId, $orgId];
        });
        $this->seed(new OrgId($orgId), $userId);

        $this->sessions->regenerate();
        $_SESSION['user_id'] = $userId;
        $_SESSION['organization_id'] = $orgId;
        $_SESSION['session_epoch'] = 1;
        $this->events->record('demo.created', $userId, $orgId, $ip, $userAgent);

        return 'ok';
    }

    /** Remet les données fictives d'origine, sans prolonger l'expiration. */
    public function reset(OrgId $org, int $userId): void
    {
        $this->db->transaction(function () use ($org): void {
            foreach (['pricing_simulations', 'diagnostics', 'financial_profile_changes', 'financial_profiles', 'services'] as $table) {
                $this->db->run("DELETE FROM {$table} WHERE organization_id = :org", ['org' => $org->value]);
            }
            $this->db->run(
                "UPDATE organizations SET name = 'Agence Lumen', activity_type = 'wordpress', team_size = 4, legal_status = 'sarl' WHERE id = :org AND is_demo = 1",
                ['org' => $org->value],
            );
        });
        $this->seed($org, $userId);
    }

    /**
     * Supprime les démos expirées (cascade sur toutes les tables), puis les utilisateurs de démo orphelins.
     */
    public function purgeExpired(int $limit = 500): int
    {
        $rows = $this->db->fetchAll(
            'SELECT id, public_id FROM organizations WHERE is_demo = 1 AND demo_expires_at <= :now ORDER BY demo_expires_at LIMIT ' . max(1, min(1000, $limit)),
            ['now' => $this->now()],
        );
        $count = 0;
        foreach ($rows as $row) {
            $this->db->transaction(function () use ($row): void {
                $orgId = TypedArray::int($row, 'id');
                $members = $this->db->fetchAll('SELECT user_id FROM organization_members WHERE organization_id = :org', ['org' => $orgId]);
                $this->db->run('DELETE FROM organizations WHERE id = :org AND is_demo = 1', ['org' => $orgId]);
                foreach ($members as $member) {
                    $this->db->run(
                        'DELETE FROM users WHERE id = :id AND is_demo = 1 AND NOT EXISTS (SELECT 1 FROM organization_members WHERE user_id = :id2)',
                        ['id' => TypedArray::int($member, 'user_id'), 'id2' => TypedArray::int($member, 'user_id')],
                    );
                }
            });
            $count++;
        }
        if ($count > 0) {
            $this->events->record('demo.purged', context: ['count' => $count]);
        }

        return $count;
    }

    /** Jeu de données fictif de référence (cohérent avec les cas chiffrés de docs/03). */
    private function seed(OrgId $org, int $userId): void
    {
        $this->profiles->update($org, [
            'period_type' => 'closed_fiscal_year',
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'revenue' => '180000.00',
            'fixed_costs' => '30000.00',
            'personnel_costs' => '60000.00',
            'variable_costs_attributable' => '12000.00',
            'variable_costs_general' => '3600.00',
            'owner_comp_total_cost' => '50000.00',
            'owner_comp_included_amount' => '0.00',
            'production_fte' => '2.00',
            'annual_hours_per_fte' => '1607.00',
            'annual_hours_is_assumption' => true,
            'billable_rate' => '65.00',
            'billable_rate_is_assumption' => true,
            'revenue_target' => '250000.00',
            'revenue_target_year' => 2026,
            'recurring_share_declared' => '25.00',
        ], $userId, 'demo_seed');

        foreach ([
            ['Site vitrine WordPress', 'showcase', '3200.00', 8],
            ['Site e-commerce', 'ecommerce', '9500.00', 3],
            ['Refonte de site', 'redesign', '5500.00', 4],
            ['Accompagnement SEO', 'seo', '1800.00', 6],
            ['Prestation sur mesure', 'custom', null, null],
        ] as [$name, $category, $price, $volume]) {
            $this->services->create($org, $name, $category, $price === null ? null : Decimal::of($price), $volume);
        }

        $this->seedPricing($org, $userId);

        $draft = $this->diagnostics->startOrResume($org, $userId);
        foreach (self::LUMEN_DIAGNOSTIC as $question => $option) {
            $this->diagnostics->answer($org, $draft['public_id'], ['question' => $question, 'option' => $option]);
        }
        $this->diagnostics->complete($org, $draft['public_id']);
    }

    /**
     * Pricing fictif : « Site vitrine » finalisé (deux scénarios, prix actuel 3 200 € sous le prix recommandé)
     * et un brouillon pour la refonte.
     */
    private function seedPricing(OrgId $org, int $userId): void
    {
        $services = [];
        foreach ($this->services->all($org) as $service) {
            $services[$service['name']] = $service['public_id'];
        }
        $lines = self::lines(...);
        $showcase = $this->pricing->create($org, ['service' => $services['Site vitrine WordPress']]);
        $view = $this->pricing->view($org, $showcase) ?? [];
        /** @var list<array{public_id: string}> $scenarios */
        $scenarios = $view['scenarios'] ?? [];
        $this->pricing->saveScenario($org, $scenarios[0]['public_id'], [
            'label' => 'Scénario A', 'hourly_cost_mode' => 'profile', 'contingency_pct' => '0.00', 'target_margin_pct' => '30.00',
            'lines' => $lines([['Cadrage', '4.00'], ['Design', '10.00'], ['Développement', '18.00'], ['Contenus et recette', '6.00'], ['Gestion de projet', '2.00']], [['Thème premium', '300.00']]),
        ]);
        $b = $this->pricing->addScenario($org, $showcase, ['copy_of' => $scenarios[0]['public_id']]);
        $this->pricing->saveScenario($org, $b, [
            'label' => 'Design allégé', 'hourly_cost_mode' => 'profile', 'contingency_pct' => '10.00', 'target_margin_pct' => '30.00',
            'lines' => $lines([['Cadrage', '4.00'], ['Design', '6.00'], ['Développement', '18.00'], ['Contenus et recette', '6.00'], ['Gestion de projet', '2.00']], [['Thème premium', '300.00']]),
        ]);
        $this->pricing->finalize($org, $showcase, $userId);

        $redesign = $this->pricing->create($org, ['service' => $services['Refonte de site']]);
        $view = $this->pricing->view($org, $redesign) ?? [];
        /** @var list<array{public_id: string}> $scenarios */
        $scenarios = $view['scenarios'] ?? [];
        $this->pricing->saveScenario($org, $scenarios[0]['public_id'], [
            'label' => 'Scénario A', 'hourly_cost_mode' => 'profile', 'contingency_pct' => '10.00', 'target_margin_pct' => '30.00',
            'lines' => $lines([['Audit de l\'existant', '6.00'], ['Maquettes', '10.00'], ['Intégration et reprise des contenus', '30.00'], ['Redirections et recette', '6.00']], []),
        ]);
    }

    /**
     * @param list<array{0: string, 1: string}> $time
     * @param list<array{0: string, 1: string}> $costs
     * @return list<array<string, string>>
     */
    private static function lines(array $time, array $costs): array
    {
        $out = [];
        foreach ($time as [$label, $hours]) {
            $out[] = ['kind' => 'time', 'label' => $label, 'hours' => $hours];
        }
        foreach ($costs as [$label, $amount]) {
            $out[] = ['kind' => 'direct_cost', 'label' => $label, 'amount' => $amount];
        }

        return $out;
    }

    private function now(): string
    {
        return $this->clock->nowUtc()->format('Y-m-d H:i:s');
    }
}
