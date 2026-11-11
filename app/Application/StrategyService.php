<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Strategy\Facts;
use App\Domain\Strategy\RuleCatalog;
use App\Domain\Strategy\RuleEngine;
use App\Infrastructure\Database\OrgId;
use App\Infrastructure\Repositories\DiagnosticRepository;
use App\Infrastructure\Repositories\OrganizationRepository;
use App\Infrastructure\Repositories\ServiceRepository;
use App\Infrastructure\Repositories\StrategyRepository;
use App\Support\Clock;
use App\Support\Decimal;
use App\Support\Validator;
use DateTimeZone;

/**
 * Module Stratégie (docs/03 §7, docs/05 §2 et §3.4, docs/07 §4.7) : collecte des faits (données réelles et figées
 * uniquement), évaluation des règles, réconciliation, cycle de vie des recommandations et plan d'action.
 *
 * L'évaluation est déclenchée après chaque enregistrement qui change les faits (profil, prestations, simulation
 * finalisée, formules, diagnostic terminé), et manuellement. Un brouillon ne déclenche jamais de recommandation.
 *
 * @phpstan-import-type RecoRow from StrategyRepository
 * @phpstan-import-type ActionRow from StrategyRepository
 */
final class StrategyService
{
    public const STATUSES = ['new', 'accepted', 'snoozed', 'dismissed', 'resolved'];
    public const SNOOZE_DAYS = [7, 30, 90];

    public function __construct(
        private readonly StrategyRepository $repository,
        private readonly FinancialProfileService $profiles,
        private readonly ServiceRepository $services,
        private readonly PricingService $pricing,
        private readonly RecurringService $recurring,
        private readonly DiagnosticRepository $diagnostics,
        private readonly OrganizationRepository $organizations,
        private readonly Clock $clock,
        private readonly RuleEngine $engine = new RuleEngine(),
    ) {
    }

    /** Collecte des faits : profil, prestations actives et leur simulation ACTIVE, formules actives, dernier diagnostic TERMINÉ. */
    public function facts(OrgId $org): Facts
    {
        $inputs = $this->profiles->pricingInputs($org);
        $derived = $this->profiles->derived($org);
        $profile = $this->profiles->get($org);
        $references = $this->pricing->activeReferences($org);
        $summary = $this->recurring->summary($org);

        $services = [];
        foreach ($this->services->all($org, true) as $s) {
            $services[] = [
                'public_id' => $s['public_id'], 'name' => $s['name'], 'price' => $s['current_price'] === null ? null : Decimal::of($s['current_price']),
                'volume' => $s['annual_volume'], 'simulation' => $references[$s['public_id']] ?? null,
            ];
        }
        $offers = [];
        foreach ($this->recurring->offers($org) as $o) {
            if (!$o['is_active']) {
                continue;
            }
            $econ = $o['economics'];
            $offers[] = [
                'public_id' => $o['public_id'], 'name' => $o['name'], 'contracts' => $o['active_contracts'],
                'monthly_result' => $econ['monthly_result'] === null ? null : Decimal::of($econ['monthly_result']),
                'margin' => $econ['margin_on_price_pct'] === null ? null : Decimal::of($econ['margin_on_price_pct'])->div(Decimal::of('100')),
            ];
        }
        $diagnostic = null;
        $latest = $this->diagnostics->latestCompleted($org);
        if ($latest !== null && $latest['completed_at'] !== null) {
            $scores = $this->diagnostics->scores($org, $latest['id']);
            $labels = ['pos' => 'Positionnement et offres', 'acq' => 'Acquisition et développement commercial', 'fin' => 'Rentabilité et pilotage financier',
                'org' => 'Organisation et processus internes', 'ret' => 'Fidélisation et revenus récurrents', 'str' => 'Stratégie et développement'];
            $dims = [];
            foreach ($labels as $code => $label) {
                if (isset($scores[$code])) {
                    $dims[$code] = ['label' => $label, 'scorable' => $scores[$code]['is_scorable'], 'score' => $scores[$code]['score'] === null ? null : Decimal::of($scores[$code]['score'])];
                }
            }
            $diagnostic = [
                'public_id' => $latest['public_id'], 'completed_at' => $latest['completed_at'], 'completeness' => $latest['completeness'] ?? 'complete',
                'reliability' => Decimal::of($scores['global']['reliability'] ?? '0'), 'dimensions' => $dims,
            ];
        }
        /** @var array{rate: int} $completeness */
        $completeness = $derived['completeness'];
        $estimated = $derived['estimated_result'];
        $estimatedValue = is_array($estimated) && is_string($estimated['value'] ?? null) ? Decimal::of($estimated['value']) : null;
        $periodEnd = null;
        try {
            $periodEnd = $profile->period()?->end->format('Y-m-d');
        } catch (\InvalidArgumentException) {
        }

        return new Facts(
            today: $this->clock->nowUtc()->setTimezone(new DateTimeZone('Europe/Paris')),
            estimatedResult: $estimatedValue,
            hourlyCost: $inputs['hourly_cost']->value,
            hourlyCostAssumptions: $inputs['hourly_cost']->nature === 'assumption' ? [] : $inputs['hourly_cost']->assumptions,
            revenueAnnualized: $inputs['annualized_revenue']->value,
            revenueTarget: $profile->revenueTarget,
            declaredShare: $inputs['declared_share'],
            profileCompleteness: $completeness['rate'],
            profileUpdatedAt: $this->profiles->updatedAt($org),
            periodEnd: $periodEnd,
            periodLabel: $inputs['period'],
            services: $services,
            offers: $offers,
            coverage: is_string($summary['coverage_pct']) ? Decimal::of($summary['coverage_pct'])->div(Decimal::of('100')) : null,
            coverageGapPoints: is_string($summary['gap_points']) ? Decimal::of($summary['gap_points']) : null,
            recurringHours: Decimal::of(is_string($summary['hours_needed']) ? $summary['hours_needed'] : '0'),
            monthlyCapacity: $inputs['monthly_capacity']->value,
            contractsUpdatedAt: is_string($summary['contracts_updated_at']) ? $summary['contracts_updated_at'] : null,
            diagnostic: $diagnostic,
        );
    }

