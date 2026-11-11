<?php
/** @var array<string, mixed> $derived */
/** @var list<array{name: string, current_price: string|null}> $services */
/** @var bool $isDemo */
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
      <div class="card__title"><h2 class="kpi__label">Résultat estimé</h2><?= ui_tip('tip-result', 'Résultat estimé', 'Résultat estimé', 'CA annualisé moins toutes les charges déclarées. Estimation de gestion : elle ne remplace pas votre bilan.', array_merge(['Inclus :'], $result['includes'], ['Exclus :'], $result['excludes'])) ?></div>
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
      <div class="card__title"><h2 class="kpi__label">Maturité business</h2><?= ui_tip('tip-maturity', 'Maturité business', 'Maturité business', 'Score sur 100 issu du dernier diagnostic terminé, moyenne des six dimensions à poids égaux. Autoévaluation indicative.') ?></div>
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

  <article class="card kpi kpi--empty">
    <div class="kpi__top"><div class="card__title"><h2 class="kpi__label">MRR contractualisé</h2><?= ui_tip('tip-mrr', 'MRR contractualisé', 'MRR contractualisé', 'Revenu mensuel des contrats récurrents réellement signés. Les scénarios simulés ne sont jamais ajoutés à ce montant.') ?></div></div>
    <p class="kpi__value">À venir</p>
    <div class="kpi__meta">
      <span>Module Revenus récurrents à venir.</span>
      <?php if ($share !== null): ?><span>Part récurrente déclarée : <span class="num"><?= e(Format::percent($share['value'], 0)) ?></span> <?= ui_nature('declared') ?></span><?php endif; ?>
    </div>
  </article>
</section>

<section class="card" aria-labelledby="t-target">
  <?php if ($targetPct !== null && $target !== null): ?>
    <div class="target">
      <div class="stack stack--sm">
        <div class="cluster cluster--between">
          <div class="card__title"><h2 id="t-target">Objectif de CA<?php if ($target['year'] !== null): ?> <?= e($target['year']) ?><?php endif; ?></h2><?= ui_tip('tip-target', 'Objectif de CA', 'Objectif de chiffre d\'affaires', 'Votre objectif déclaré, comparé au CA de la période de référence. L\'écart montre le chemin restant : ce n\'est pas une prévision.') ?></div>
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
    <div class="card__header"><div class="card__title"><h2 id="t-prio">Priorités</h2><?= ui_tip('tip-prio', 'Priorités', 'D\'où viendront les priorités ?', 'Des règles métier analyseront vos données (profil, diagnostic, simulations finalisées, offres). Chaque recommandation indiquera les données sur lesquelles elle repose.') ?></div></div>
    <div class="empty">
      <span class="empty__icon"><?= ui_icon('target') ?></span>
      <h3>Bientôt vos recommandations</h3>
      <p class="empty__text text-small">Elles apparaîtront ici une fois le diagnostic et les simulations disponibles.</p>
    </div>
  </section>

  <section class="card" aria-labelledby="t-marks">
    <div class="card__header"><div class="card__title"><h2 id="t-marks">Repères de production</h2><?= ui_tip('tip-marks', 'Repères de production', 'Repères de production', 'Calculés à partir de votre profil financier. Ils alimenteront le simulateur de prix.', [], 'end') ?></div></div>
    <div class="result-list">
      <div class="result-line">
        <span class="result-line__label">Coût horaire complet <?= ui_tip('tip-hourly', 'Coût horaire', 'Coût horaire complet', '(Charges fixes + coût des salariés + rémunération du dirigeant) ÷ heures facturables annuelles.') ?></span>
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
