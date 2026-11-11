<?php
/** @var string $title */
/** @var string $content */
/** @var string $nav */
/** @var string $csrf */
/** @var array{name: string} $org */
/** @var bool $isDemo */
/** @var string|null $demoExpiresAt */
/** @var int|null $demoRemaining */
/** @var bool $charts */
$charts ??= false;
$navItems = [
    'vue' => ['/app', 'layout-dashboard', 'Vue d\'ensemble', 'Vue'],
    'diagnostic' => ['/app/diagnostic', 'clipboard-check', 'Diagnostic', 'Diagnostic'],
    'pricing' => ['/app/pricing', 'tag', 'Pricing', 'Pricing'],
    'recurrent' => ['/app/recurrent', 'repeat', 'Revenus récurrents', 'Récurrent'],
    'strategie' => ['/app/strategie', 'target', 'Stratégie', 'Stratégie'],
];
$remainingLabel = $demoRemaining === null ? '' : sprintf('%d h %02d', intdiv($demoRemaining, 3600), intdiv($demoRemaining % 3600, 60));
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= attr($csrf) ?>">
  <title><?= e($title) ?> · Agency OS</title>
  <link rel="stylesheet" href="/assets/css/tokens.css">
  <link rel="stylesheet" href="/assets/css/base.css">
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/layout.css">
  <link rel="stylesheet" href="/assets/css/app.css">
  <?php if ($charts): ?><script src="/assets/vendor/chart-4.4.4.umd.min.js" defer></script><?php endif; ?>
  <script src="/assets/js/app.js" type="module"></script>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>

  <?php if ($isDemo): ?>
  <div class="demo-banner" role="region" aria-label="Mode démonstration">
    <p class="demo-banner__text">
      <?= ui_icon('flag', 'sm') ?>
      <strong>Démonstration</strong> · données fictives<span class="hide-sm"> de l'agence « Lumen »</span> · expire dans
      <span class="num" data-expires="<?= attr($demoExpiresAt) ?>"><?= e($remainingLabel) ?></span>
    </p>
    <div class="cluster demo-banner__actions">
      <form method="post" action="/demo/reinitialiser"><?= ui_csrf($csrf) ?>
        <button class="btn btn--ghost btn--sm" type="submit"><?= ui_icon('rotate-ccw', 'sm') ?><span class="btn__label">Réinitialiser</span></button>
      </form>
      <form method="post" action="/demo/quitter"><?= ui_csrf($csrf) ?>
        <button class="btn btn--secondary btn--sm" type="submit"><?= ui_icon('log-out', 'sm') ?><span class="btn__label">Quitter la démo</span></button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="app">
    <aside class="sidebar" aria-label="Navigation principale">
      <a class="brand" href="/app"><span class="brand__mark" aria-hidden="true">AO</span><span class="brand__name">Agency OS</span></a>
      <div class="org-switch"><span class="eyebrow">Agence</span><span class="org-switch__name"><?= e($org['name']) ?><?php if ($isDemo): ?> (démo)<?php endif; ?></span></div>
      <nav class="nav">
        <?php foreach ($navItems as $key => [$href, $icon, $label]): ?>
          <a class="nav__link" href="<?= attr($href) ?>"<?php if ($nav === $key): ?> aria-current="page"<?php endif; ?>><?= ui_icon($icon) ?><span class="nav__label"><?= e($label) ?></span></a>
        <?php endforeach; ?>
      </nav>
      <div class="sidebar__bottom">
        <a class="nav__link" href="/app/glossaire"<?php if ($nav === 'glossaire'): ?> aria-current="page"<?php endif; ?>><?= ui_icon('circle-help') ?><span class="nav__label">Glossaire</span></a>
        <a class="nav__link" href="/app/parametres/agence"<?php if ($nav === 'parametres'): ?> aria-current="page"<?php endif; ?>><?= ui_icon('settings') ?><span class="nav__label">Paramètres</span></a>
        <?php if (!$isDemo): ?>
        <form method="post" action="/deconnexion"><?= ui_csrf($csrf) ?>
          <button class="nav__link btn--ghost" type="submit"><?= ui_icon('log-out') ?><span class="nav__label">Se déconnecter</span></button>
        </form>
        <?php endif; ?>
      </div>
    </aside>

    <main class="main" id="contenu">
      <div class="page">
        <?= $content /* contenu déjà rendu et échappé par la vue */ ?>
      </div>
    </main>
  </div>

  <nav class="tabbar" aria-label="Navigation principale">
    <?php foreach ($navItems as $key => [$href, $icon, , $short]): ?>
      <a class="tabbar__link" href="<?= attr($href) ?>"<?php if ($nav === $key): ?> aria-current="page"<?php endif; ?>><?= ui_icon($icon) ?><?= e($short) ?></a>
    <?php endforeach; ?>
  </nav>

  <div class="toast-region" aria-live="polite" data-toasts></div>
  <dialog class="dialog" data-confirm-dialog aria-labelledby="confirm-title">
    <h2 id="confirm-title" data-confirm-title>Confirmer</h2>
    <p class="text-secondary" data-confirm-text></p>
    <div class="dialog__actions">
      <button class="btn btn--ghost" type="button" data-confirm-cancel>Annuler</button>
      <button class="btn btn--danger" type="button" data-confirm-ok>Confirmer</button>
    </div>
  </dialog>
</body>
</html>
