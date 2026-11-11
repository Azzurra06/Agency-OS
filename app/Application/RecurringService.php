<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Finance\DerivedValue;
use App\Domain\Recurring\RecurringCalculator;
use App\Infrastructure\Database\OrgId;
use App\Infrastructure\Repositories\RecurringRepository;
use App\Support\Decimal;
use App\Support\Validator;
use LogicException;

/**
 * Cas d'usage du module Revenus récurrents (docs/03 §5, docs/05 §3.2, docs/07 §4.6).
 *
 * - RÉEL : formules et contrats signés (modifiables, sans cycle de vie) → MRR et ARR contractualisés, couverture du CA
 *   affichée À CÔTÉ de la part déclarée (jamais à sa place), capacité utilisée.
 * - PRÉVISIONNEL : scénarios de projection (brouillon recalculé en direct, version finalisée figée et chiffrée).
 * - Le MRR contractualisé et le MRR simulé ne sont JAMAIS additionnés.
 *
 * @phpstan-import-type OfferRow from RecurringRepository
 * @phpstan-import-type ScenarioRow from RecurringRepository
 * @phpstan-type Inputs array{hourly_cost: DerivedValue, variable_rate: DerivedValue, period: string|null, annualized_revenue: DerivedValue, monthly_capacity: DerivedValue, declared_share: Decimal|null}
 */
final class RecurringService
{
    public const MAX_OFFERS = 30;
    public const MAX_SCENARIOS = 50;
    public const MAX_ITEMS = 20;

    /** @var array<string, list<string>> */
    private const OFFER_RULES = [
        'name' => ['required', 'string', 'min:2', 'max:100'],
        'kind' => ['required', 'enum:maintenance,seo,hosting,other'],
        'billing_period' => ['required', 'enum:monthly,annual'],
        'price' => ['required', 'decimal', 'min:0', 'max:999999.99'],
        'hours_per_month' => ['nullable', 'decimal', 'min:0', 'max:500'],
        'external_costs_per_month' => ['nullable', 'decimal', 'min:0', 'max:99999.99'],
        'active_contracts' => ['nullable', 'int', 'min:0', 'max:10000'],
        'is_active' => ['bool'],
    ];

    /** @var array<string, list<string>> */
    private const ENTRY_RULES = [
        'offer' => ['required', 'public_id'],
        'starting_clients' => ['required', 'decimal', 'min:0', 'max:100000'],
        'new_clients_per_month' => ['required', 'decimal', 'min:0', 'max:1000'],
        'monthly_churn_pct' => ['required', 'percent', 'min:0', 'max:100'],
    ];

    public function __construct(
        private readonly RecurringRepository $repository,
        private readonly FinancialProfileService $profiles,
        private readonly RecurringCalculator $calculator = new RecurringCalculator(),
    ) {
    }

    /* ---------------------------------------------------------------- Formules (réel) */

    /**
     * Formules avec leur économie par contrat (calculée en direct avec le coût horaire et tv du profil).
     *
     * @return list<array<string, mixed>>
     */
    public function offers(OrgId $org): array
    {
        $inputs = $this->profiles->pricingInputs($org);

        return array_map(fn (array $o): array => $this->offerView($o, $inputs), $this->repository->offers($org));
    }

    /** @return array<string, mixed>|null */
    public function offer(OrgId $org, string $publicId): ?array
    {
        $offer = $this->repository->offer($org, $publicId);

        return $offer === null ? null : $this->offerView($offer, $this->profiles->pricingInputs($org));
    }

    /** @param array<string, mixed> $input */
    public function createOffer(OrgId $org, array $input): string
    {
        if (count($this->repository->offers($org)) >= self::MAX_OFFERS) {
            throw new ValidationException(['name' => 'quota_reached']);
        }
        [$data, $items] = $this->validateOffer($input);

        return $this->repository->createOffer($org, $data, $items);
    }

