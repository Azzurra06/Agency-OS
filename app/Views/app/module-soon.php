<?php
/** @var string $moduleLabel */
/** @var string $moduleIcon */
/** @var int $lot */
/** @var string $promise */
?>
<header class="page-header"><div class="page-header__titles"><h1><?= e($moduleLabel) ?></h1><p class="page-header__sub"><?= e($promise) ?></p></div></header>
<section class="card">
  <div class="empty">
    <span class="empty__icon"><?= ui_icon($moduleIcon) ?></span>
    <h2>Module en cours de développement</h2>
    <p class="empty__text text-small">Ce module arrive avec le lot <?= e($lot) ?> du développement. En attendant, complétez votre profil : il alimentera directement ses calculs.</p>
    <a class="btn btn--secondary" href="/app/parametres/finances">Compléter le profil</a>
  </div>
</section>
