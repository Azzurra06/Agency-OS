<?php
/** @var array<string, mixed>|null $account */
/** @var array{type: string, message: string}|null $flash */
/** @var string $csrf */
include __DIR__ . '/settings-tabs.php';
?>
<?php include __DIR__ . '/../partials/flash.php'; ?>
<?php if ($account === null): ?>
  <section class="card"><div class="empty"><span class="empty__icon"><?= ui_icon('download') ?></span><h2>Non disponible en démonstration</h2><p class="empty__text text-small">Les données fictives de la démonstration sont supprimées automatiquement à son expiration.</p></div></section>
<?php else: ?>
  <section class="card stack" aria-labelledby="t-export">
    <div class="card__title"><h2 id="t-export">Exporter mes données</h2><?= ui_tip('tip-export', 'Export', 'Export de vos données', 'Un fichier JSON contenant toutes les données de votre agence (profil, prestations, diagnostics, simulations, formules, recommandations, plan d\'action, historique), généré à la demande et jamais conservé sur le serveur. 5 exports par heure au maximum ; un email vous prévient de chaque export.') ?></div>
    <form class="stack narrow-form" method="post" action="/app/parametres/donnees/export"><?= ui_csrf($csrf) ?>
      <div class="field"><label class="field__label" for="export_password">Mot de passe (vérification d'identité)</label><input class="input" id="export_password" name="password" type="password" autocomplete="current-password" required maxlength="128"></div>
      <div><button class="btn btn--secondary" type="submit"><?= ui_icon('download', 'sm') ?>Télécharger l'export</button></div>
    </form>
  </section>
  <section class="card stack danger-zone" aria-labelledby="t-delete">
    <h2 id="t-delete">Supprimer mon compte</h2>
    <ol class="text-small steps-text">
      <li>Vous confirmez avec votre mot de passe ; un lien valable 1 heure est envoyé à votre adresse email.</li>
      <li>En ouvrant ce lien, le compte est <strong>désactivé immédiatement</strong> : plus aucun accès, toutes les sessions sont fermées.</li>
      <li>Pendant <strong>7 jours</strong>, vous pouvez annuler en vous reconnectant (un lien d'annulation vous est envoyé).</li>
      <li>Ensuite, toutes les données sont <strong>supprimées définitivement</strong>. Elles disparaissent des sauvegardes à l'expiration de leur durée de conservation.</li>
    </ol>
    <form class="stack narrow-form" method="post" action="/app/parametres/donnees/suppression"><?= ui_csrf($csrf) ?>
      <div class="field"><label class="field__label" for="delete_password">Mot de passe</label><input class="input" id="delete_password" name="password" type="password" autocomplete="current-password" required maxlength="128"></div>
      <div class="field"><label class="field__label" for="delete_confirm">Saisissez SUPPRIMER pour confirmer</label><input class="input" id="delete_confirm" name="confirm" required maxlength="20" autocomplete="off"></div>
      <div><button class="btn btn--danger" type="submit">Demander la suppression</button></div>
    </form>
  </section>
<?php endif; ?>