    /** @return array{created: int, updated: int, reappeared: int, resolved: int} */
    public function evaluate(OrgId $org): array
    {
        $facts = $this->facts($org);

        return $this->repository->reconcile($org, $this->engine->evaluate($facts), RuleEngine::RULE_VERSION, $facts->today->format('Y-m-d'));
    }

    /**
     * Recommandations par onglet (nouvelles, acceptées, reportées, ignorées, résolues), triées par priorité.
     *
     * @return array{items: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function list(OrgId $org, string $tab = 'new', ?string $module = null): array
    {
        $all = $this->repository->recommendations($org);
        $counts = array_fill_keys(self::STATUSES, 0);
        $items = [];
        $isDemo = $this->organizations->get($org)['is_demo'];
        foreach ($all as $r) {
            $group = self::tab($r['status']);
            $counts[$group]++;
            $view = $this->view($org, $r, $isDemo);
            if ($group === $tab && ($module === null || (is_array($view['modules']) && in_array($module, $view['modules'], true)))) {
                $items[] = $view;
            }
        }

        return ['items' => $items, 'counts' => $counts];
    }

    /** @return array{top: list<array<string, mixed>>, counts: array<string, int>, progress: array{done: int, total: int, pct: int}} */
    public function overview(OrgId $org): array
    {
        $list = $this->list($org, 'new');
        $plan = $this->plan($org);

        return ['top' => array_slice($list['items'], 0, 3), 'counts' => $list['counts'], 'progress' => $plan['progress']];
    }

    public function accept(OrgId $org, string $publicId): string
    {
        $r = $this->requireReco($org, $publicId);
        $existing = $this->repository->actionForRecommendation($org, $r['id']);
        if (!in_array($r['status'], ['new', 'snoozed', 'dismissed'], true) && $existing !== null) {
            return $existing;
        }
        $this->repository->setStatus($org, $r['id'], 'accepted');
        if ($existing !== null) {
            return $existing;
        }
        $content = RuleCatalog::describe($r['rule_code'], $r['evidence'], $r['impact_amount']);

        return $this->repository->createAction($org, $r['id'], mb_substr($content['title'], 0, 160), $content['explanation'], null, $content['actions']);
    }

    public function dismiss(OrgId $org, string $publicId): void
    {
        $r = $this->requireReco($org, $publicId);
        $this->repository->setStatus($org, $r['id'], 'dismissed', null, $r['severity']);
    }

