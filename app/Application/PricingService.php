<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Finance\DerivedValue;
use App\Domain\Pricing\PricingCalculator;
use App\Domain\Pricing\PricingException;
use App\Domain\Pricing\ScenarioInput;
use App\Infrastructure\Database\OrgId;
use App\Infrastructure\Repositories\PricingRepository;
use App\Support\Decimal;
use App\Support\Validator;
use LogicException;

/**
 * Cas d'usage du module Pricing (docs/03 §4, docs/05 §3.1, docs/07 §4.5).
 *
 * - Brouillon : coût horaire et taux de charges variables lus EN DIRECT dans le profil, résultats recalculés à chaque lecture,
 *   jamais stockés, sans effet sur la stratégie.
 * - Finalisation : une transaction gèle le contexte et les résultats (arrondis à l'échelle de sortie, chiffrés) ;
 *   la simulation active précédente de la même prestation passe en « superseded ».
 * - Version figée : lecture seule (409), indicateur de dérive calculé à la lecture.
 *
 * @phpstan-type ScenarioData array{label: string, hourly_cost_mode: string, hourly_cost_custom: string|null, contingency_rate: string, target_margin_rate: string, proposed_price: string|null}
 * @phpstan-type LineData array{kind: string, label: string, hours: string|null, amount: string|null}
 * @phpstan-import-type SimulationRow from PricingRepository
 */
final class PricingService
{
    public const MAX_SCENARIOS = 3;
    public const MAX_LINES = 40;
    public const MAX_SIMULATIONS = 200;
    public const DEFAULT_MARGIN = '0.30';

    /** @var array<string, list<string>> */
    private const RULES = [
        'label' => ['nullable', 'string', 'min:1', 'max:60'],
        'hourly_cost_mode' => ['enum:profile,custom'],
        'hourly_cost_custom' => ['nullable', 'decimal', 'min:1', 'max:10000'],
        'contingency_pct' => ['nullable', 'percent', 'min:0', 'max:100'],
        'target_margin_pct' => ['nullable', 'percent', 'min:0', 'max:90'],
        'proposed_price' => ['nullable', 'decimal', 'min:0.01', 'max:99999999.99'],
    ];

    /** @var array<string, list<string>> */
    private const LINE_RULES = [
        'kind' => ['required', 'enum:time,direct_cost'],
        'label' => ['required', 'string', 'min:1', 'max:120'],
        'hours' => ['nullable', 'decimal', 'min:0', 'max:10000'],
        'amount' => ['nullable', 'decimal', 'min:0', 'max:99999999.99'],
    ];

    public function __construct(
        private readonly PricingRepository $repository,
        private readonly FinancialProfileService $profiles,
        private readonly PricingCalculator $calculator = new PricingCalculator(),
    ) {
    }

    /**
     * Aperçu sans enregistrement (POST /pricing/calculate). « simulation » facultative : prix et volume de sa prestation.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function preview(OrgId $org, array $input, string $rounding = '10'): array
    {
        $simulationId = $input['simulation'] ?? null;
        unset($input['simulation']);
        $service = ['price' => null, 'volume' => null];
        if (is_string($simulationId)) {
            $sim = $this->repository->find($org, $simulationId) ?? throw new NotFoundException();
            $service = ['price' => $sim['service_price'], 'volume' => $sim['service_volume']];
        }
        [$data, $lines] = $this->validateScenario($input);
        $out = $this->compute($data, $lines, $service, $this->profiles->pricingInputs($org));
        $recommended = self::pick($out, 'results', 'recommended_price');
        $out['rounded_price'] = is_string($recommended) ? PricingCalculator::commercialRounding(Decimal::of($recommended), $rounding)?->toFixed(0) : null;

        return $out;
    }

    /**
     * Crée une simulation brouillon (avec un premier scénario) pour une prestation du catalogue ou « sur mesure ».
     *
     * @param array<string, mixed> $input {service?: public_id, name?: string}
     */
    public function create(OrgId $org, array $input): string
    {
        $v = Validator::make($input, ['service' => ['nullable', 'public_id'], 'name' => ['nullable', 'string', 'min:1', 'max:150']]);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $valid = $v->validated();
        if (count($this->repository->simulations($org)) >= self::MAX_SIMULATIONS) {
            throw new ValidationException(['service' => 'quota_reached']);
        }
        $serviceId = null;
        $name = is_string($valid['name'] ?? null) ? $valid['name'] : 'Simulation sur mesure';
        if (is_string($valid['service'] ?? null)) {
            $serviceId = $this->repository->serviceId($org, $valid['service']) ?? throw new ValidationException(['service' => 'invalid_choice']);
        }
        $sim = $this->repository->createSimulation($org, $serviceId, $name);
        if ($serviceId !== null && !isset($valid['name'])) {
            $row = $this->repository->findById($org, $sim['id']);
            $this->repository->rename($org, $sim['id'], $row['service_name'] ?? $name);
        }
        $scenario = $this->repository->createScenario($org, $sim['id'], self::defaultScenario('Scénario A'), []) ?? throw new LogicException('Brouillon introuvable.');
        $this->repository->setReference($org, $sim['id'], $scenario['id']);

        return $sim['public_id'];
    }

