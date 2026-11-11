<?php
/** @var string $csrf */
/** @var array{type: string, message: string}|null $flash */
?>
<section class="card public-card stack">
  <h1>Mot de passe oublié</h1>
  <p class="text-secondary">Indiquez l'adresse de votre compte. Si elle existe, vous recevrez un lien valable 30 minutes. Aucun mot de passe n'est jamais envoyé par email.</p>
  <?php include __DIR__ . '/../partials/flash.php'; ?>
  <form class="stack" method="post" action="/mot-de-passe-oublie"><?= ui_csrf($csrf) ?>
    <div class="field">
      <label class="field__label" for="email">Adresse email</label>
      <input class="input" id="email" name="email" type="email" autocomplete="username" required maxlength="190">
    </div>
    <button class="btn btn--primary" type="submit">Recevoir un lien</button>
  </form>
  <a class="text-small" href="/connexion">Retour à la connexion</a>
</section>
