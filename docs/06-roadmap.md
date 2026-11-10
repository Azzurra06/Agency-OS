# Agency OS — Roadmap : MVP portfolio, puis SaaS commercial

> Statut : **adopté le 10/11/2026**.
> Principe : **un seul code**, deux profils d'exploitation. Le portfolio n'est pas une maquette : c'est le vrai produit, sécurisé au niveau du code, mais exploité sur une infrastructure de démonstration et **avec des données fictives uniquement**.

---

## 1. Deux cibles distinctes

| | **MVP portfolio** (maintenant) | **SaaS commercial** (plus tard) |
|---|---|---|
| Objectif | Démontrer tes compétences et toutes les fonctionnalités | Servir de vraies agences |
| Données | **Fictives uniquement** : démo de 24 h et comptes de test créés en ligne de commande | Données financières réelles et confidentielles |
| Hébergement | `agency-os.azzurra-pro.com` (Hostinger Premium, compte partagé), base dédiée `u700770059_agencyos` | Infrastructure **isolée et dédiée**, à choisir (D1) |
| Profil applicatif | `APP_PROFILE=portfolio`, `APP_REAL_DATA_ALLOWED=false` | `APP_PROFILE=production`, `APP_REAL_DATA_ALLOWED=true` après la checklist de [04 §14.3](04-securite.md) |
| Inscription publique | **Désactivée** : l'écran oriente vers la démo | Activée, avec vérification de l'email |
| Emails | Transport `capture` (aucun envoi) | SMTP authentifié avec PHPMailer, DMARC durci |
| Cron | Scripts développés et testés, **non programmés** ; purge de la démo à la volée | Programmés (instantanés, purges, sauvegardes, alertes) |
| Sauvegardes | Inutiles pour des démos éphémères ; la base peut être recréée par les migrations et le jeu de démo | N2 chiffrées et N3 externes européennes immuables |
| Durcissement PHP | Par le code (04 §6.3) | Par le code **et** la configuration du serveur |
| Préproduction | Environnement local de développement | Sous-domaine isolé (D11) |
| Sécurité du code | **Complète** : authentification, sessions, autorisations, isolation, validation, CSRF, XSS, SQL, secrets, chiffrement des champs | Identique, avec en plus la revue et le scan dynamique sur la préproduction |

## 2. Ce que le profil `portfolio` impose dans le code

1. **Aucune donnée réelle ne peut être conservée** :
   - pas d'inscription publique ;
   - les espaces de démo expirent après **24 h** et sont purgés ;
   - pas d'export, pas d'email ;
   - aucun moyen de retrouver un espace de démo après son expiration.
2. **Aucune invitation à saisir de vraies données** :
   - bandeau permanent « Démonstration : données fictives » ;
   - formulaires financiers **préremplis avec les données fictives** de l'agence « Lumen » ;
   - mention sous chaque formulaire financier : « N'indiquez pas les chiffres réels de votre entreprise » ;
   - aucun texte du type « Saisissez votre CA » sans le contexte de la démo.
3. **Comptes de test** (pour présenter les parcours de connexion, de mot de passe oublié et de vérification de l'email) : créés uniquement par `bin/create-test-account.php` ; les emails correspondants sont lisibles dans la capture locale.
4. **Contrôles au démarrage** : en profil `portfolio`, l'application refuse de démarrer si `APP_REAL_DATA_ALLOWED=true` ou si un transport d'email réel est configuré.

## 3. Facilité de transfert vers la production

- **Aucune dépendance au fournisseur dans le code métier.** Tout ce qui est propre à l'hébergement est isolé derrière des interfaces : `Mailer`, `BackupStorage`, `Clock`, `CryptoProvider` (en fonction de la décision S1 ou S2) et les scripts de déploiement.
- **Configuration uniquement par `config/.env`**, sur le modèle 12-factor. Le fichier `.env.example` documente chaque variable et indique si elle est exigée en production.
- **Migrations versionnées** et jeu de démo séparé.
- **Guide de migration** `docs/runbooks/passage-production.md` (phase 5) : nouvel environnement, génération de **tous** les secrets, programmation du cron, SMTP, stockage externe, DMARC, préproduction, revue de sécurité, puis levée de `APP_REAL_DATA_ALLOWED`. **Aucune donnée du portfolio n'est migrée** : elles sont fictives.

## 4. Roadmap

| Étape | Contenu | Livrable | Statut |
|---|---|---|---|
| Phase 1 | Cadrage | `01-cadrage.md` | ✅ validé |
| Phase 2 | Architecture, formules, sécurité, architecture détaillée | `02` à `05` | ✅ validé (sauf la décision S1 ou S2 sur sodium) |
| **Phase 3** | **UX/UI** : design system, composants, écrans, états | `07-ux-ui.md` et, si tu le souhaites, un **prototype statique** du design system | ⏳ en cours |
| Phase 4, lot 0 | **Socle** : bootstrap et configuration, profils `portfolio`/`production`, PDO, migrations, `Decimal`, middlewares de sécurité (en-têtes, session, CSRF, authentification, contexte d'organisation, limitation des tentatives), dépôts restreints à l'organisation, `FieldCrypto`, journalisation, `Mailer` en capture, exécuteur cron, CI | code et tests | — |
| Phase 4, lot 1 | Démo (création, quotas, expiration, purge), authentification complète (comptes de test), layout, dashboard squelette, profil et onboarding | — | — |
| Phase 4, lot 2 | Diagnostic (questionnaire v1, scoring, résultats partiels) | — | — |
| Phase 4, lot 3 | Pricing (simulateur, aperçus, finalisation, versions) | — | — |
| Phase 4, lot 4 | Revenus récurrents (offres, contrats, scénarios, projections) | — | — |
| Phase 4, lot 5 | Stratégie (moteur de règles, sources des recommandations, plan d'action) | — | — |
| Phase 4, lot 6 | Dashboard complet, historique, méthodologie, RGPD (export et suppression, utiles aux comptes de test), pages légales du portfolio | — | — |
| Phase 5 | Tests de sécurité (locaux) ; déploiement **portfolio** sur Hostinger, avec ton accord pour chaque action ; checklist de mise en ligne du portfolio | runbooks et checklist | — |
| **Plus tard** | **SaaS commercial** : choix de l'infrastructure isolée, A3, A5, A6, A7, A8 et A9, revue de sécurité complète, checklist « données réelles », documents RGPD définitifs, éventuellement facturation | — | ⏸ |