    /** @param array<string, mixed> $input {name?: string, reference?: scenario public_id} */
    public function update(OrgId $org, string $publicId, array $input): void
    {
        $sim = $this->requireDraft($org, $publicId);
        $v = Validator::make($input, ['name' => ['nullable', 'string', 'min:1', 'max:150'], 'reference' => ['nullable', 'public_id']]);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $valid = $v->validated();
        if (is_string($valid['name'] ?? null)) {
            $this->repository->rename($org, $sim['id'], $valid['name']);
        }
        if (is_string($valid['reference'] ?? null)) {
            $scenario = $this->repository->findScenario($org, $valid['reference']);
            if ($scenario === null || $scenario['simulation_id'] !== $sim['id'] || !$this->repository->setReference($org, $sim['id'], $scenario['id'])) {
                throw new ValidationException(['reference' => 'invalid_choice']);
            }
        }
    }

    public function delete(OrgId $org, string $publicId): void
    {
        $sim = $this->requireDraft($org, $publicId);
        $this->repository->deleteDraft($org, $sim['id']);
    }

    /**
     * Ajoute un scénario (au plus 3), vierge ou copie d'un scénario existant de la simulation.
     *
     * @param array<string, mixed> $input {copy_of?: scenario public_id}
     */
    public function addScenario(OrgId $org, string $simulationPublicId, array $input): string
    {
        $sim = $this->requireDraft($org, $simulationPublicId);
        $scenarios = $this->repository->scenarios($org, $sim['id']);
        if (count($scenarios) >= self::MAX_SCENARIOS) {
            throw new ValidationException(['scenarios' => 'too_many_scenarios']);
        }
        $label = 'Scénario ' . ['A', 'B', 'C', 'D'][min(3, count($scenarios))];
        $data = self::defaultScenario($label);
        $lines = [];
        $copyOf = $input['copy_of'] ?? null;
        if (is_string($copyOf)) {
            $source = array_values(array_filter($scenarios, static fn (array $s): bool => $s['public_id'] === $copyOf))[0] ?? null;
            if ($source === null) {
                throw new ValidationException(['copy_of' => 'invalid_choice']);
            }
            $data = ['label' => $label, 'hourly_cost_mode' => $source['hourly_cost_mode'], 'hourly_cost_custom' => $source['hourly_cost_custom'],
                'contingency_rate' => $source['contingency_rate'], 'target_margin_rate' => $source['target_margin_rate'], 'proposed_price' => $source['proposed_price']];
            $lines = $source['lines'];
        }
        $created = $this->repository->createScenario($org, $sim['id'], $data, $lines) ?? throw new ConflictException();

        return $created['public_id'];
    }

    /** @param array<string, mixed> $input */
    public function saveScenario(OrgId $org, string $scenarioPublicId, array $input): void
    {
        $scenario = $this->repository->findScenario($org, $scenarioPublicId) ?? throw new NotFoundException();
        [$data, $lines] = $this->validateScenario($input);
        if (!$this->repository->updateScenario($org, $scenario['id'], $data, $lines)) {
            throw new ConflictException();
        }
    }