    /** @param array<string, mixed> $input */
    public function updateOffer(OrgId $org, string $publicId, array $input): void
    {
        $offer = $this->repository->offer($org, $publicId) ?? throw new NotFoundException();
        [$data, $items] = $this->validateOffer($input);
        $this->repository->updateOffer($org, $offer['id'], $data, $items);
        if ($data['active_contracts'] !== $offer['active_contracts']) {
            $this->repository->setContracts($org, $offer['id'], $data['active_contracts']);
        }
    }

    /** @param array<string, mixed> $input {active_contracts: int} */
    public function setContracts(OrgId $org, string $publicId, array $input): int
    {
        $offer = $this->repository->offer($org, $publicId) ?? throw new NotFoundException();
        $v = Validator::make($input, ['active_contracts' => ['required', 'int', 'min:0', 'max:10000']]);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $contracts = $v->validated()['active_contracts'] ?? 0;
        $contracts = is_int($contracts) ? $contracts : 0;
        $this->repository->setContracts($org, $offer['id'], $contracts);

        return $contracts;
    }

    public function deleteOffer(OrgId $org, string $publicId): void
    {
        $offer = $this->repository->offer($org, $publicId) ?? throw new NotFoundException();
        $this->repository->deleteOffer($org, $offer['id']);
    }

    /**
     * Aperçu de l'économie d'une formule pendant la saisie (sans enregistrement).
     *
     * @param array<string, mixed> $input
     * @return array{monthly_price: string, monthly_cost: string|null, monthly_result: string|null, margin_on_price_pct: string|null, floor_price: string|null, hourly_cost: string|null, variable_rate_pct: string|null, unprofitable: bool}
     */
    public function previewOffer(OrgId $org, array $input): array
    {
        [$data] = $this->validateOffer($input);
        $fake = $data;

        return $this->economics($fake, $this->profiles->pricingInputs($org));
    }

    /* ---------------------------------------------------------------- Synthèse (réel) */

    /**
     * MRR et ARR contractualisés, répartition par formule, couverture comparée à la part déclarée, capacité.
     *
     * @return array<string, mixed>
     */
    public function summary(OrgId $org): array
    {
        $inputs = $this->profiles->pricingInputs($org);
        $offers = array_values(array_filter($this->repository->offers($org), static fn (array $o): bool => $o['is_active']));
        $rows = [];
        foreach ($offers as $o) {
            $rows[] = [
                'monthly_price' => RecurringCalculator::monthlyPrice(Decimal::of($o['price']), $o['billing_period']),
                'contracts' => $o['active_contracts'],
                'hours_per_month' => Decimal::of($o['hours_per_month']),
            ];
        }
        $c = $this->calculator->contracted($rows, $inputs['annualized_revenue']->value, $inputs['declared_share'], $inputs['monthly_capacity']->value);
        $breakdown = [];
        $unprofitable = [];
        foreach ($offers as $i => $o) {
            $mrr = $rows[$i]['monthly_price']->mul(Decimal::of($o['active_contracts']));
            $economics = $this->economics($o, $inputs);
            $breakdown[] = [
                'public_id' => $o['public_id'], 'name' => $o['name'], 'contracts' => $o['active_contracts'], 'mrr' => $mrr->toFixed(2),
                'share_pct' => $c['mrr']->isPositive() ? $mrr->mul(Decimal::of('100'))->div($c['mrr'])->toFixed(2) : null,
            ];
            if ($economics['unprofitable']) {
                $unprofitable[] = $o['name'];
            }
        }
        $latest = null;
        foreach ($offers as $o) {
            if ($o['contracts_updated_at'] !== null && ($latest === null || $o['contracts_updated_at'] > $latest)) {
                $latest = $o['contracts_updated_at'];
            }
        }

        return [
            'nature' => 'calculated',
            'mrr' => $c['mrr']->toFixed(2),
            'arr' => $c['arr']->toFixed(2),
            'coverage_pct' => $c['coverage']?->mul(Decimal::of('100'))->toFixed(2),
            'declared_share_pct' => $inputs['declared_share']?->mul(Decimal::of('100'))->toFixed(2),
            'gap_points' => $c['gap_points']?->toFixed(2),
            'gap_alert' => $c['gap_alert'],
            'hours_needed' => $c['hours']->toFixed(2),
            'monthly_capacity' => $inputs['monthly_capacity']->value?->toFixed(2),
            'capacity_use_pct' => $c['capacity_use']?->mul(Decimal::of('100'))->toFixed(2),
            'breakdown' => $breakdown,
            'unprofitable' => $unprofitable,
            'contracts_updated_at' => $latest,
            'revenue_missing' => $inputs['annualized_revenue']->value === null,
        ];
    }

