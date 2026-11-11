<?php
/** @var array{draft: array{public_id: string, dimensions_done: int, dimensions: int, answered: int, applicable: int}|null, latest: array<string, mixed>|null, history: list<array<string, mixed>>} $overview */
/** @var bool $isDemo */
use App\Support\Format;

$draft = $overview['draft'];
$latest = $overview['latest'];
?>
<header class="page-header">
  <div class="page-header__titles">
    <p class="eyebrow">Module Diagnostic · questionnaire v1</p>
    <div class="card__title"><h1>Diagnostic de maturité</h1><?= ui_tip('tip-diag', 'Diagnostic de maturité', 'Comment fonctionne le diagnostic ?', '30 questions réparties en six dimensions à poids égaux, environ 10 minutes. Chaque réponse vaut de 0 à 3 points ; le score de chaque dimension est ramené sur 100. Vos réponses sont enregistrées automatiquement : vous pouvez reprendre plus tard.') ?></div>
    <p class="page-header__sub">Positionnement, acquisition, rentabilité, organisation, fidélisation et stratégie.</p>
  </div>
  <div class="cluster">
    <?php if ($draft !== null): ?>
      <a class="btn btn--primary" href="/app/diagnostic/questionnaire?etape=<?= attr(min($draft['dimensions'], $draft['dimensions_done'] + 1)) ?>"><?= ui_icon('chevron-right', 'sm') ?>Reprendre (<?= e($draft['dimensions_done']) ?>/<?= e($draft['dimensions']) ?>)</a>
    <?php else: ?>
      <button class="btn btn--primary" type="button" data-diagnostic-start><?= ui_icon('clipboard-check', 'sm') ?><?= e($latest === null ? 'Commencer le diagnostic' : 'Refaire le diagnostic') ?></button>
    <?php endif; ?>
  </div>
</header>

<?php if ($isDemo): ?>
  <p class="alert alert--neutral"><?= ui_icon('flag', 'sm') ?><span>Valeurs fictives de démonstration. N'indiquez pas les informations réelles de votre entreprise.</span></p>
<?php endif; ?>

<?php if ($latest === null && $draft === null): ?>
  <section class="card">
    <div class="empty">
      <span class="empty__icon"><?= ui_icon('clipboard-check') ?></span>
      <h2>Faites le diagnostic en 10 minutes</h2>
      <p class="empty__text text-small">Vous obtiendrez un score par dimension, vos deux points forts et vos deux axes d'amélioration prioritaires. Autoévaluation indicative : ce n'est ni une certification ni un diagnostic financier officiel.</p>
    </div>
  </section>
<?php endif; ?>

<?php if ($draft !== null): ?>
  <section class="card" aria-labelledby="t-draft">
    <div class="card__header"><h2 id="t-draft">Diagnostic en cours</h2><span class="badge"><?= e($draft['answered']) ?> / <?= e($draft['applicable']) ?> réponses</span></div>
    <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?= attr($draft['applicable']) ?>" aria-valuenow="<?= attr($draft['answered']) ?>" aria-label="Progression du diagnostic en cours"><span class="progress__bar" data-value="<?= attr($draft['applicable'] === 0 ? 0 : intdiv(100 * $draft['answered'], $draft['applicable'])) ?>"></span></div>
    <div class="cluster cluster--between diag-draft-actions">
      <p class="text-small text-secondary">Vos réponses sont enregistrées au fur et à mesure.</p>
      <button class="btn btn--ghost btn--sm" type="button" data-diagnostic-discard="<?= attr($draft['public_id']) ?>"><?= ui_icon('x', 'sm') ?>Abandonner ce brouillon</button>
    </div>
  </section>
<?php endif; ?>

<?php if ($latest !== null): ?>
  <section class="card" aria-labelledby="t-latest">
    <div class="card__header">
      <div class="card__title"><h2 id="t-latest">Dernier résultat</h2></div>
      <?= ui_nature('frozen', 'Figé le ' . Format::date(substr((string) $latest['completed_at'], 0, 10))) ?>
    </div>
    <div class="diag-summary">
      <?php if ($latest['global_display'] !== null): ?>
        <div class="score-ring" data-value="<?= attr($latest['global_display']) ?>"><span class="score-ring__value"><?= e($latest['global_display']) ?><small>/ 100</small></span></div>
        <div class="stack stack--sm">
          <p class="diag-level"><?= e($latest['level']) ?></p>
          <p class="text-small text-secondary"><?= e($latest['reliability_label']) ?></p>
        </div>
      <?php else: ?>
        <div class="score-ring score-ring--partial"><span class="score-ring__value">—<small>partiel</small></span></div>
        <div class="stack stack--sm">
          <p class="diag-level">Diagnostic partiel</p>
          <p class="text-small text-secondary">Score global disponible lorsque les 6 dimensions sont renseignées.</p>
        </div>
      <?php endif; ?>
      <a class="btn btn--secondary diag-summary__cta" href="/app/diagnostic/resultats/<?= attr($latest['public_id']) ?>">Voir les résultats<?= ui_icon('chevron-right', 'sm') ?></a>
    </div>
  </section>

  <section class="card" aria-labelledby="t-history">
    <div class="card__header"><h2 id="t-history">Historique</h2></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col">Date</th><th scope="col">Résultat</th><th scope="col">Fiabilité</th><th scope="col">Version</th><th scope="col"><span class="visually-hidden">Action</span></th></tr></thead>
        <tbody>
          <?php foreach ($overview['history'] as $h): ?>
            <tr>
              <td><?= e(Format::date(substr((string) $h['completed_at'], 0, 10))) ?></td>
              <td><?php if ($h['global_display'] !== null): ?><span class="num"><?= e($h['global_display']) ?></span> / 100 · <?= e($h['level']) ?><?php else: ?>Diagnostic partiel<?php endif; ?></td>
              <td><?= e($h['reliability_label'] ?? '—') ?></td>
              <td><?= e($h['questionnaire_version']) ?></td>
              <td><a href="/app/diagnostic/resultats/<?= attr($h['public_id']) ?>">Détail</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>