    public function deleteScenario(OrgId $org, string $scenarioPublicId): void
    {
        $scenario = $this->repository->findScenario($org, $scenarioPublicId) ?? throw new NotFoundException();
        $sim = $this->repository->findById($org, $scenario['simulation_id']) ?? throw new NotFoundException();
        if ($sim['status'] !== 'draft') {
            throw new ConflictException();
        }
        $all = $this->repository->scenarios($org, $sim['id']);
        if (count($all) <= 1) {
            throw new ValidationException(['scenarios' => 'last_scenario']);
        }
        $this->repository->deleteScenario($org, $scenario['id']);
        if ($sim['reference_scenario_id'] === $scenario['id']) {
            // Le scénario de référence a été supprimé : le premier scénario restant devient la référence.
            $remaining = array_values(array_filter($all, static fn (array $s): bool => $s['id'] !== $scenario['id']));
            $this->repository->setReference($org, $sim['id'], $remaining[0]['id'] ?? 0);
        }
    }

    /**
     * Finalise : 1 à 3 scénarios calculables, scénario de référence désigné, coût horaire disponible.
     */
    public function finalize(OrgId $org, string $publicId, ?int $userId): void
    {
        $sim = $this->requireDraft($org, $publicId);
        $scenarios = $this->repository->scenarios($org, $sim['id']);
        if ($scenarios === [] || $sim['reference_scenario_id'] === null) {
            throw new ValidationException(['reference' => 'reference_required']);
        }
        $inputs = $this->profiles->pricingInputs($org);
        $results = [];
        $ids = [];
        $frozenScenarios = [];
        foreach ($scenarios as $s) {
            $computed = $this->compute(self::scenarioData($s), $s['lines'], ['price' => $sim['service_price'], 'volume' => $sim['service_volume']], $inputs);
            $error = self::pick($computed, 'error');
            if ($error !== null) {
                throw new ValidationException(['scenario' => $s['label'] . ' : ' . self::errorMessage($error)]);
            }
            $results[$s['id']] = $computed;
            $ids[$s['id']] = $s['public_id'];
            $frozenScenarios[$s['public_id']] = $computed['inputs'];
        }
        $context = [
            'service' => $sim['service_id'] === null ? null : ['name' => $sim['service_name'], 'current_price' => $sim['service_price'], 'annual_volume' => $sim['service_volume']],
            'profile_hourly_cost' => self::exact($inputs['hourly_cost']),
            'variable_rate' => self::exact($inputs['variable_rate']),
            'period' => $inputs['period'],
            'scenarios' => $frozenScenarios,
        ];
        if (!$this->repository->finalize($org, $sim['id'], $sim['public_id'], $userId, PricingCalculator::CALC_VERSION, $context, $results, $ids)) {
            throw new ConflictException();
        }
        // Lot 5 : l'événement « PricingFinalized » déclenchera ici la réévaluation des recommandations.
    }

    public function archive(OrgId $org, string $publicId): void
    {
        $sim = $this->repository->find($org, $publicId) ?? throw new NotFoundException();
        if ($sim['status'] !== 'finalized' || !$this->repository->archive($org, $sim['id'])) {
            throw new ConflictException();
        }
    }

    /** Nouvelle version : copie en brouillon (version + 1) d'une version figée. Un seul brouillon par famille. */
    public function newVersion(OrgId $org, string $publicId): string
    {
        $sim = $this->repository->find($org, $publicId) ?? throw new NotFoundException();
        if ($sim['status'] === 'draft') {
            throw new ConflictException();
        }
        $versions = $this->repository->versions($org, $sim['family_id']);
        foreach ($versions as $v) {
            if ($v['status'] === 'draft') {
                return $v['public_id']; // brouillon déjà ouvert pour cette famille : on le reprend
            }
        }
        $next = ($versions[0]['version_number'] ?? $sim['version_number']) + 1;
        $copy = $this->repository->createSimulation($org, $sim['service_id'], $sim['name'], $sim['family_id'], $next, $sim['id']);
        foreach ($this->repository->scenarios($org, $sim['id']) as $s) {
            $created = $this->repository->createScenario($org, $copy['id'], self::scenarioData($s), $s['lines']) ?? throw new LogicException('Brouillon introuvable.');
            if ($s['id'] === $sim['reference_scenario_id']) {
                $this->repository->setReference($org, $copy['id'], $created['id']);
            }
        }

        return $copy['public_id'];
    }