    /** Remplace EXPLICITEMENT la part récurrente déclarée par la couverture calculée (action de l'utilisateur, journalisée). */
    public function acceptComputedShare(OrgId $org, ?int $userId): string
    {
        $coverage = $this->summary($org)['coverage_pct'];
        if (!is_string($coverage)) {
            throw new ValidationException(['recurring_share_declared' => 'Couverture non calculable : renseignez le chiffre d\'affaires du profil.']);
        }
        $value = Decimal::of($coverage)->min(Decimal::of('100'))->toFixed(2);
        $this->profiles->update($org, ['recurring_share_declared' => $value], $userId, 'accept_computed_share');

        return $value;
    }

    /* ---------------------------------------------------------------- Scénarios (prévisionnel) */

    /** @return list<array<string, mixed>> */
    public function scenarios(OrgId $org): array
    {
        $inputs = $this->profiles->pricingInputs($org);
        $offers = $this->repository->offers($org);

        return array_map(function (array $s) use ($org, $inputs, $offers): array {
            $projection = $this->projection($org, $s, $inputs, $offers);
            $months = is_array($projection['months'] ?? null) ? $projection['months'] : [];
            $last = $months === [] ? null : end($months);

            return [
                'public_id' => $s['public_id'], 'name' => $s['name'], 'status' => $s['status'], 'version' => $s['version_number'],
                'horizon' => $s['horizon_months'], 'finalized_at' => $s['finalized_at'],
                'final_mrr' => is_array($last) ? ($last['mrr'] ?? null) : null,
                'cumulative' => $projection['cumulative'] ?? null,
            ];
        }, $this->repository->scenarios($org));
    }

    /** @param array<string, mixed> $input {name?: string, horizon?: 6|12} */
    public function createScenario(OrgId $org, array $input): string
    {
        if (count($this->repository->scenarios($org)) >= self::MAX_SCENARIOS) {
            throw new ValidationException(['name' => 'quota_reached']);
        }
        $v = Validator::make($input, ['name' => ['nullable', 'string', 'min:1', 'max:150'], 'horizon' => ['nullable', 'enum:6,12']]);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $valid = $v->validated();
        $entries = [];
        foreach ($this->repository->offers($org, true) as $o) {
            // Point de départ : les contrats réellement signés aujourd'hui, sans hypothèse de croissance.
            $entries[] = ['offer_id' => $o['id'], 'starting_clients' => (string) $o['active_contracts'], 'new_clients_per_month' => '0', 'monthly_churn_rate' => '0'];
        }
        if ($entries === []) {
            throw new ValidationException(['offers' => 'Créez d\'abord au moins une formule récurrente.']);
        }
        $name = is_string($valid['name'] ?? null) ? $valid['name'] : 'Scénario de croissance';
        $horizon = ($valid['horizon'] ?? '12') === '6' ? 6 : 12;

        return $this->repository->createScenario($org, $name, $horizon, $entries)['public_id'];
    }

    /** @param array<string, mixed> $input {name, horizon, entries: list<{offer, starting_clients, new_clients_per_month, monthly_churn_pct}>} */
    public function updateScenario(OrgId $org, string $publicId, array $input): void
    {
        $scenario = $this->repository->scenario($org, $publicId) ?? throw new NotFoundException();
        if ($scenario['status'] !== 'draft') {
            throw new ConflictException();
        }
        [$name, $horizon, $entries] = $this->validateScenario($org, $input);
        if (!$this->repository->updateScenario($org, $scenario['id'], $name, $horizon, $entries)) {
            throw new ConflictException();
        }
    }

