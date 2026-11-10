<?php
/** @var string $title */
/** @var string $message */
/** @var int $status */
/** @var string|null $errorRef */
?>
<section class="card public-card stack">
  <p class="eyebrow">Erreur <?= e($status) ?></p>
  <h1><?= e($title) ?></h1>
  <p class="text-secondary"><?= e($message) ?></p>
  <?php if ($errorRef !== null): ?>
    <p class="text-small text-tertiary">Référence de l'incident : <span class="num"><?= e($errorRef) ?></span></p>
  <?php endif; ?>
  <a class="btn btn--secondary" href="/">Retour à l'accueil</a>
</section>