    /**
     * Vue complète d'une simulation : brouillon recalculé en direct, ou version figée (déchiffrée) avec dérive.
     *
     * @return array<string, mixed>|null
     */
    public function view(OrgId $org, string $publicId, string $rounding = '10'): ?array
    {
        $sim = $this->repository->find($org, $publicId);
        if ($sim === null) {
            return null;
        }
        $inputs = $this->profiles->pricingInputs($org);
        $frozen = $sim['status'] === 'draft' ? null : $this->repository->frozenContext($org, $sim['id'], $sim['public_id']);
        $scenarios = [];
        foreach ($this->repository->scenarios($org, $sim['id']) as $s) {
            $computed = $frozen === null
                ? $this->compute(self::scenarioData($s), $s['lines'], ['price' => $sim['service_price'], 'volume' => $sim['service_volume']], $inputs)
                : ($this->repository->frozenResults($org, $s['id'], $s['public_id']) ?? []);
            $recommended = self::pick($computed, 'results', 'recommended_price');
            $scenarios[] = [
                'public_id' => $s['public_id'],
                'label' => $s['label'],
                'is_reference' => $s['id'] === $sim['reference_scenario_id'],
                'form' => [
                    'label' => $s['label'],
                    'hourly_cost_mode' => $s['hourly_cost_mode'],
                    'hourly_cost_custom' => $s['hourly_cost_custom'],
                    'contingency_pct' => Decimal::of($s['contingency_rate'])->mul(Decimal::of('100'))->toFixed(2),
                    'target_margin_pct' => Decimal::of($s['target_margin_rate'])->mul(Decimal::of('100'))->toFixed(2),
                    'proposed_price' => $s['proposed_price'],
                    'lines' => $s['lines'],
                ],
                'computed' => $computed,
                'rounded_price' => is_string($recommended) ? PricingCalculator::commercialRounding(Decimal::of($recommended), $rounding)?->toFixed(0) : null,
            ];
        }

        return [
            'simulation' => [
                'public_id' => $sim['public_id'], 'name' => $sim['name'], 'status' => $sim['status'], 'version' => $sim['version_number'],
                'finalized_at' => $sim['finalized_at'], 'archived_at' => $sim['archived_at'], 'calc_version' => $sim['calc_version'],
                'service' => $sim['service_public_id'] === null ? null : [
                    'public_id' => $sim['service_public_id'], 'name' => $sim['service_name'], 'category' => $sim['service_category'],
                    'current_price' => $sim['service_price'], 'annual_volume' => $sim['service_volume'],
                ],
                'affects_strategy' => $sim['status'] === 'finalized',
            ],
            'scenarios' => $scenarios,
            'frozen_context' => $frozen,
            'drift' => $frozen === null ? null : $this->drift($frozen, $inputs),
            'versions' => array_map(static fn (array $v): array => [
                'public_id' => $v['public_id'], 'version' => $v['version_number'], 'status' => $v['status'], 'finalized_at' => $v['finalized_at'],
            ], $this->repository->versions($org, $sim['family_id'])),
        ];
    }

    /**
     * Liste : une ligne par simulation, avec le prix recommandé (figé, ou recalculé pour un brouillon) du scénario de référence.
     *
     * @return list<array<string, mixed>>
     */
    public function index(OrgId $org): array
    {
        $inputs = $this->profiles->pricingInputs($org);
        $list = [];
        foreach ($this->repository->simulations($org) as $sim) {
            $reference = null;
            foreach ($this->repository->scenarios($org, $sim['id']) as $s) {
                if ($s['id'] !== $sim['reference_scenario_id']) {
                    continue;
                }
                $reference = $sim['status'] === 'draft'
                    ? $this->compute(self::scenarioData($s), $s['lines'], ['price' => $sim['service_price'], 'volume' => $sim['service_volume']], $inputs)
                    : $this->repository->frozenResults($org, $s['id'], $s['public_id']);
            }
            $list[] = [
                'public_id' => $sim['public_id'], 'name' => $sim['name'], 'status' => $sim['status'], 'version' => $sim['version_number'],
                'finalized_at' => $sim['finalized_at'], 'updated_at' => $sim['updated_at'],
                'service' => $sim['service_name'], 'service_category' => $sim['service_category'], 'current_price' => $sim['service_price'],
                'recommended_price' => self::pick($reference, 'results', 'recommended_price'),
                'gap_pct' => self::pick($reference, 'results', 'at_price', 'gap_to_recommended_pct'),
                'error' => self::pick($reference, 'error'),
            ];
        }

        return $list;
    }