    /**
     * Aperçu d'une projection pendant la saisie (sans enregistrement).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function previewProjection(OrgId $org, array $input): array
    {
        [, $horizon, $entries] = $this->validateScenario($org, $input);
        $offers = $this->repository->offers($org);
        $byId = array_column($offers, null, 'id');
        $inputs = $this->profiles->pricingInputs($org);

        return $this->computeProjection(array_map(static fn (array $e): array => $e + ['offer' => $byId[$e['offer_id']]], $entries), $horizon, $inputs);
    }

    public function deleteScenario(OrgId $org, string $publicId): void
    {
        $scenario = $this->repository->scenario($org, $publicId) ?? throw new NotFoundException();
        if ($scenario['status'] !== 'draft' || !$this->repository->deleteDraft($org, $scenario['id'])) {
            throw new ConflictException();
        }
    }

    public function finalizeScenario(OrgId $org, string $publicId, ?int $userId): void
    {
        $scenario = $this->repository->scenario($org, $publicId) ?? throw new NotFoundException();
        if ($scenario['status'] !== 'draft') {
            throw new ConflictException();
        }
        $inputs = $this->profiles->pricingInputs($org);
        $offers = $this->repository->offers($org);
        $results = $this->projection($org, $scenario, $inputs, $offers);
        if (($results['months'] ?? []) === []) {
            throw new ValidationException(['entries' => 'Le scénario ne contient aucune formule.']);
        }
        $context = [
            'offers' => $results['offers'] ?? [],
            'hourly_cost' => $inputs['hourly_cost']->value?->toString(),
            'variable_rate' => $inputs['variable_rate']->value?->toString(),
            'monthly_capacity' => $inputs['monthly_capacity']->value?->toString(),
            'horizon' => $scenario['horizon_months'],
        ];
        if (!$this->repository->finalize($org, $scenario['id'], $scenario['public_id'], $scenario['family_id'], $userId, RecurringCalculator::CALC_VERSION, $context, $results)) {
            throw new ConflictException();
        }
    }

    public function archiveScenario(OrgId $org, string $publicId): void
    {
        $scenario = $this->repository->scenario($org, $publicId) ?? throw new NotFoundException();
        if (!$this->repository->archive($org, $scenario['id'])) {
            throw new ConflictException();
        }
    }

    /** « Dupliquer pour modifier » : brouillon version + 1 (un seul brouillon par famille). */
    public function newVersion(OrgId $org, string $publicId): string
    {
        $scenario = $this->repository->scenario($org, $publicId) ?? throw new NotFoundException();
        if ($scenario['status'] === 'draft') {
            throw new ConflictException();
        }
        $versions = $this->repository->versions($org, $scenario['family_id']);
        foreach ($versions as $v) {
            if ($v['status'] === 'draft') {
                return $v['public_id'];
            }
        }
        $entries = [];
        foreach ($this->repository->entries($org, $scenario['id']) as $e) {
            if ($e['offer_id'] !== null) { // une formule supprimée depuis n'est pas reprise
                $entries[] = ['offer_id' => $e['offer_id'], 'starting_clients' => $e['starting_clients'], 'new_clients_per_month' => $e['new_clients_per_month'], 'monthly_churn_rate' => $e['monthly_churn_rate']];
            }
        }
        $next = ($versions[0]['version_number'] ?? $scenario['version_number']) + 1;

        return $this->repository->createScenario($org, $scenario['name'], $scenario['horizon_months'], $entries, $scenario['family_id'], $next, $scenario['id'])['public_id'];
    }