    /** @param array<string, mixed> $input {days: 7|30|90} */
    public function snooze(OrgId $org, string $publicId, array $input): string
    {
        $r = $this->requireReco($org, $publicId);
        $days = $input['days'] ?? null;
        $days = is_int($days) ? $days : (is_string($days) && ctype_digit($days) ? (int) $days : 0);
        if (!in_array($days, self::SNOOZE_DAYS, true)) {
            throw new ValidationException(['days' => 'invalid_choice']);
        }
        $until = $this->clock->nowUtc()->modify("+{$days} days")->format('Y-m-d');
        $this->repository->setStatus($org, $r['id'], 'snoozed', $until);

        return $until;
    }

    public function reopen(OrgId $org, string $publicId): void
    {
        $r = $this->requireReco($org, $publicId);
        if (in_array($r['status'], ['auto_resolved', 'done'], true)) {
            throw new ValidationException(['status' => 'Recommandation résolue : elle réapparaîtra automatiquement si le problème revient.']);
        }
        $this->repository->setStatus($org, $r['id'], 'new');
    }

    /**
     * Plan d'action : actions groupées par statut, échéances, retards, sous-actions et progression.
     *
     * @return array{groups: array<string, list<array<string, mixed>>>, progress: array{done: int, total: int, pct: int}}
     */
    public function plan(OrgId $org): array
    {
        $today = $this->clock->nowUtc()->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d');
        $groups = ['todo' => [], 'in_progress' => [], 'done' => []];
        $done = 0;
        $actions = $this->repository->actions($org);
        $isDemo = $this->organizations->get($org)['is_demo'];
        foreach ($actions as $a) {
            $reco = $a['recommendation_id'] === null ? null : $this->repository->recommendationById($org, $a['recommendation_id']);
            $groups[$a['status']][] = [
                'public_id' => $a['public_id'], 'title' => $a['title'], 'description' => $a['description'], 'status' => $a['status'],
                'due_date' => $a['due_date'], 'overdue' => $a['status'] !== 'done' && $a['due_date'] !== null && $a['due_date'] < $today,
                'completed_at' => $a['completed_at'], 'tasks' => $a['tasks'],
                'tasks_done' => count(array_filter($a['tasks'], static fn (array $t): bool => $t['is_done'])),
                'recommendation' => $reco === null ? null : ['public_id' => $reco['public_id'], 'status' => $reco['status'], 'title' => $this->view($org, $reco, $isDemo)['title']],
                'goal_reached' => $reco !== null && $reco['status'] === 'auto_resolved',
            ];
            if ($a['status'] === 'done') {
                $done++;
            }
        }
        $total = count($actions);

        return ['groups' => $groups, 'progress' => ['done' => $done, 'total' => $total, 'pct' => $total === 0 ? 0 : intdiv(100 * $done, $total)]];
    }

    /** @param array<string, mixed> $input {title, description?, due_date?} */
    public function createAction(OrgId $org, array $input): string
    {
        $v = Validator::make($input, ['title' => ['required', 'string', 'min:2', 'max:160'], 'description' => ['nullable', 'string', 'max:2000'], 'due_date' => ['nullable', 'date']]);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $valid = $v->validated();
        if (count($this->repository->actions($org)) >= 200) {
            throw new ValidationException(['title' => 'quota_reached']);
        }

        return $this->repository->createAction(
            $org, null, is_string($valid['title'] ?? null) ? $valid['title'] : '', is_string($valid['description'] ?? null) ? $valid['description'] : null,
            is_string($valid['due_date'] ?? null) ? $valid['due_date'] : null, [],
        );
    }

    /** @param array<string, mixed> $input {title?, description?, status?, due_date?} */
    public function updateAction(OrgId $org, string $publicId, array $input): void
    {
        $action = $this->repository->action($org, $publicId) ?? throw new NotFoundException();
        $v = Validator::make($input, [
            'title' => ['string', 'min:2', 'max:160'], 'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['enum:todo,in_progress,done'], 'due_date' => ['nullable', 'date'],
        ]);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        /** @var array{title?: string, description?: string|null, status?: string, due_date?: string|null} $fields */
        $fields = array_intersect_key($v->validated(), array_flip(['title', 'description', 'status', 'due_date']));
        $this->repository->updateAction($org, $action['id'], $fields);
        // L'action liée à une recommandation la fait passer en « terminée » (ou la rouvre).
        if (isset($fields['status']) && $action['recommendation_id'] !== null) {
            $reco = $this->repository->recommendationById($org, $action['recommendation_id']);
            if ($reco !== null && $reco['status'] !== 'auto_resolved') {
                if ($fields['status'] === 'done') {
                    $this->repository->setStatus($org, $reco['id'], 'done');
                } elseif ($reco['status'] === 'done') {
                    $this->repository->setStatus($org, $reco['id'], 'accepted');
                }
            }
        }
    }

