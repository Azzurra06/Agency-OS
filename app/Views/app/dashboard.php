<?php
/** @var array<string, mixed> $derived */
/** @var list<array{name: string, current_price: string|null}> $services */
/** @var bool $isDemo */
/** @var array{mrr: string, arr: string, coverage_pct: string|null} $recurring */
/** @var bool $hasOffers */
/** @var array{days: list<array<string, mixed>>, missing: list<string>, count: int} $history */
/** @var string $historyMetric */
/** @var array{top: list<array{title: string, rule_code: string, priority_label: string, impact_kind: string, impact_amount: string|null}>, progress: array{done: int, total: int, pct: int}} $strategy */
/** @var array{draft: array{dimensions_done: int, dimensions: int}|null, latest: array{public_id: string, completed_at: string, global_display: string|null, level: string|null, reliability_label: string|null}|null} $diagnostic */
use App\Support\FinanceFields;
use App\Support\Format;

/** @var array{value: string|null, nature: string, assumptions: list<string>, missing: list<string>, rate_pct: string|null, includes: list<string>, excludes: list<string>} $result */
$result = $derived['estimated_result'];
/** @var array{value: string|null} $revenue */
$revenue = $derived['revenue_annualized'];
/** @var array{value: string|null} $costs */
$costs = $derived['total_costs'];
/** @var array{value: string|null, nature: string, assumptions: list<string>, missing: list<string>} $hourly */
$hourly = $derived['hourly_cost'];
/** @var array{value: string|null} $capacity */
$capacity = $derived['monthly_capacity'];
/** @var array{value: string|null} $tv */
$tv = $derived['general_variable_rate'];
/** @var array{value: string, year: int|null, progress_pct: string|null, gap: string|null}|null $target */
$target = $derived['revenue_target'];
/** @var array{value: string}|null $share */
$share = $derived['recurring_share_declared'];
/** @var array{label: string}|null $period */
$period = $derived['period'];
/** @var array{rate: int, missing: list<string>} $completeness */
$completeness = $derived['completeness'];

$targetPct = $target['progress_pct'] ?? null;
$latestDiag = $diagnostic['latest'];
$draftDiag = $diagnostic['draft'];
$hourlyLabel = $hourly['assumptions'] === [] ? 'Calculé' : sprintf('Calculé, %d hypothèse%s', count($hourly['assumptions']), count($hourly['assumptions']) > 1 ? 's' : '');
?>
<header class="page-header">
  <div class="page-header__titles">
    <h1>Vue d'ensemble</h1>
    <p class="page-header__sub">Où en est votre agence ?</p>
  </div>
  <span class="period-pill"><?= ui_icon('calendar', 'sm') ?><?= e($period['label'] ?? 'Période de référence non renseignée') ?></span>
</header>

