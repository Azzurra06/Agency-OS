<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\OrgId;
use App\Infrastructure\Repositories\DiagnosticRepository;
use App\Infrastructure\Repositories\FinancialProfileRepository;
use App\Infrastructure\Repositories\OrganizationRepository;
use App\Infrastructure\Repositories\PricingRepository;
use App\Infrastructure\Repositories\ServiceRepository;
use App\Infrastructure\Repositories\SnapshotRepository;
use App\Support\Clock;
use App\Support\TypedArray;

/**
 * Export RGPD (portabilité) : toutes les données de l'organisation, déchiffrées, en un document JSON construit en mémoire.
 * Aucune donnée d'une autre organisation ; les identifiants internes ne sont pas exportés (identifiants publics seulement).
 */
final class ExportService
{
    public const FORMAT_VERSION = '1';

    public function __construct(
        private readonly Connection $db,
        private readonly OrganizationRepository $organizations,
        private readonly FinancialProfileService $profiles,
        private readonly FinancialProfileRepository $profileRepository,
        private readonly ServiceRepository $services,
        private readonly DiagnosticRepository $diagnostics,
        private readonly DiagnosticService $diagnosticService,
        private readonly PricingRepository $pricingRepository,
        private readonly PricingService $pricing,
        private readonly RecurringService $recurring,
        private readonly StrategyService $strategy,
        private readonly SnapshotRepository $snapshots,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function export(OrgId $org, int $userId): array
    {
        $identity = $this->organizations->get($org);
        $user = $this->db->fetchOne('SELECT public_id, email, created_at, email_verified_at, last_login_at FROM users WHERE id = :id', ['id' => $userId]) ?? [];
        $diagnostics = [];
        foreach ($this->diagnostics->history($org, 100) as $h) {
            $diagnostics[] = $this->diagnosticService->results($org, $h['public_id']);
        }
        $simulations = [];
        foreach ($this->pricingRepository->simulations($org) as $sim) {
            $simulations[] = $this->pricing->view($org, $sim['public_id']);
        }
        $recurringScenarios = [];
        foreach ($this->recurring->scenarios($org) as $s) {
            $public = $s['public_id'] ?? null;
            if (is_string($public)) {
                $recurringScenarios[] = $this->recurring->scenarioView($org, $public);
            }
        }
        $recommendations = [];
        foreach (StrategyService::STATUSES as $tab) {
            foreach ($this->strategy->list($org, $tab)['items'] as $item) {
                unset($item['is_demo'], $item['action']);
                $recommendations[] = $item;
            }
        }

        return [
            'format' => 'agency-os-export', 'format_version' => self::FORMAT_VERSION, 'exported_at' => $this->clock->nowUtc()->format('Y-m-d\TH:i:s\Z'),
            'notice' => 'Montants en euros (chaînes décimales), taux en pourcentage. Données figées : valeurs validées à la date indiquée.',
            'account' => [
                'public_id' => TypedArray::string($user, 'public_id', ''), 'email' => TypedArray::string($user, 'email', ''),
                'created_at' => TypedArray::nullableString($user, 'created_at'), 'email_verified_at' => TypedArray::nullableString($user, 'email_verified_at'),
                'last_login_at' => TypedArray::nullableString($user, 'last_login_at'),
            ],
            'organization' => $identity,
            'financial_profile' => ['values' => $this->profiles->formValues($org), 'derived' => $this->profiles->derived($org), 'changes' => $this->profileRepository->changes($org, 1000)],
            'services' => $this->services->all($org),
            'diagnostics' => array_values(array_filter($diagnostics)),
            'pricing_simulations' => array_values(array_filter($simulations)),
            'recurring' => ['offers' => $this->recurring->offers($org), 'summary' => $this->recurring->summary($org), 'scenarios' => array_values(array_filter($recurringScenarios))],
            'recommendations' => $recommendations,
            'action_plan' => $this->strategy->plan($org),
            'history' => $this->snapshots->between($org, $identity['public_id'], '2000-01-01', $this->clock->nowUtc()->modify('+1 day')->format('Y-m-d')),
        ];
    }
}
