<?php
/** @var string $title */
/** @var bool $ok */
/** @var string $message */
?>
<section class="card public-card stack">
  <h1><?= e($ok ? $title : 'Lien invalide') ?></h1>
  <p class="flash flash--<?= attr($ok ? 'success' : 'error') ?>" role="status"><?= e($message) ?></p>
  <div class="public-actions"><a class="btn btn--secondary" href="/connexion">Connexion</a><a class="btn btn--ghost" href="/">Accueil</a></div>
</section>
