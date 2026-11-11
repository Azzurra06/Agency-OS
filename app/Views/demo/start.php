<?php
/** @var string $csrf */
/** @var string|null $error */
?>
<section class="card public-card stack">
  <p class="eyebrow">Démonstration</p>
  <h1>Explorer Agency OS avec une agence fictive</h1>
  <p class="text-secondary">Vous allez découvrir l'outil avec les données de l'agence « Lumen », entièrement inventées. Vous pouvez modifier toutes les valeurs pour voir l'effet sur les indicateurs.</p>
  <ul class="stack stack--sm text-small text-secondary">
    <li>Votre espace est personnel et isolé : personne d'autre n'y a accès.</li>
    <li>Il expire au bout de 24 heures, puis toutes ses données sont supprimées.</li>
    <li><strong>N'indiquez pas les chiffres réels de votre entreprise.</strong></li>
  </ul>
  <?php if ($error !== null): ?><p class="flash flash--error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" action="/demo"><?= ui_csrf($csrf) ?>
    <button class="btn btn--primary btn--lg" type="submit">Lancer la démonstration</button>
  </form>
</section>