    /**
     * Modèle de temps indicatif d'une catégorie (lignes modifiables, jamais appliqué sans demande).
     *
     * @return list<array{kind: string, label: string, hours: string, amount: null}>
     */
    public static function template(string $category): array
    {
        /** @var array{categories: array<string, list<array{0: string, 1: string}>>} $templates */
        $templates = require dirname(__DIR__) . '/Domain/Pricing/templates.php';
        $lines = $templates['categories'][$category] ?? $templates['categories']['other'];

        return array_map(static fn (array $l): array => ['kind' => 'time', 'label' => $l[0], 'hours' => $l[1], 'amount' => null], $lines);
    }

    /** Texte d'une alerte locale (affichée dans le simulateur, sans effet sur la stratégie). */
    public static function alertMessage(string $code, ?string $gapPct = null): string
    {
        $gap = $gapPct === null ? '' : str_replace('.', ',', Decimal::of($gapPct)->negate()->toFixed(1));

        return match ($code) {
            'price_below_floor' => 'Le prix testé est sous le prix plancher : à ce prix, la prestation fait perdre de l\'argent.',
            'price_below_recommended' => 'Le prix testé est ' . $gap . ' % sous le prix recommandé : la marge cible n\'est pas atteinte.',
            'variable_rate_zero' => 'Aucune charge variable générale dans votre profil : le taux appliqué est de 0 %.',
            'variable_rate_unavailable' => 'Taux de charges variables générales non calculable (profil incomplet) : 0 % est utilisé.',
            default => $code,
        };
    }

    public static function errorMessage(string $code): string
    {
        return match ($code) {
            'hourly_cost_unavailable' => 'Coût horaire non calculable : complétez votre profil financier ou saisissez un coût horaire personnalisé.',
            'margin_plus_variable_rate_too_high' => 'La marge cible et le taux de charges variables dépassent 100 % du prix : réduisez la marge cible.',
            'empty_scenario' => 'Ajoutez au moins une ligne de temps ou de frais.',
            'invalid_target_margin' => 'La marge cible doit être comprise entre 0 et 90 %.',
            'invalid_contingency' => 'Les aléas doivent être compris entre 0 et 100 %.',
            default => 'Calcul impossible avec ces paramètres.',
        };
    }

