<?php
/** @var string $title */
/** @var bool $valid */
/** @var string $token */
/** @var string $action */
/** @var string $button */
/** @var bool $danger */
/** @var string $text */
/** @var string $csrf */
?>
<section class="card public-card stack">
  <h1><?= e($title) ?></h1>
  <?php if (!$valid): ?>
    <p class="flash flash--error" role="alert">Ce lien est invalide, a déjà été utilisé ou a expiré (validité : 1 heure).</p>
    <a class="btn btn--secondary" href="/connexion">Aller à la connexion</a>
  <?php else: ?>
    <p class="text-secondary"><?= e($text) ?></p>
    <form method="post" action="<?= attr($action) ?>"><?= ui_csrf($csrf) ?><input type="hidden" name="token" value="<?= attr($token) ?>">
      <button class="btn <?= attr($danger ? 'btn--danger' : 'btn--primary') ?>" type="submit"><?= e($button) ?></button>
    </form>
  <?php endif; ?>
</section>