    public function deleteAction(OrgId $org, string $publicId): void
    {
        $action = $this->repository->action($org, $publicId) ?? throw new NotFoundException();
        $this->repository->deleteAction($org, $action['id']);
        if ($action['recommendation_id'] !== null) {
            $reco = $this->repository->recommendationById($org, $action['recommendation_id']);
            if ($reco !== null && $reco['status'] === 'accepted') {
                $this->repository->setStatus($org, $reco['id'], 'new');
            }
        }
    }

    /** @param array<string, mixed> $input {label} */
    public function addTask(OrgId $org, string $actionPublicId, array $input): string
    {
        $action = $this->repository->action($org, $actionPublicId) ?? throw new NotFoundException();
        $v = Validator::make($input, ['label' => ['required', 'string', 'min:1', 'max:200']]);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        if (count($action['tasks']) >= 30) {
            throw new ValidationException(['label' => 'quota_reached']);
        }
        $label = $v->validated()['label'] ?? '';

        return $this->repository->addTask($org, $action['id'], is_string($label) ? $label : '');
    }

    /** @param array<string, mixed> $input {is_done: bool} */
    public function updateTask(OrgId $org, string $taskPublicId, array $input): void
    {
        $this->repository->task($org, $taskPublicId) ?? throw new NotFoundException();
        $v = Validator::make($input, ['is_done' => ['bool']]);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $this->repository->setTask($org, $taskPublicId, ($v->validated()['is_done'] ?? false) === true);
    }

    public function deleteTask(OrgId $org, string $taskPublicId): void
    {
        $this->repository->task($org, $taskPublicId) ?? throw new NotFoundException();
        $this->repository->deleteTask($org, $taskPublicId);
    }

    /**
     * @param RecoRow $r
     * @return array<string, mixed>
     */
    private function view(OrgId $org, array $r, bool $isDemo): array
    {
        $content = RuleCatalog::describe($r['rule_code'], $r['evidence'], $r['impact_amount']);
        /** @var list<array<string, mixed>> $sources */
        $sources = is_array($r['evidence']['sources'] ?? null) ? $r['evidence']['sources'] : [];
        $priorityLabel = match (true) {
            $r['priority_score'] >= 90 => 'Critique',
            $r['priority_score'] >= 70 => 'Haute',
            $r['priority_score'] >= 45 => 'Moyenne',
            default => 'Faible',
        };

        return $content + [
            'public_id' => $r['public_id'], 'rule_code' => $r['rule_code'], 'status' => $r['status'], 'tab' => self::tab($r['status']),
            'severity' => $r['severity'], 'priority' => $r['priority_score'], 'priority_label' => $priorityLabel,
            'impact_kind' => $r['impact_kind'], 'impact_amount' => $r['impact_amount'],
            'data_quality' => $r['data_quality'], 'missing' => $r['evidence']['missing'] ?? [], 'stale' => $r['evidence']['stale'] ?? [],
            'sources' => array_map(static fn (array $s): string => RuleCatalog::sourceLabel($s), $sources),
            'values' => $r['evidence']['values'] ?? [], 'thresholds' => $r['evidence']['thresholds'] ?? [],
            'reappeared' => $r['reappeared'], 'snoozed_until' => $r['snoozed_until'], 'first_detected_at' => $r['first_detected_at'],
            'last_evaluated_at' => $r['last_evaluated_at'], 'resolved_at' => $r['resolved_at'], 'is_demo' => $isDemo,
            'action' => $this->repository->actionForRecommendation($org, $r['id']),
        ];
    }

    private static function tab(string $status): string
    {
        return match ($status) {
            'auto_resolved', 'done' => 'resolved',
            default => $status,
        };
    }

    /** @return RecoRow */
    private function requireReco(OrgId $org, string $publicId): array
    {
        return $this->repository->recommendation($org, $publicId) ?? throw new NotFoundException();
    }
}
