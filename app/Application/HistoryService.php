<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Database\OrgId;
use App\Infrastructure\Repositories\DiagnosticRepository;
use App\Infrastructure\Repositories\RecurringRepository;
use App\Infrastructure\Repositories\SnapshotRepository;
use App\Support\Clock;
use App\Support\Decimal;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Historique des indicateurs (docs/05 §4) : instantané quotidien (heure de Paris, jamais d'écrasement) et séries
 * jour par jour où un jour sans instantané reste MANQUANT (aucune interpolation).
 */
final class HistoryService
{
    public const CALC_VERSION = '1.0';
    public const METRICS = ['estimated_result', 'mrr_contracted', 'maturity_score', 'hourly_cost', 'revenue_annualized', 'action_progress'];

    public function __construct(
        private readonly SnapshotRepository $snapshots,
        private readonly FinancialProfileService $profiles,
        private readonly RecurringService $recurring,
        private readonly RecurringRepository $recurringRepository,
        private readonly DiagnosticRepository $diagnostics,
        private readonly StrategyService $strategy,
        private readonly Clock $clock,
    ) {
    }

    /** Capture l'état du jour (valeurs non calculables : NULL, jamais 0). Faux si l'instantané existait déjà. */
    public function capture(OrgId $org, string $orgPublicId, string $date, ?string $capturedAt = null): bool
    {
        $derived = $this->profiles->derived($org);
        $inputs = $this->profiles->pricingInputs($org);
        $value = static function (mixed $field): ?Decimal {
            return is_array($field) && is_string($field['value'] ?? null) ? Decimal::of($field['value']) : null;
        };
        $maturity = null;
        $reliability = null;
        $latest = $this->diagnostics->latestCompleted($org);
        if ($latest !== null) {
            $global = $this->diagnostics->scores($org, $latest['id'])['global'] ?? null;
            $maturity = $global === null || $global['score'] === null ? null : Decimal::of($global['score']);
            $reliability = $global === null ? null : Decimal::of($global['reliability']);
        }
        $mrr = $this->recurringRepository->offers($org) === [] ? null : $this->recurring->summary($org)['mrr'];
        $progress = $this->strategy->plan($org)['progress'];
        /** @var array{rate: int, missing: list<string>} $completeness */
        $completeness = $derived['completeness'];

        return $this->snapshots->insert($org, $orgPublicId, $date, $capturedAt ?? $this->clock->nowUtc()->format('Y-m-d H:i:s'), [
            'revenue_annualized' => $inputs['annualized_revenue']->value,
            'total_costs_annualized' => $value($derived['total_costs']),
            'estimated_result' => $value($derived['estimated_result']),
            'mrr_contracted' => is_string($mrr) ? Decimal::of($mrr) : null,
            'recurring_share_declared' => $inputs['declared_share']?->mul(Decimal::of('100')),
            'hourly_cost' => $inputs['hourly_cost']->value,
            'maturity_score' => $maturity,
            'maturity_reliability' => $reliability,
            'action_progress' => $progress['total'] === 0 ? null : Decimal::of($progress['pct']),
        ], [
            'period' => $inputs['period'],
            'hourly_cost_nature' => $inputs['hourly_cost']->nature,
            'hourly_cost_assumptions' => $inputs['hourly_cost']->assumptions,
            'missing' => $completeness['missing'],
        ], self::CALC_VERSION);
    }

    /**
     * Série jour par jour sur N jours (jusqu'à aujourd'hui inclus, heure de Paris). Jour manquant : {date, missing: true}.
     *
     * @return array{days: list<array<string, mixed>>, missing: list<string>, count: int}
     */
    public function series(OrgId $org, string $orgPublicId, int $days): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $today = $this->today();
        $from = $today->modify('-' . ($days - 1) . ' days');
        $byDate = [];
        foreach ($this->snapshots->between($org, $orgPublicId, $from->format('Y-m-d'), $today->format('Y-m-d')) as $s) {
            $byDate[$s['date']] = $s;
        }
        $out = [];
        $missing = [];
        for ($d = $from; $d <= $today; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            if (isset($byDate[$key])) {
                $out[] = $byDate[$key] + ['missing' => false];
            } else {
                $out[] = ['date' => $key, 'missing' => true];
                $missing[] = $key;
            }
        }

        return ['days' => $out, 'missing' => $missing, 'count' => count($byDate)];
    }

    /**
     * Démo : 30 jours d'instantanés FICTIFS (dont deux jours manquants, pour illustrer l'absence d'interpolation),
     * qui convergent vers l'état actuel de Lumen.
     */
    public function seedDemo(OrgId $org, string $orgPublicId): void
    {
        $today = $this->today();
        $missing = [9, 10];
        for ($i = 29; $i >= 1; $i--) {
            if (in_array($i, $missing, true)) {
                continue;
            }
            $date = $today->modify("-{$i} days");
            $drift = Decimal::of((string) $i);
            $this->snapshots->insert($org, $orgPublicId, $date->format('Y-m-d'), $date->setTime(2, 15)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), [
                'revenue_annualized' => Decimal::of('180000'),
                'total_costs_annualized' => Decimal::of('155600')->add($drift->mul(Decimal::of('40'))),
                'estimated_result' => Decimal::of('24400')->sub($drift->mul(Decimal::of('40'))),
                'mrr_contracted' => Decimal::of('2660')->sub(Decimal::of((string) intdiv($i, 7))->mul(Decimal::of('150'))),
                'recurring_share_declared' => Decimal::of('25'),
                'hourly_cost' => Decimal::of('67.01'),
                'maturity_score' => $i > 14 ? null : Decimal::of('58.06'),
                'maturity_reliability' => $i > 14 ? null : Decimal::of('0.9667'),
                'action_progress' => null,
            ], ['period' => 'Exercice 2025', 'demo' => true], self::CALC_VERSION);
        }
        $this->capture($org, $orgPublicId, $today->format('Y-m-d'));
    }

    private function today(): DateTimeImmutable
    {
        $paris = $this->clock->nowUtc()->setTimezone(new DateTimeZone('Europe/Paris'));

        return new DateTimeImmutable($paris->format('Y-m-d'), new DateTimeZone('Europe/Paris'));
    }
}
