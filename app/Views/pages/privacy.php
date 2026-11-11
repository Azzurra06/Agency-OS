<?php
/** @var \App\Support\Config $config */
$todo = 'à compléter avant la mise en ligne';
$contact = $config->legalContactEmail !== '' ? $config->legalContactEmail : $todo;
?>
<article class="card public-card stack">
  <h1>Politique de confidentialité</h1>
  <?php if (!$config->legalComplete()): ?><p class="flash flash--error" role="alert">Identité et contact du responsable de traitement à compléter avant la mise en ligne (configuration LEGAL_*).</p><?php endif; ?>
  <p class="text-small text-secondary">Version du 11/11/2026.</p>

  <h2>Responsable de traitement</h2>
  <p><?= e($config->legalPublisher !== '' ? $config->legalPublisher : $todo) ?>, éditrice ou éditeur d'Agency OS. Contact : <?= e($contact) ?>.</p>

  <h2>Démonstration</h2>
  <p>La démonstration ne demande aucune information personnelle et ne contient que des données fictives (agence « Lumen »). Chaque espace de démonstration est isolé et supprimé automatiquement 24 heures après sa création. N'y saisissez jamais les chiffres réels de votre entreprise.</p>

  <h2>Données traitées pour les comptes</h2>
  <ul>
    <li><strong>Compte</strong> : adresse email, mot de passe (stocké uniquement sous forme d'empreinte bcrypt), dates de création et de connexion.</li>
    <li><strong>Données de l'agence</strong> : identité de l'agence, profil financier, prestations, diagnostics, simulations, formules récurrentes, recommandations, plan d'action, historique quotidien des indicateurs.</li>
    <li><strong>Sécurité</strong> : journal des événements de sécurité (connexions, tentatives refusées), avec une adresse IP, pendant 6 mois.</li>
  </ul>
  <p>Finalités : fournir le service, sécuriser les comptes et prévenir les abus. Bases légales : exécution du service demandé ; intérêt légitime pour la sécurité.</p>

  <h2>Cookies</h2>
  <p>Un seul cookie, strictement nécessaire : le cookie de session, posé uniquement lorsque vous vous connectez, lancez la démonstration ou envoyez un formulaire. Aucun cookie de mesure d'audience ni de publicité, aucun outil de suivi tiers.</p>

  <h2>Sécurité</h2>
  <p>Chiffrement des données financières en base, avec des clés stockées séparément de la base ; connexions HTTPS ; isolation stricte entre agences ; journalisation sans données financières. Les données de votre agence ne sont jamais utilisées pour entraîner un modèle ni partagées.</p>

  <h2>Durées de conservation</h2>
  <ul>
    <li>Démonstration : 24 heures.</li>
    <li>Compte et données de l'agence : jusqu'à la suppression du compte. Après une demande confirmée, le compte est désactivé immédiatement et supprimé définitivement 7 jours plus tard.</li>
    <li>Sauvegardes : les données supprimées disparaissent des sauvegardes à l'expiration de leur durée de conservation (durée de l'hébergeur à confirmer avant la mise en ligne). Un registre minimal des suppressions (identifiant technique et date, sans donnée) permet de les réappliquer après une restauration.</li>
    <li>Journal de sécurité : 6 mois.</li>
  </ul>

  <h2>Destinataires</h2>
  <p>Hébergement : Hostinger International Ltd (Chypre). Aucune autre transmission à des tiers.</p>

  <h2>Vos droits</h2>
  <p>Vous disposez des droits d'accès, de rectification, d'effacement, de limitation, d'opposition et de portabilité. Depuis l'application : <strong>Paramètres › Données</strong> pour exporter toutes vos données (fichier JSON) ou supprimer votre compte. Pour toute autre demande : <?= e($contact) ?>. Vous pouvez aussi saisir la CNIL (www.cnil.fr).</p>
  <p><a href="/mentions-legales">Mentions légales</a> · <a href="/methodologie">Méthodologie</a></p>
</article>
