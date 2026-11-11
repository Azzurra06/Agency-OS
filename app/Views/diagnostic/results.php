<?php
/** @var array<string, mixed> $results */
/** @var bool $isDemo */
use App\Support\Format;

/** @var array<string, array{code: string, label: string, description: string, score: string|null, score_display: string|null, level: string|null, scorable: bool, applicable: int, scored: int, unknown_excluded: int, unknown_evaluable: int, reliability: string, unknown_questions: list<array{code: string, text: string}>}> $dimensions */
$dimensions = $results['dimensions'];
/** @var list<string> $strengths */
$strengths = $results['strengths'];
/** @var list<string> $weaknesses */
$weaknesses = $results['weaknesses'];
/** @var array{public_id: string, completed_at: string, same_method: bool, deltas: array<string, string|null>}|null $comparison */
$comparison = $results['comparison'];
$partial = $results['global_display'] === null;
$date = Format::date(substr((string) $results['completed_at'], 0, 10));
$radar = ['labels' => [], 'values' => []];
foreach ($dimensions as $d) {
    $radar['labels'][] = $d['scorable'] ? $d['label'] : $d['label'] . ' (insuffisant)';
    $radar['values'][] = $d['scorable'] && $d['score'] !== null ? (float) $d['score'] : null;
}
$radarLabel = implode(', ', array_map(static fn (array $d): string => $d['label'] . ' ' . ($d['scorable'] ? $d['score_display'] : 'données insuffisantes'), $dimensions));
$missing = [];
foreach ($dimensions as $d) {
    if (!$d['scorable']) {
        foreach ($d['unknown_questions'] as $q) {
            $missing[] = $d['label'] . ' : ' . $q['text'];
        }
    }
}
$delta = static function (?string $value): string {
    if ($value === null) {
        return '';
    }

    return (str_starts_with($value, '-') ? '' : '+') . $value;
};
?>
<header class="page-header">
  <div class="page-header__titles">
    <p class="eyebrow">Diagnostic de maturité · questionnaire <?= e($results['version']) ?></p>
    <h1>Résultats du diagnostic</h1>
    <p class="page-header__sub">Terminé le <?= e($date) ?><?php if ($isDemo): ?> · réponses fictives de démonstration<?php endif; ?></p>
  </div>
  <div class="cluster">
    <?= ui_nature('frozen', 'Figé le ' . $date) ?>
    <a class="btn btn--secondary btn--sm" href="/app/diagnostic"><?= ui_icon('chevron-left', 'sm') ?>Accueil du diagnostic</a>
  </div>
</header>

<section class="card diag-hero" aria-labelledby="t-score">
  <?php if (!$partial): ?>
    <div class="score-ring score-ring--lg" data-value="<?= attr($results['global_display']) ?>"><span class="score-ring__value"><?= e($results['global_display']) ?><small>/ 100</small></span></div>
  <?php else: ?>
    <div class="score-ring score-ring--lg score-ring--partial"><span class="score-ring__value">—<small>partiel</small></span></div>
  <?php endif; ?>
  <div class="stack stack--sm">
    <div class="card__title">
      <h2 id="t-score"><?= e($partial ? 'Diagnostic partiel : ' . $results['scorable_count'] . '/6 dimensions' : 'Maturité : ' . $results['level']) ?></h2>
      <?= ui_tip('tip-score', 'Score global', 'Comment ce score est calculé', 'Moyenne simple des six dimensions, à poids égaux. Chaque dimension = points obtenus ÷ points possibles sur les réponses exploitables. Le score global n\'est affiché que si les six dimensions sont calculables (au moins 3 réponses exploitables et 60 % des questions).', ['Fragile : 0 à 39', 'En construction : 40 à 59', 'Structuré : 60 à 79', 'Avancé : 80 à 100']) ?>
    </div>
    <?php if ($partial): ?><p class="text-secondary">Score global disponible lorsque les 6 dimensions sont renseignées.</p><?php endif; ?>
    <p class="text-small">
      <strong><?= e($results['reliability_label']) ?></strong>
      <?php if ((int) $results['unknown_excluded'] > 0): ?> · <?= e($results['unknown_excluded']) ?> réponse(s) inconnue(s) exclue(s) du calcul<?php endif; ?>
      <?php if ((int) $results['unknown_evaluable'] > 0): ?> · <?= e($results['unknown_evaluable']) ?> « Je ne sais pas » compté(s) comme pratique absente<?php endif; ?>
    </p>
    <p class="text-small text-secondary">Autoévaluation indicative : ce n'est ni une certification ni un diagnostic financier officiel.</p>
  </div>
</section>