<section class="kpi-grid" aria-label="Indicateurs principaux">
  <article class="card kpi<?php if ($result['value'] === null): ?> kpi--empty<?php endif; ?>">
    <div class="kpi__top">
      <div class="card__title"><h2 class="kpi__label">Résultat estimé</h2><?= ui_term('resultat-estime', 'Estimation de gestion : elle ne remplace pas votre bilan.', 'start', array_merge(['Inclus :'], $result['includes'], ['Exclus :'], $result['excludes'])) ?></div>
      <?php if ($result['value'] !== null): ?><?= ui_nature('calculated') ?><?php endif; ?>
    </div>
    <?php if ($result['value'] !== null): ?>
      <p class="kpi__value"><?= e(Format::number($result['value'], 0)) ?><span class="kpi__unit">€</span></p>
      <div class="kpi__meta">
        <span><span class="num"><?= e(Format::percent($result['rate_pct'])) ?></span> du CA · CA <span class="num"><?= e(Format::money($revenue['value'], 0)) ?></span> · charges <span class="num"><?= e(Format::money($costs['value'], 0)) ?></span></span>
      </div>
    <?php else: ?>
      <p class="kpi__value">Non calculable</p>
      <div class="kpi__meta"><span>Il manque : <?= e(implode(', ', array_map([FinanceFields::class, 'label'], $result['missing']))) ?>.</span><a href="/app/parametres/finances">Compléter</a></div>
    <?php endif; ?>
  </article>

  <article class="card kpi<?php if ($latestDiag === null): ?> kpi--empty<?php endif; ?>">
    <div class="kpi__top">
      <div class="card__title"><h2 class="kpi__label">Maturité business</h2><?= ui_term('score-maturite', 'Calculé à partir de votre dernier diagnostic terminé. Autoévaluation indicative.') ?></div>
      <?php if ($latestDiag !== null): ?><?= ui_nature('frozen', 'Figé le ' . Format::date(substr((string) $latestDiag['completed_at'], 0, 10))) ?><?php endif; ?>
    </div>
    <?php if ($latestDiag === null): ?>
      <p class="kpi__value">Pas encore évaluée</p>
      <div class="kpi__meta">
        <?php if ($draftDiag !== null): ?>
          <span>Diagnostic en cours : <?= e($draftDiag['dimensions_done']) ?>/<?= e($draftDiag['dimensions']) ?> dimensions.</span><a href="/app/diagnostic">Reprendre</a>
        <?php else: ?>
          <span>Faites le diagnostic en 10 minutes.</span><a href="/app/diagnostic">Commencer</a>
        <?php endif; ?>
      </div>
    <?php elseif ($latestDiag['global_display'] !== null): ?>
      <div class="kpi__ring">
        <div class="score-ring" data-value="<?= attr($latestDiag['global_display']) ?>"><span class="score-ring__value"><?= e($latestDiag['global_display']) ?></span></div>
        <div class="stack stack--sm"><p class="diag-level"><?= e($latestDiag['level']) ?></p><span class="text-small text-secondary"><?= e($latestDiag['reliability_label']) ?></span></div>
      </div>
      <div class="kpi__meta"><a href="/app/diagnostic/resultats/<?= attr($latestDiag['public_id']) ?>">Voir le détail</a></div>
    <?php else: ?>
      <div class="kpi__ring">
        <div class="score-ring score-ring--partial"><span class="score-ring__value">—</span></div>
        <div class="stack stack--sm"><p class="diag-level">Diagnostic partiel</p><span class="text-small text-secondary">Score global disponible lorsque les 6 dimensions sont renseignées.</span></div>
      </div>
      <div class="kpi__meta"><a href="/app/diagnostic/resultats/<?= attr($latestDiag['public_id']) ?>">Compléter</a></div>
    <?php endif; ?>
  </article>

  <article class="card kpi<?php if (!$hasOffers): ?> kpi--empty<?php endif; ?>">
    <div class="kpi__top">
      <div class="card__title"><h2 class="kpi__label">MRR contractualisé</h2><?= ui_term('mrr', 'Les scénarios simulés ne sont jamais ajoutés à ce montant.') ?></div>
      <?php if ($hasOffers): ?><?= ui_nature('calculated') ?><?php endif; ?>
    </div>
    <?php if ($hasOffers): ?>
      <p class="kpi__value"><?= e(Format::number($recurring['mrr'], 0)) ?><span class="kpi__unit">€ / mois</span></p>
      <div class="kpi__meta">
        <span>ARR <span class="num"><?= e(Format::money($recurring['arr'], 0)) ?></span><?php if ($recurring['coverage_pct'] !== null): ?> · <span class="num"><?= e(Format::percent($recurring['coverage_pct'])) ?></span> du CA<?php endif; ?></span>
        <?php if ($share !== null): ?><span>Part récurrente déclarée : <span class="num"><?= e(Format::percent($share['value'], 0)) ?></span> <?= ui_nature('declared') ?></span><?php endif; ?>
        <a href="/app/recurrent">Voir le détail</a>
      </div>
    <?php else: ?>
      <p class="kpi__value">Aucun contrat</p>
      <div class="kpi__meta">
        <span>Créez votre formule de maintenance pour suivre votre MRR.</span><a href="/app/recurrent/formules/nouvelle">Créer une formule</a>
        <?php if ($share !== null): ?><span>Part récurrente déclarée : <span class="num"><?= e(Format::percent($share['value'], 0)) ?></span> <?= ui_nature('declared') ?></span><?php endif; ?>
      </div>
    <?php endif; ?>
  </article>
</section>

