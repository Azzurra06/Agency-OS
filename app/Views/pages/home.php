<?php
/** @var bool $isPortfolio */
?>
<section class="card public-card stack">
  <p class="eyebrow">Projet de portfolio · données fictives uniquement</p>
  <h1>Pilotez la rentabilité de votre agence</h1>
  <p class="text-secondary">Structurez vos offres et prenez les bonnes décisions pour développer votre activité. Agency OS relie diagnostic, pricing, revenus récurrents et stratégie dans un plan d'action priorisé.</p>
  <div class="public-actions">
    <a class="btn btn--primary btn--lg" href="/demo">Explorer la démo</a>
    <a class="btn btn--secondary btn--lg" href="/connexion">Connexion</a>
  </div>
  <p class="text-small"><a href="/methodologie">Comment sont faits les calculs ?</a> · <a href="/glossaire">Glossaire des termes</a></p>
  <?php if ($isPortfolio): ?>
    <p class="text-small text-tertiary">Cette instance est une démonstration : elle n'accepte aucune donnée réelle.</p>
  <?php endif; ?>
</section>
