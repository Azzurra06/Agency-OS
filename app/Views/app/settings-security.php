<?php
/** @var array<string, mixed>|null $account */
/** @var list<array{at: string, ip: string|null}> $logins */
/** @var array{type: string, message: string}|null $flash */
/** @var string $csrf */
use App\Support\Format;

include __DIR__ . '/settings-tabs.php';
?>
<?php include __DIR__ . '/../partials/flash.php'; ?>
<?php if ($account === null): ?>
  <section class="card"><div class="empty"><span class="empty__icon"><?= ui_icon('shield') ?></span><h2>Non disponible en démonstration</h2><p class="empty__text text-small">La démonstration est isolée et expire automatiquement après 24 heures.</p></div></section>
<?php else: ?>
  <section class="card stack" aria-labelledby="t-logins">
    <div class="card__title"><h2 id="t-logins">Dernières connexions</h2><?= ui_tip('tip-logins', 'Connexions', 'Dernières connexions', 'Les 10 dernières connexions réussies à votre compte, avec une adresse IP tronquée. Si l\'une d\'elles ne vient pas de vous, déconnectez tous les appareils et changez votre mot de passe.') ?></div>
    <?php if ($logins === []): ?><p class="text-small text-secondary">Aucune connexion enregistrée.</p><?php else: ?>
      <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Date (UTC)</th><th scope="col">Adresse IP (tronquée)</th></tr></thead><tbody>
        <?php foreach ($logins as $l): ?><tr><td><?= e(Format::date(substr($l['at'], 0, 10)) . ' ' . substr($l['at'], 11, 5)) ?></td><td><?= e($l['ip'] ?? '—') ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>
  <section class="card stack" aria-labelledby="t-revoke">
    <h2 id="t-revoke">Déconnecter tous les appareils</h2>
    <p class="text-small text-secondary">Toutes les sessions ouvertes, y compris celle-ci, seront fermées immédiatement.</p>
    <form method="post" action="/app/parametres/securite/deconnexion-globale"><?= ui_csrf($csrf) ?><button class="btn btn--secondary" type="submit"><?= ui_icon('log-out', 'sm') ?>Déconnecter tous les appareils</button></form>
  </section>
<?php endif; ?>