<section class="card" aria-labelledby="t-target">
  <?php if ($targetPct !== null && $target !== null): ?>
    <div class="target">
      <div class="stack stack--sm">
        <div class="cluster cluster--between">
          <div class="card__title"><h2 id="t-target">Objectif de CA<?php if ($target['year'] !== null): ?> <?= e($target['year']) ?><?php endif; ?></h2><?= ui_term('objectif-ca') ?></div>
          <?= ui_nature('declared') ?>
        </div>
        <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= attr($targetPct) ?>" aria-label="CA de référence par rapport à l'objectif : <?= attr($targetPct) ?> %"><span class="progress__bar" data-value="<?= attr($targetPct) ?>"></span></div>
      </div>
      <div class="target__figures">
        <div class="target__figure"><span class="text-small text-secondary">Objectif</span><span class="num"><?= e(Format::money($target['value'], 0)) ?></span></div>
        <div class="target__figure"><span class="text-small text-secondary">CA de référence</span><span class="num"><?= e(Format::money($revenue['value'], 0)) ?> <span class="text-small text-secondary">(<?= e($targetPct) ?> %)</span></span></div>
        <div class="target__figure"><span class="text-small text-secondary">Écart</span><span class="num"><?= e(Format::money($target['gap'], 0)) ?></span></div>
      </div>
    </div>
  <?php else: ?>
    <div class="cluster cluster--between"><h2 id="t-target">Objectif de CA</h2><a class="btn btn--secondary btn--sm" href="/app/parametres/finances">Définir un objectif</a></div>
  <?php endif; ?>
</section>

<div class="dash-grid">
  <section class="card" aria-labelledby="t-prio">
    <div class="card__header">
      <div class="card__title"><h2 id="t-prio">Priorités</h2><?= ui_tip('tip-prio', 'Priorités', 'D\'où viennent les priorités ?', 'Agency OS compare vos chiffres à des seuils simples (prix trop bas, peu de récurrent, objectif lointain…) et vous dit quoi traiter en premier. Chaque recommandation indique les données sur lesquelles elle repose.', [], 'start', 'votre prix de création de site est sous votre prix plancher : c\'est la priorité n° 1.', '/app/glossaire#priorite') ?></div>
      <a class="text-small" href="/app/strategie">Toutes les recommandations</a>
    </div>
    <?php if ($strategy['top'] === []): ?>
      <div class="empty">
        <span class="empty__icon"><?= ui_icon('target') ?></span>
        <h3>Aucune recommandation nouvelle</h3>
        <p class="empty__text text-small">Complétez votre profil, finalisez vos simulations de prix et faites le diagnostic pour des recommandations plus précises.</p>
      </div>
    <?php else: ?>
      <div class="dash-recos">
        <?php foreach ($strategy['top'] as $r): ?>
          <div class="dash-reco">
            <div class="stack stack--sm"><a href="/app/strategie"><?= e($r['title']) ?></a><span class="text-micro text-tertiary">Priorité <?= e($r['priority_label']) ?><?= e($r['impact_kind'] === 'quantified' && $r['impact_amount'] !== null ? ' · impact ' . trim(Format::money($r['impact_amount'], 0) . ' ' . \App\Domain\Strategy\RuleCatalog::impactUnit($r['rule_code'])) : '') ?></span></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="stack stack--sm dash-plan">
      <div class="cluster cluster--between"><span class="text-small">Plan d'action</span><a class="text-small num" href="/app/strategie/plan"><?= e($strategy['progress']['done'] . ' / ' . $strategy['progress']['total'] . ' actions terminées') ?></a></div>
      <div class="progress progress--success"><span class="progress__bar" data-value="<?= attr($strategy['progress']['pct']) ?>"></span></div>
    </div>
  </section>

  <section class="card" aria-labelledby="t-marks">
    <div class="card__header"><div class="card__title"><h2 id="t-marks">Repères de production</h2><?= ui_tip('tip-marks', 'Repères de production', 'Repères de production', 'Les chiffres clés de votre temps de travail : combien coûte une heure et combien d\'heures vous pouvez vendre. Ils servent de base au simulateur de prix et aux formules récurrentes.', [], 'end', null, '/app/glossaire#rubrique-production') ?></div></div>
    <div class="result-list">
      <div class="result-line">
        <span class="result-line__label">Coût horaire complet <?= ui_term('cout-horaire', formula: true) ?></span>
        <span class="result-line__value"><?= e(Format::money($hourly['value'])) ?>/h</span>
      </div>
      <?php if ($hourly['value'] !== null): ?>
        <p class="text-small"><?= ui_nature($hourly['nature'], $hourly['nature'] === 'assumption' ? 'Saisi manuellement' : $hourlyLabel) ?></p>
      <?php endif; ?>
      <div class="result-line"><span class="result-line__label">Capacité facturable</span><span class="result-line__value"><?= e(Format::hours($capacity['value'])) ?> / mois</span></div>
      <div class="result-line"><span class="result-line__label">Charges variables générales</span><span class="result-line__value"><?= e(Format::percent($tv['value'], 2)) ?> du prix</span></div>
      <div class="result-line"><span class="result-line__label">Prestations actives</span><span class="result-line__value"><?= e(count($services)) ?></span></div>
    </div>
  </section>
