<?php
/** @var int $step */
/** @var array<string, string|bool|int|null> $values */
/** @var list<array{public_id: string, name: string, category: string, current_price: string|null, annual_volume: int|null, is_active: bool}> $services */
/** @var array{name: string, activity_type: string|null, team_size: int|null, legal_status: string|null} $org */
/** @var bool $isDemo */
$steps = [1 => 'Votre agence', 2 => 'Vos chiffres', 3 => 'Vos prestations'];
?>
<header class="page-header">
  <div class="page-header__titles">
    <p class="eyebrow">Bienvenue · étape <?= e($step) ?> sur 3</p>
    <h1><?= e($steps[$step]) ?></h1>
    <p class="page-header__sub">Chaque étape peut être passée et complétée plus tard dans les paramètres.</p>
  </div>
</header>

<ol class="stepper" aria-label="Étapes">
  <?php foreach ($steps as $n => $label): ?>
    <li<?php if ($n === $step): ?> aria-current="step"<?php elseif ($n < $step): ?> data-state="done"<?php endif; ?>><?php if ($n < $step): ?><?= ui_icon('check', 'sm') ?><?php endif; ?><?= e($n . '. ' . $label) ?></li>
  <?php endforeach; ?>
</ol>

<?php if ($step === 1): ?>
  <?php $redirect = '/app/bienvenue?etape=2'; include __DIR__ . '/../partials/agency-form.php'; ?>
<?php elseif ($step === 2): ?>
  <?php
    $formAction = '/api/v1/financial-profile?origine=onboarding';
    $formRedirect = '/app/bienvenue?etape=3';
    $submitLabel = 'Enregistrer et continuer';
    include __DIR__ . '/../partials/finance-form.php';
  ?>
<?php else: ?>
  <?php include __DIR__ . '/../partials/services-editor.php'; ?>
  <div class="form-actions">
    <a class="btn btn--ghost" href="/app/bienvenue?etape=2">Retour</a>
    <button class="btn btn--primary" type="button" data-finish-onboarding>Terminer et voir la vue d'ensemble</button>
  </div>
<?php endif; ?>

<?php if ($step < 3): ?>
  <p class="text-small"><a href="/app/bienvenue?etape=<?= attr($step + 1) ?>">Passer cette étape</a></p>
<?php endif; ?>