    /**
     * Calcul d'un scénario au format de l'API (docs/05 §6.1). Une erreur de calcul est renvoyée, pas levée.
     *
     * @param ScenarioData $data
     * @param list<LineData> $lines
     * @param array{price: string|null, volume: int|null} $service
     * @param array{hourly_cost: DerivedValue, variable_rate: DerivedValue, period: string|null} $inputs
     * @return array<string, mixed>
     */
    private function compute(array $data, array $lines, array $service, array $inputs): array
    {
        $hourly = $data['hourly_cost_mode'] === 'custom'
            ? ['value' => $data['hourly_cost_custom'] === null ? null : Decimal::of($data['hourly_cost_custom']), 'nature' => 'assumption', 'assumptions' => ['hourly_cost_custom'], 'source' => 'custom']
            : ['value' => $inputs['hourly_cost']->value, 'nature' => $inputs['hourly_cost']->nature, 'assumptions' => $inputs['hourly_cost']->assumptions, 'source' => 'profile'];
        $tv = $inputs['variable_rate']->value;
        $priceSource = $data['proposed_price'] !== null ? 'scenario' : ($service['price'] !== null ? 'catalog' : null);
        $price = $data['proposed_price'] ?? $service['price'];

        $out = [
            'nature' => 'simulated',
            'inputs' => [
                'hourly_cost' => ['value' => $hourly['value']?->toFixed(2), 'nature' => $hourly['nature'], 'assumptions' => $hourly['assumptions'], 'source' => $hourly['source']],
                'variable_rate_pct' => ['value' => $tv?->mul(Decimal::of('100'))->toFixed(2), 'available' => $tv !== null, 'period' => $inputs['period']],
                'contingency_pct' => Decimal::of($data['contingency_rate'])->mul(Decimal::of('100'))->toFixed(2),
                'target_margin_pct' => Decimal::of($data['target_margin_rate'])->mul(Decimal::of('100'))->toFixed(2),
                'price' => $price === null ? null : Decimal::of($price)->toFixed(2),
                'price_source' => $priceSource,
                'annual_volume' => $service['volume'],
                // Valeurs exactes conservées pour pouvoir recalculer à l'identique une version figée.
                'exact' => ['hourly_cost' => $hourly['value']?->toString(), 'variable_rate' => $tv?->toString()],
            ],
            'results' => null,
            'alerts' => [],
            'error' => null,
            'affects_strategy' => false,
        ];
        if ($hourly['value'] === null) {
            $out['error'] = 'hourly_cost_unavailable';

            return $out;
        }
        try {
            $result = $this->calculator->calculate(new ScenarioInput(
                $hourly['value'],
                Decimal::of($data['contingency_rate']),
                Decimal::of($data['target_margin_rate']),
                $tv ?? Decimal::zero(),
                array_values(array_map(static fn (array $l): Decimal => Decimal::of($l['hours'] ?? '0'), array_filter($lines, static fn (array $l): bool => $l['kind'] === 'time'))),
                array_values(array_map(static fn (array $l): Decimal => Decimal::of($l['amount'] ?? '0'), array_filter($lines, static fn (array $l): bool => $l['kind'] === 'direct_cost'))),
                $price === null ? null : Decimal::of($price),
                $service['volume'],
            ));
        } catch (PricingException $e) {
            $out['error'] = $e->reason;

            return $out;
        }
        $array = $result->toArray();
        $alerts = $array['alerts'];
        unset($array['alerts']);
        if ($tv === null) {
            $alerts = array_values(array_filter($alerts, static fn (array $a): bool => $a['code'] !== 'variable_rate_zero'));
            $alerts[] = ['code' => 'variable_rate_unavailable', 'level' => 'info'];
        }
        $out['results'] = $array;
        $out['alerts'] = $alerts;

        return $out;
    }

    /**
     * @param array<string, mixed> $frozen
     * @param array{hourly_cost: DerivedValue, variable_rate: DerivedValue, period: string|null} $inputs
     * @return array{hourly_cost: bool, variable_rate: bool}
     */
    private function drift(array $frozen, array $inputs): array
    {
        $current = self::exact($inputs['hourly_cost']);
        $currentTv = self::exact($inputs['variable_rate']);
        $same = static function (mixed $a, ?string $b, int $scale): bool {
            if (!is_string($a) || $b === null) {
                return $a === $b;
            }

            return Decimal::of($a)->toFixed($scale) === Decimal::of($b)->toFixed($scale);
        };
        $usesProfile = false;
        foreach ((array) ($frozen['scenarios'] ?? []) as $inputsUsed) {
            if (is_array($inputsUsed) && is_array($inputsUsed['hourly_cost'] ?? null) && ($inputsUsed['hourly_cost']['source'] ?? null) === 'profile') {
                $usesProfile = true;
            }
        }

        return [
            'hourly_cost' => $usesProfile && !$same($frozen['profile_hourly_cost'] ?? null, $current, 2),
            'variable_rate' => !$same($frozen['variable_rate'] ?? null, $currentTv, 6),
        ];
    }

