<?php
/** @var string $tab */
$tabs = ['agence' => 'Agence', 'finances' => 'Finances', 'prestations' => 'Prestations', 'compte' => 'Compte', 'securite' => 'Sécurité', 'donnees' => 'Données'];
?>
<header class="page-header">
  <div class="page-header__titles"><h1>Paramètres</h1><p class="page-header__sub">Les données qui alimentent tous les calculs.</p></div>
</header>
<nav class="settings-tabs" aria-label="Sections des paramètres">
  <?php foreach ($tabs as $key => $label): ?>
    <a href="/app/parametres/<?= attr($key) ?>"<?php if ($tab === $key): ?> aria-current="page"<?php endif; ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
