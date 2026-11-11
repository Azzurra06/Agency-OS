<?php
/**
 * @var array{public_id: string, version: string, step: int, steps: int, dimension: array{code: string, label: string, description: string},
 *   questions: list<array{code: string, text: string, options: array<string, string>, unknown_policy: string, conditional: bool, visible: bool, answer: string|null, prefilled: bool}>,
 *   progress: array{answered: int, applicable: int, dimensions_done: int, dimensions: int, ready: bool, by_dimension: array<string, array{answered: int, applicable: int}>}} $draft
 */
/** @var bool $isDemo */
use App\Domain\Diagnostic\QuestionnaireRegistry;

$questionnaire = QuestionnaireRegistry::get($draft['version']);
$progress = $draft['progress'];
$pct = $progress['applicable'] === 0 ? 0 : intdiv(100 * $progress['answered'], $progress['applicable']);
$isLast = $draft['step'] === $draft['steps'];
?>
<header class="page-header">
  <div class="page-header__titles">
    <p class="eyebrow">Diagnostic de maturité · questionnaire <?= e($draft['version']) ?></p>
    <div class="card__title"><h1><?= e($draft['dimension']['label']) ?></h1><?= ui_tip('tip-dim', $draft['dimension']['label'], 'Ce que mesure cette dimension', $draft['dimension']['description'] . ' Score sur 100 : seules les réponses exploitables comptent.') ?></div>
    <p class="page-header__sub">Dimension <?= e($draft['step']) ?> sur <?= e($draft['steps']) ?> · environ 10 minutes au total</p>
  </div>
  <span class="inline-status" aria-live="polite" data-save-status><?= ui_icon('check', 'sm') ?>Enregistrement automatique</span>
</header>

<?php if ($isDemo): ?>
  <p class="alert alert--neutral"><?= ui_icon('flag', 'sm') ?><span>Réponses fictives de démonstration : n'indiquez pas les informations réelles de votre entreprise.</span></p>
<?php endif; ?>

<div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?= attr($progress['applicable']) ?>" aria-valuenow="<?= attr($progress['answered']) ?>" aria-label="Progression du diagnostic" data-diag-progress><span class="progress__bar" data-value="<?= attr($pct) ?>"></span></div>

<div class="diag-grid">
  <aside class="diag-aside" aria-label="Étapes du diagnostic">
    <ol class="steps">
      <?php $i = 0; foreach ($questionnaire->dimensions as $code => $dimension): $i++; $d = $progress['by_dimension'][$code]; $done = $d['applicable'] > 0 && $d['answered'] === $d['applicable']; ?>
        <li<?php if ($i === $draft['step']): ?> aria-current="step"<?php elseif ($done): ?> data-state="done"<?php endif; ?> data-step-code="<?= attr($code) ?>">
          <a class="steps__link" href="/app/diagnostic/questionnaire?etape=<?= attr($i) ?>"><span class="steps__dot" aria-hidden="true"><?= e($done && $i !== $draft['step'] ? '✓' : $i) ?></span><span class="steps__label"><?= e($dimension['label']) ?></span><span class="visually-hidden"><?= e($done ? ' (terminée)' : '') ?></span></a>
        </li>
      <?php endforeach; ?>
    </ol>
    <p class="alert alert--neutral"><?= ui_icon('info', 'sm') ?><span>Autoévaluation indicative. Ce n'est ni une certification ni un diagnostic financier officiel.</span></p>
  </aside>

  <form class="card stack stack--lg" aria-label="Questions : <?= attr($draft['dimension']['label']) ?>" data-diagnostic-form data-id="<?= attr($draft['public_id']) ?>">
    <?php foreach ($draft['questions'] as $n => $q): ?>
      <fieldset class="question" data-question="<?= attr($q['code']) ?>"<?php if (!$q['visible']): ?> hidden<?php endif; ?>>
        <legend><?= e($q['text']) ?></legend>
        <?php if ($q['prefilled']): ?><p class="question__hint"><?= ui_nature('declared', 'Issu de votre profil, modifiable') ?></p><?php endif; ?>
        <?php foreach ($q['options'] as $value => $label): ?>
          <label class="radio-card"><input type="radio" name="<?= attr($q['code']) ?>" value="<?= attr($value) ?>"<?php if ($q['answer'] === $value): ?> checked<?php endif; ?>><span class="radio-card__title"><?= e($label) ?></span></label>
        <?php endforeach; ?>
        <label class="radio-card radio-card--muted"><input type="radio" name="<?= attr($q['code']) ?>" value="unknown"<?php if ($q['answer'] === 'unknown'): ?> checked<?php endif; ?>><span class="radio-card__title">Je ne sais pas</span></label>
        <p class="question__note"><?= ui_icon('circle-help', 'sm') ?><span><?php if ($q['unknown_policy'] === 'evaluable'): ?>Pour cette question, « Je ne sais pas » est <strong>compté comme pratique absente</strong> (0 point) : c'est un axe de progrès.<?php else: ?>Pour cette question, « Je ne sais pas » est une réponse <strong>inconnue</strong> : elle ne compte pas dans le score, mais elle réduit sa fiabilité.<?php endif; ?></span></p>
      </fieldset>
    <?php endforeach; ?>

    <p class="alert alert--danger" data-form-summary hidden></p>

    <div class="diag-nav">
      <?php if ($draft['step'] > 1): ?>
        <a class="btn btn--secondary" href="/app/diagnostic/questionnaire?etape=<?= attr($draft['step'] - 1) ?>"><?= ui_icon('chevron-left', 'sm') ?>Précédent</a>
      <?php else: ?>
        <a class="btn btn--ghost" href="/app/diagnostic"><?= ui_icon('chevron-left', 'sm') ?>Accueil du diagnostic</a>
      <?php endif; ?>
      <?php if ($isLast): ?>
        <button class="btn btn--primary" type="button" data-diagnostic-complete><?= ui_icon('circle-check', 'sm') ?>Terminer le diagnostic</button>
      <?php else: ?>
        <a class="btn btn--primary" href="/app/diagnostic/questionnaire?etape=<?= attr($draft['step'] + 1) ?>">Dimension suivante<?= ui_icon('chevron-right', 'sm') ?></a>
      <?php endif; ?>
    </div>
    <p class="text-small text-secondary" data-diag-remaining><?= e($progress['answered']) ?> réponse(s) sur <?= e($progress['applicable']) ?>. Vous pourrez terminer quand toutes les questions auront une réponse (« Je ne sais pas » compris).</p>
  </form>
</div>