    /**
     * Vue d'un scénario : paramètres, projection (en direct pour un brouillon, figée sinon), versions.
     *
     * @return array<string, mixed>|null
     */
    public function scenarioView(OrgId $org, string $publicId): ?array
    {
        $scenario = $this->repository->scenario($org, $publicId);
        if ($scenario === null) {
            return null;
        }
        $offers = $this->repository->offers($org);
        $inputs = $this->profiles->pricingInputs($org);
        $byId = array_column($offers, null, 'id');
        $entries = [];
        foreach ($this->repository->entries($org, $scenario['id']) as $e) {
            $offer = $e['offer_id'] === null ? null : ($byId[$e['offer_id']] ?? null);
            $entries[] = [
                'offer' => $offer === null ? null : ['public_id' => $offer['public_id'], 'name' => $offer['name'], 'active_contracts' => $offer['active_contracts']],
                'starting_clients' => $e['starting_clients'],
                'new_clients_per_month' => $e['new_clients_per_month'],
                'monthly_churn_pct' => Decimal::of($e['monthly_churn_rate'])->mul(Decimal::of('100'))->toFixed(2),
            ];
        }

        return [
            'scenario' => [
                'public_id' => $scenario['public_id'], 'name' => $scenario['name'], 'status' => $scenario['status'], 'version' => $scenario['version_number'],
                'horizon' => $scenario['horizon_months'], 'finalized_at' => $scenario['finalized_at'], 'calc_version' => $scenario['calc_version'],
            ],
            'entries' => $entries,
            'available_offers' => array_values(array_map(static fn (array $o): array => ['public_id' => $o['public_id'], 'name' => $o['name'], 'active_contracts' => $o['active_contracts']], array_filter($offers, static fn (array $o): bool => $o['is_active']))),
            'projection' => $this->projection($org, $scenario, $inputs, $offers),
            'contracted_mrr' => $this->summary($org)['mrr'],
            'versions' => array_map(static fn (array $v): array => ['public_id' => $v['public_id'], 'version' => $v['version_number'], 'status' => $v['status'], 'finalized_at' => $v['finalized_at']], $this->repository->versions($org, $scenario['family_id'])),
        ];
    }

    /* ---------------------------------------------------------------- Calculs internes */

    /**
     * Projection d'un scénario : figée (déchiffrée) s'il est finalisé, recalculée en direct sinon.
     *
     * @param ScenarioRow $scenario
     * @param Inputs $inputs
     * @param list<OfferRow> $offers
     * @return array<string, mixed>
     */
    private function projection(OrgId $org, array $scenario, array $inputs, array $offers): array
    {
        if ($scenario['status'] !== 'draft') {
            $frozen = $this->repository->frozen($org, $scenario['id'], $scenario['public_id']);

            return ($frozen['results'] ?? []) + ['nature' => 'frozen'];
        }
        $byId = array_column($offers, null, 'id');
        $entries = [];
        foreach ($this->repository->entries($org, $scenario['id']) as $e) {
            if ($e['offer_id'] !== null && isset($byId[$e['offer_id']])) {
                $entries[] = ['offer_id' => $e['offer_id'], 'starting_clients' => $e['starting_clients'], 'new_clients_per_month' => $e['new_clients_per_month'],
                    'monthly_churn_rate' => $e['monthly_churn_rate'], 'offer' => $byId[$e['offer_id']]];
            }
        }

        return $this->computeProjection($entries, $scenario['horizon_months'], $inputs);
    }

    /**
     * @param list<array{offer_id: int, starting_clients: string, new_clients_per_month: string, monthly_churn_rate: string, offer: OfferRow}> $entries
     * @param Inputs $inputs
     * @return array<string, mixed>
     */
    private function computeProjection(array $entries, int $horizon, array $inputs): array
    {
        $calc = [];
        $offersOut = [];
        foreach ($entries as $e) {
            $o = $e['offer'];
            $key = $o['public_id'];
            $calc[$key] = [
                'monthly_price' => RecurringCalculator::monthlyPrice(Decimal::of($o['price']), $o['billing_period']),
                'hours_per_month' => Decimal::of($o['hours_per_month']),
                'starting' => Decimal::of($e['starting_clients']),
                'new_per_month' => Decimal::of($e['new_clients_per_month']),
                'churn' => Decimal::of($e['monthly_churn_rate']),
            ];
            $offersOut[$key] = [
                'name' => $o['name'], 'monthly_price' => $calc[$key]['monthly_price']->toFixed(2), 'hours_per_month' => $calc[$key]['hours_per_month']->toFixed(2),
                'starting_clients' => $calc[$key]['starting']->toFixed(2), 'new_clients_per_month' => $calc[$key]['new_per_month']->toFixed(2),
                'monthly_churn_pct' => $calc[$key]['churn']->mul(Decimal::of('100'))->toFixed(2),
            ];
        }
        if ($calc === []) {
            return ['nature' => 'simulated', 'months' => [], 'cumulative' => null, 'cumulative_6' => null, 'offers' => [], 'capacity_exceeded' => false];
        }
        $p = $this->calculator->project($calc, $horizon, $inputs['monthly_capacity']->value);
        $exceeded = false;
        $months = array_map(static function (array $m) use (&$exceeded): array {
            if ($m['capacity_use'] !== null && $m['capacity_use']->compare(Decimal::of('1')) > 0) {
                $exceeded = true;
            }

            return [
                'month' => $m['month'],
                'clients' => $m['total_clients']->toFixed(2),
                'clients_rounded' => $m['total_clients']->round(0)->toFixed(0),
                'mrr' => $m['mrr']->toFixed(2),
                'hours' => $m['hours']->toFixed(2),
                'capacity_use_pct' => $m['capacity_use']?->mul(Decimal::of('100'))->toFixed(2),
            ];
        }, $p['months']);

        return [
            'nature' => 'simulated',
            'months' => $months,
            'cumulative' => $p['cumulative']->toFixed(2),
            'cumulative_6' => $p['cumulative_6']->toFixed(2),
            'offers' => $offersOut,
            'capacity_exceeded' => $exceeded,
        ];
    }

