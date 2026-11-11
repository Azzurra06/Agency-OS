<?php
/** @var string $title */
/** @var string $content */
/** @var bool $wide */
$wide ??= false;
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> · Agency OS</title>
  <link rel="stylesheet" href="/assets/css/tokens.css">
  <link rel="stylesheet" href="/assets/css/base.css">
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/layout.css">
  <link rel="stylesheet" href="/assets/css/app.css">
  <script src="/assets/js/app.js" type="module"></script>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>
  <main id="contenu" class="<?= attr($wide ? 'public-page public-page--wide' : 'public-page') ?>">
    <a class="public-brand" href="/"><span class="brand__mark" aria-hidden="true">AO</span><span class="brand__name">Agency OS</span></a>
    <?= $content /* contenu déjà rendu et échappé par la vue */ ?>
    <?php include __DIR__ . '/../partials/public-footer.php'; ?>
  </main>
</body>
</html>