<?php if ($missing !== []): ?>
  <section class="alert alert--warning" aria-labelledby="t-missing">
    <?= ui_icon('triangle-alert', 'sm') ?>
    <div class="stack stack--sm">
      <p class="alert__title" id="t-missing">Informations manquantes</p>
      <ul class="diag-missing"><?php foreach ($missing as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul>
      <?php if ($results['can_copy']): ?><div><button class="btn btn--secondary btn--sm" type="button" data-diagnostic-start data-from="<?= attr($results['public_id']) ?>">Compléter ces réponses</button></div><?php endif; ?>
    </div>
  </section>
<?php endif; ?>

<div class="dash-grid">
  <section class="card" aria-labelledby="t-radar">
    <div class="card__header">
      <div class="card__title"><h2 id="t-radar">Vue par dimension</h2><?= ui_tip('tip-radar', 'Radar', 'Lire le radar', 'Chaque axe est une dimension, de 0 (centre) à 100. Un axe grisé « insuffisant » n\'est pas calculable : il n\'est jamais placé à 0.') ?></div>
      <?php if ($partial): ?><span class="badge badge--warning"><?= e($results['scorable_count']) ?>/6 dimensions</span><?php endif; ?>
    </div>
    <div class="chart-box chart-box--radar"><canvas id="chart-radar" role="img" aria-label="<?= attr('Radar : ' . $radarLabel . '.') ?>"></canvas></div>
    <script type="application/json" id="data-radar"><?= json_script($radar) ?></script>
  </section>

  <section class="card stack" aria-labelledby="t-focus">
    <h2 id="t-focus">Forces et axes d'amélioration</h2>
    <div class="stack stack--sm">
      <h3 class="diag-focus__title"><?= ui_icon('star', 'sm') ?>Points forts</h3>
      <?php if ($strengths === []): ?><p class="text-small text-secondary">Aucune dimension n'atteint 60/100 pour l'instant.</p><?php endif; ?>
      <?php foreach ($strengths as $code): ?><p class="diag-focus"><span><?= e($dimensions[$code]['label']) ?></span><span class="num"><?= e($dimensions[$code]['score_display']) ?></span></p><?php endforeach; ?>
    </div>
    <div class="stack stack--sm">
      <h3 class="diag-focus__title"><?= ui_icon('target', 'sm') ?>Axes d'amélioration prioritaires</h3>
      <?php if ($weaknesses === []): ?><p class="text-small text-secondary">Toutes les dimensions calculables atteignent au moins 60/100.</p><?php endif; ?>
      <?php foreach ($weaknesses as $code): ?><p class="diag-focus"><span><?= e($dimensions[$code]['label']) ?></span><span class="num"><?= e($dimensions[$code]['score_display']) ?></span></p><?php endforeach; ?>
      <p class="text-small text-secondary">Les recommandations détaillées arrivent avec le module Stratégie.</p>
    </div>
  </section>
</div>

<section class="card" aria-labelledby="t-detail">
  <div class="card__header">
    <h2 id="t-detail">Détail par dimension</h2>
    <?php if ($comparison !== null): ?>
      <span class="text-small text-secondary"><?= e($comparison['same_method'] ? 'Comparé au diagnostic du ' . Format::date(substr($comparison['completed_at'], 0, 10)) : 'Diagnostic précédent : méthode différente, comparaison impossible') ?></span>
    <?php endif; ?>
  </div>
  <div class="result-list">
    <?php foreach ($dimensions as $d): ?>
      <div class="diag-dim">
        <div class="diag-dim__head">
          <span class="diag-dim__label"><?= e($d['label']) ?></span>
          <span class="diag-dim__value">
            <?php if ($d['scorable']): ?>
              <span class="num"><?= e($d['score_display']) ?></span> / 100 · <?= e($d['level']) ?>
              <?php if ($comparison !== null && $comparison['same_method'] && ($comparison['deltas'][$d['code']] ?? null) !== null): ?>
                <span class="badge badge--outline"><?= e($delta($comparison['deltas'][$d['code']]) . ' pts') ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="badge badge--warning">Données insuffisantes</span>
            <?php endif; ?>
          </span>
        </div>
        <div class="progress"><span class="progress__bar" data-value="<?= attr($d['scorable'] ? $d['score_display'] : 0) ?>"></span></div>
        <p class="text-small text-secondary"><?= e(sprintf('%d réponse(s) exploitable(s) sur %d', $d['scored'], $d['applicable'])) ?><?php if ($d['unknown_excluded'] > 0): ?> · <?= e($d['unknown_excluded']) ?> inconnue(s)<?php endif; ?><?php if ($d['unknown_evaluable'] > 0): ?> · <?= e($d['unknown_evaluable']) ?> comptée(s) comme pratique absente<?php endif; ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</section>
