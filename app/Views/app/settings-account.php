<?php
/** @var array{email: string, created_at: string, password_changed_at: string|null}|null $account */
/** @var array{type: string, message: string}|null $flash */
/** @var string $csrf */
use App\Support\Format;

include __DIR__ . '/settings-tabs.php';
?>
<?php include __DIR__ . '/../partials/flash.php'; ?>
<?php if ($account === null): ?>
  <section class="card"><div class="empty"><span class="empty__icon"><?= ui_icon('lock') ?></span><h2>Non disponible en démonstration</h2><p class="empty__text text-small">Le compte de démonstration n'a ni email ni mot de passe.</p></div></section>
<?php else: ?>
  <section class="card stack" aria-labelledby="t-account">
    <h2 id="t-account">Compte</h2>
    <dl class="kv">
      <dt>Adresse email</dt><dd><?= e($account['email']) ?></dd>
      <dt>Compte créé le</dt><dd><?= e(Format::date(substr($account['created_at'], 0, 10))) ?></dd>
      <dt>Mot de passe modifié le</dt><dd><?= e($account['password_changed_at'] === null ? '—' : Format::date(substr($account['password_changed_at'], 0, 10))) ?></dd>
    </dl>
  </section>
  <section class="card stack" aria-labelledby="t-password">
    <h2 id="t-password">Changer le mot de passe</h2>
    <p class="text-small text-secondary">12 caractères minimum. Vos autres sessions seront fermées.</p>
    <form class="stack narrow-form" method="post" action="/app/parametres/compte/mot-de-passe"><?= ui_csrf($csrf) ?>
      <div class="field"><label class="field__label" for="current_password">Mot de passe actuel</label><input class="input" id="current_password" name="current_password" type="password" autocomplete="current-password" required maxlength="128"></div>
      <div class="field"><label class="field__label" for="new_password">Nouveau mot de passe</label><input class="input" id="new_password" name="new_password" type="password" autocomplete="new-password" required minlength="12" maxlength="128"></div>
      <div class="field"><label class="field__label" for="new_password_confirmation">Confirmation</label><input class="input" id="new_password_confirmation" name="new_password_confirmation" type="password" autocomplete="new-password" required minlength="12" maxlength="128"></div>
      <div><button class="btn btn--primary" type="submit">Changer le mot de passe</button></div>
    </form>
  </section>
<?php endif; ?>