    /**
     * @param OfferRow|array{id: int, public_id: string, name: string, kind: string, billing_period: string, price: string, hours_per_month: string, external_costs_per_month: string, active_contracts: int, contracts_updated_at: string|null, is_active: bool, items: list<string>} $o
     * @param Inputs $inputs
     * @return array<string, mixed>
     */
    private function offerView(array $o, array $inputs): array
    {
        $mrr = RecurringCalculator::monthlyPrice(Decimal::of($o['price']), $o['billing_period'])->mul(Decimal::of($o['active_contracts']));

        return [
            'public_id' => $o['public_id'], 'name' => $o['name'], 'kind' => $o['kind'], 'billing_period' => $o['billing_period'],
            'price' => $o['price'], 'hours_per_month' => $o['hours_per_month'], 'external_costs_per_month' => $o['external_costs_per_month'],
            'active_contracts' => $o['active_contracts'], 'contracts_updated_at' => $o['contracts_updated_at'], 'is_active' => $o['is_active'], 'items' => $o['items'],
            'mrr' => $mrr->toFixed(2),
            'economics' => $this->economics($o, $inputs),
        ];
    }

    /**
     * Économie d'un contrat, par mois (chaînes décimales de l'API).
     *
     * @param array{price: string, billing_period: string, hours_per_month: string, external_costs_per_month: string} $o
     * @param Inputs $inputs
     * @return array{monthly_price: string, monthly_cost: string|null, monthly_result: string|null, margin_on_price_pct: string|null, floor_price: string|null, hourly_cost: string|null, variable_rate_pct: string|null, unprofitable: bool}
     */
    private function economics(array $o, array $inputs): array
    {
        $e = $this->calculator->offer(
            Decimal::of($o['price']), $o['billing_period'], Decimal::of($o['hours_per_month']), Decimal::of($o['external_costs_per_month']),
            $inputs['hourly_cost']->value, $inputs['variable_rate']->value ?? Decimal::zero(),
        );

        return [
            'monthly_price' => $e['monthly_price']->toFixed(2),
            'monthly_cost' => $e['monthly_cost']?->toFixed(2),
            'monthly_result' => $e['monthly_result']?->toFixed(2),
            'margin_on_price_pct' => $e['margin_on_price']?->mul(Decimal::of('100'))->toFixed(2),
            'floor_price' => $e['floor_price']?->toFixed(2),
            'hourly_cost' => $inputs['hourly_cost']->value?->toFixed(2),
            'variable_rate_pct' => $inputs['variable_rate']->value?->mul(Decimal::of('100'))->toFixed(2),
            'unprofitable' => $e['monthly_result']?->isNegative() ?? false,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: array{name: string, kind: string, billing_period: string, price: string, hours_per_month: string, external_costs_per_month: string, active_contracts: int, is_active: bool}, 1: list<string>}
     */
    private function validateOffer(array $input): array
    {
        $items = $input['items'] ?? [];
        unset($input['items']);
        $v = Validator::make($input, self::OFFER_RULES);
        $errors = $v->fails() ? $v->errors() : [];
        $cleanItems = [];
        if (!is_array($items) || !array_is_list($items) || count($items) > self::MAX_ITEMS) {
            $errors['items'] = 'invalid_items';
        } else {
            foreach ($items as $i => $item) {
                if (!is_string($item) || mb_strlen(trim($item)) > 120 || preg_match('/[\x00-\x1F\x7F]/', $item) === 1) {
                    $errors["items.{$i}"] = 'invalid_string';
                } elseif (trim($item) !== '') {
                    $cleanItems[] = trim($item);
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $valid = $v->validated();
        $dec = static fn (mixed $d): string => $d instanceof Decimal ? $d->toFixed(2) : '0.00';
        $contracts = $valid['active_contracts'] ?? 0;

        return [[
            'name' => is_string($valid['name'] ?? null) ? $valid['name'] : '',
            'kind' => is_string($valid['kind'] ?? null) ? $valid['kind'] : 'other',
            'billing_period' => is_string($valid['billing_period'] ?? null) ? $valid['billing_period'] : 'monthly',
            'price' => $dec($valid['price'] ?? null),
            'hours_per_month' => $dec($valid['hours_per_month'] ?? null),
            'external_costs_per_month' => $dec($valid['external_costs_per_month'] ?? null),
            'active_contracts' => is_int($contracts) ? $contracts : 0,
            'is_active' => ($valid['is_active'] ?? true) !== false,
        ], $cleanItems];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: string, 1: int, 2: list<array{offer_id: int, starting_clients: string, new_clients_per_month: string, monthly_churn_rate: string}>}
     */
    private function validateScenario(OrgId $org, array $input): array
    {
        $entries = $input['entries'] ?? [];
        unset($input['entries']);
        $v = Validator::make($input, ['name' => ['nullable', 'string', 'min:1', 'max:150'], 'horizon' => ['required', 'enum:6,12']]);
        $errors = $v->fails() ? $v->errors() : [];
        $offers = array_column($this->repository->offers($org), null, 'public_id');
        $clean = [];
        $seen = [];
        if (!is_array($entries) || !array_is_list($entries) || $entries === [] || count($entries) > self::MAX_OFFERS) {
            $errors['entries'] = 'invalid_entries';
            $entries = [];
        }
        foreach ($entries as $i => $entry) {
            if (!is_array($entry)) {
                $errors["entries.{$i}"] = 'invalid_entries';
                continue;
            }
            /** @var array<string, mixed> $entry */
            $ev = Validator::make($entry, self::ENTRY_RULES);
            if ($ev->fails()) {
                foreach ($ev->errors() as $field => $code) {
                    $errors["entries.{$i}.{$field}"] = $code;
                }
                continue;
            }
            $e = $ev->validated();
            $offerId = is_string($e['offer'] ?? null) ? ($offers[$e['offer']]['id'] ?? null) : null;
            if ($offerId === null || isset($seen[$offerId])) {
                $errors["entries.{$i}.offer"] = 'invalid_choice'; // formule d'une autre organisation, inconnue ou en double
                continue;
            }
            $seen[$offerId] = true;
            $d = static fn (mixed $x, int $scale): string => $x instanceof Decimal ? $x->toFixed($scale) : '0';
            $clean[] = [
                'offer_id' => $offerId,
                'starting_clients' => $d($e['starting_clients'] ?? null, 2),
                'new_clients_per_month' => $d($e['new_clients_per_month'] ?? null, 2),
                'monthly_churn_rate' => $d($e['monthly_churn_pct'] ?? null, 4),
            ];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $valid = $v->validated();
        if ($clean === []) {
            throw new LogicException('Aucune formule.');
        }

        return [is_string($valid['name'] ?? null) ? $valid['name'] : 'Scénario', ($valid['horizon'] ?? '12') === '6' ? 6 : 12, $clean];
    }
}