    /** Lecture typée d'une valeur chaîne dans un tableau imbriqué (résultats calculés ou figés). */
    private static function pick(mixed $data, string ...$path): ?string
    {
        foreach ($path as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return is_string($data) ? $data : null;
    }

    private static function exact(DerivedValue $value): ?string
    {
        return $value->value?->toString();
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: ScenarioData, 1: list<LineData>}
     */
    private function validateScenario(array $input): array
    {
        $lines = $input['lines'] ?? [];
        unset($input['lines']);
        $v = Validator::make($input, self::RULES);
        $errors = $v->fails() ? $v->errors() : [];
        if (!is_array($lines) || !array_is_list($lines)) {
            $errors['lines'] = 'invalid_lines';
            $lines = [];
        }
        if (count($lines) > self::MAX_LINES) {
            $errors['lines'] = 'too_many_lines';
        }
        $cleanLines = [];
        foreach ($lines as $i => $line) {
            if (!is_array($line)) {
                $errors["lines.{$i}"] = 'invalid_lines';
                continue;
            }
            /** @var array<string, mixed> $line */
            $lv = Validator::make($line, self::LINE_RULES);
            if ($lv->fails()) {
                foreach ($lv->errors() as $field => $code) {
                    $errors["lines.{$i}.{$field}"] = $code;
                }
                continue;
            }
            $l = $lv->validated();
            $kind = is_string($l['kind'] ?? null) ? $l['kind'] : 'time';
            $hours = ($l['hours'] ?? null) instanceof Decimal ? $l['hours'] : null;
            $amount = ($l['amount'] ?? null) instanceof Decimal ? $l['amount'] : null;
            if ($kind === 'time' && $hours === null) {
                $errors["lines.{$i}.hours"] = 'required';
                continue;
            }
            if ($kind === 'direct_cost' && $amount === null) {
                $errors["lines.{$i}.amount"] = 'required';
                continue;
            }
            $cleanLines[] = [
                'kind' => $kind,
                'label' => is_string($l['label'] ?? null) ? $l['label'] : '',
                'hours' => $kind === 'time' ? $hours?->toFixed(2) : null,
                'amount' => $kind === 'direct_cost' ? $amount?->toFixed(2) : null,
            ];
        }
        $valid = $v->fails() ? [] : $v->validated();
        $mode = is_string($valid['hourly_cost_mode'] ?? null) ? $valid['hourly_cost_mode'] : 'profile';
        $custom = ($valid['hourly_cost_custom'] ?? null) instanceof Decimal ? $valid['hourly_cost_custom'] : null;
        if ($mode === 'custom' && $custom === null && !isset($errors['hourly_cost_custom'])) {
            $errors['hourly_cost_custom'] = 'required';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $pct = static fn (mixed $d, string $default): string => $d instanceof Decimal ? $d->toFixed(4) : $default;
        $price = ($valid['proposed_price'] ?? null) instanceof Decimal ? $valid['proposed_price'] : null;

        return [[
            'label' => is_string($valid['label'] ?? null) ? $valid['label'] : 'Scénario',
            'hourly_cost_mode' => $mode,
            'hourly_cost_custom' => $mode === 'custom' ? $custom?->toFixed(2) : null,
            'contingency_rate' => $pct($valid['contingency_pct'] ?? null, '0.0000'),
            'target_margin_rate' => $pct($valid['target_margin_pct'] ?? null, self::DEFAULT_MARGIN),
            'proposed_price' => $price?->toFixed(2),
        ], $cleanLines];
    }

    /**
     * @param array{label: string, hourly_cost_mode: string, hourly_cost_custom: string|null, contingency_rate: string, target_margin_rate: string, proposed_price: string|null} $s
     * @return ScenarioData
     */
    private static function scenarioData(array $s): array
    {
        return [
            'label' => $s['label'], 'hourly_cost_mode' => $s['hourly_cost_mode'], 'hourly_cost_custom' => $s['hourly_cost_custom'],
            'contingency_rate' => $s['contingency_rate'], 'target_margin_rate' => $s['target_margin_rate'], 'proposed_price' => $s['proposed_price'],
        ];
    }

    /** @return ScenarioData */
    private static function defaultScenario(string $label): array
    {
        return ['label' => $label, 'hourly_cost_mode' => 'profile', 'hourly_cost_custom' => null, 'contingency_rate' => '0.0000',
            'target_margin_rate' => self::DEFAULT_MARGIN, 'proposed_price' => null];
    }

    /** @return SimulationRow */
    private function requireDraft(OrgId $org, string $publicId): array
    {
        $sim = $this->repository->find($org, $publicId) ?? throw new NotFoundException();
        if ($sim['status'] !== 'draft') {
            throw new ConflictException();
        }

        return $sim;
    }
}
