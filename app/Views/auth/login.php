<?php
/** @var string $csrf */
/** @var array{type: string, message: string}|null $flash */
/** @var bool $isPortfolio */
?>
<section class="card public-card stack">
  <h1>Connexion</h1>
  <?php if ($isPortfolio): ?>
    <p class="text-small text-secondary">Comptes de test uniquement. Pour découvrir Agency OS, <a href="/demo">utilisez la démonstration</a>.</p>
  <?php endif; ?>
  <?php include __DIR__ . '/../partials/flash.php'; ?>
  <form class="stack" method="post" action="/connexion" novalidate><?= ui_csrf($csrf) ?>
    <div class="field">
      <label class="field__label" for="email">Adresse email</label>
      <input class="input" id="email" name="email" type="email" autocomplete="username" required maxlength="190">
    </div>
    <div class="field">
      <label class="field__label" for="password">Mot de passe</label>
      <input class="input" id="password" name="password" type="password" autocomplete="current-password" required maxlength="128">
    </div>
    <button class="btn btn--primary" type="submit">Se connecter</button>
  </form>
  <a class="text-small" href="/mot-de-passe-oublie">Mot de passe oublié ?</a>
</section>
