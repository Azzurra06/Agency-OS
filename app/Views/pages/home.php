<?php
/** @var bool $isPortfolio */
?>
<section class="card public-card stack">
  <p class="eyebrow">Projet de portfolio · données fictives uniquement</p>
  <h1>Pilotez la rentabilité de votre agence</h1>
  <p class="text-secondary">Structurez vos offres et prenez les bonnes décisions pour développer votre activité. Agency OS relie diagnostic, pricing, revenus récurrents et stratégie dans un plan d'action priorisé.</p>
  <p class="alert alert--neutral"><span>Le socle technique est en place. La démonstration interactive sera disponible avec le lot 1.</span></p>
  <?php if ($isPortfolio): ?>
    <p class="text-small text-tertiary">Cette instance est une démonstration : elle n'accepte aucune donnée réelle.</p>
  <?php endif; ?>
</section>
