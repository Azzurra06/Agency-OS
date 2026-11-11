<?php
/** @var string $csrf */
/** @var array{type: string, message: string}|null $flash */
?>
<section class="card public-card stack">
  <h1>Nouveau mot de passe</h1>
  <p class="text-secondary">12 caractères minimum. Toutes vos sessions ouvertes seront fermées.</p>
  <?php include __DIR__ . '/../partials/flash.php'; ?>
  <form class="stack" method="post" action="/reinitialisation"><?= ui_csrf($csrf) ?>
    <div class="field">
      <label class="field__label" for="password">Nouveau mot de passe</label>
      <input class="input" id="password" name="password" type="password" autocomplete="new-password" required minlength="12" maxlength="128">
    </div>
    <div class="field">
      <label class="field__label" for="password_confirmation">Confirmation</label>
      <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="12" maxlength="128">
    </div>
    <button class="btn btn--primary" type="submit">Enregistrer</button>
  </form>
</section>