</div>

<?php
$metricLabels = ['estimated_result' => 'Résultat estimé', 'mrr_contracted' => 'MRR contractualisé', 'maturity_score' => 'Maturité', 'hourly_cost' => 'Coût horaire'];
$historyDays = count($history['days']);
$chartData = [
    'labels' => array_map(static fn (array $d): string => substr((string) $d['date'], 8, 2) . '/' . substr((string) $d['date'], 5, 2), $history['days']),
    'values' => array_map(static fn (array $d): ?float => ($d['missing'] ?? false) || !isset($d[$historyMetric]) ? null : (float) $d[$historyMetric], $history['days']),
    'captured' => array_map(static fn (array $d): ?string => ($d['missing'] ?? false) ? null : (string) $d['captured_at'], $history['days']),
    'unit' => $historyMetric === 'maturity_score' ? 'score' : 'euro',
];
?>
<section class="card" aria-labelledby="t-history">
  <div class="card__header">
    <div class="card__title"><h2 id="t-history">Évolution</h2><?= ui_tip('tip-history', 'Évolution', 'Historique des indicateurs', 'Un instantané est enregistré chaque nuit (vers 02:15, heure de Paris). Un jour sans instantané reste vide dans la courbe : aucune valeur n\'est inventée entre deux points.') ?><?php if ($isDemo): ?><?= ui_nature('demo', 'Historique fictif') ?><?php endif; ?></div>
    <nav class="chips" aria-label="Période"><?php foreach ([7, 30] as $d): ?><a class="chip" href="/app?jours=<?= attr($d) ?>&amp;courbe=<?= attr($historyMetric) ?>"<?php if ($historyDays === $d): ?> aria-current="true"<?php endif; ?>><?= e($d . ' jours') ?></a><?php endforeach; ?></nav>
  </div>
  <?php if ($history['count'] === 0): ?>
    <div class="empty"><span class="empty__icon"><?= ui_icon('chart-spline') ?></span><h3>L'historique commence demain matin</h3><p class="empty__text text-small">Un instantané de vos indicateurs est enregistré chaque nuit. La courbe apparaîtra dès le premier.</p></div>
  <?php else: ?>
    <nav class="chips" aria-label="Indicateur"><?php foreach ($metricLabels as $key => $label): ?><a class="chip" href="/app?jours=<?= attr($historyDays) ?>&amp;courbe=<?= attr($key) ?>"<?php if ($historyMetric === $key): ?> aria-current="true"<?php endif; ?>><?= e($label) ?></a><?php endforeach; ?></nav>
    <div class="chart-box"><canvas id="chart-history" role="img" aria-label="<?= attr($metricLabels[$historyMetric] . ' sur ' . $historyDays . ' jours, ' . count($history['missing']) . ' jour(s) sans donnée.') ?>"></canvas></div>
    <script type="application/json" id="data-history"><?= json_script($chartData) ?></script>
    <?php if ($history['missing'] !== []): ?>
      <details class="text-small"><summary><?= e(count($history['missing']) . ' jour(s) sans donnée enregistrée') ?></summary><p class="text-secondary"><?= e(implode(', ', array_map(static fn (string $d): string => Format::date($d), $history['missing']))) ?></p></details>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section class="card card--sunken" aria-labelledby="t-start">
  <div class="cluster cluster--between">
    <h2 id="t-start">Bien démarrer</h2>
    <span class="text-small text-secondary">Profil complété à <span class="num"><?= e($completeness['rate']) ?> %</span></span>
  </div>
  <div class="progress progress--success" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= attr($completeness['rate']) ?>" aria-label="Complétude du profil"><span class="progress__bar" data-value="<?= attr($completeness['rate']) ?>"></span></div>
  <?php if ($completeness['missing'] !== []): ?>
    <div class="checklist">
      <?php foreach ($completeness['missing'] as $field): ?>
        <a class="checklist__item" href="<?= attr($field === 'services' ? '/app/parametres/prestations' : ($field === 'identity' ? '/app/parametres/agence' : '/app/parametres/finances')) ?>"><?= ui_icon('plus', 'sm') ?><?= e(FinanceFields::label($field)) ?></a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="checklist__item checklist__item--done"><?= ui_icon('circle-check', 'sm') ?>Profil complet</p>
  <?php endif; ?>
</section>
