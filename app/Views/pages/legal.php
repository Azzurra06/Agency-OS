<?php
/** @var \App\Support\Config $config */
$todo = 'à compléter avant la mise en ligne';
?>
<article class="card public-card stack">
  <h1>Mentions légales</h1>
  <?php if (!$config->legalComplete()): ?><p class="flash flash--error" role="alert">Informations de l'éditeur à compléter avant la mise en ligne (configuration LEGAL_*).</p><?php endif; ?>
  <h2>Éditeur</h2>
  <p><?= e($config->legalPublisher !== '' ? $config->legalPublisher : $todo) ?><br>
    <?php if ($config->legalAddress !== ''): ?><?= e($config->legalAddress) ?><br><?php endif; ?>
    <?php if ($config->legalRegistration !== ''): ?><?= e($config->legalRegistration) ?><br><?php endif; ?>
    Contact : <?= e($config->legalContactEmail !== '' ? $config->legalContactEmail : $todo) ?></p>
  <p>Directrice ou directeur de la publication : <?= e($config->legalPublisher !== '' ? $config->legalPublisher : $todo) ?>.</p>
  <h2>Hébergement</h2>
  <p>Hostinger International Ltd, 61 Lordou Vironos Street, 6023 Larnaca, Chypre — www.hostinger.fr.</p>
  <h2>Nature du site</h2>
  <p><?= e($config->isPortfolio()
      ? 'Agency OS est présenté ici comme un projet de portfolio : une démonstration fonctionnelle qui n\'accepte que des données fictives. L\'inscription est fermée ; seule la démonstration (données fictives, supprimées après 24 heures) est ouverte au public.'
      : 'Agency OS est un outil de pilotage de la rentabilité destiné aux agences digitales.') ?></p>
  <p>Les calculs, scores et recommandations sont des estimations de gestion indicatives. Ils ne constituent ni un conseil comptable, fiscal ou juridique, ni une certification.</p>
  <h2>Propriété intellectuelle</h2>
  <p>Le code, les textes et l'interface d'Agency OS sont protégés. Les polices Montserrat et Poppins sont distribuées sous licence SIL Open Font License ; la bibliothèque Chart.js sous licence MIT.</p>
  <p><a href="/confidentialite">Politique de confidentialité</a></p>
</article>
