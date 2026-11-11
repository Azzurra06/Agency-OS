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
| Cryptographie | OpenSSL (AES-256-GCM, RSA-OAEP) et bcrypt (S2) | libsodium (XChaCha20-Poly1305) et Argon2id |
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
| Phase 2 | Architecture, formules, sécurité, architecture détaillée | `02` à `05` | ✅ validé (S2 : OpenSSL pour le portfolio) |
| Phase 3 | UX/UI : design system, composants, écrans, états, bulles d'information | `07-ux-ui.md` et prototype statique (`prototype/`) | ✅ validé |
| Phase 4, lot 0 | Socle : bootstrap et configuration, profils `portfolio`/`production`, PDO, migrations, `Decimal`, middlewares de sécurité (en-têtes, session, CSRF, authentification, contexte d'organisation, limitation des tentatives), dépôts restreints à l'organisation, `FieldCrypto`, journalisation, `Mailer` en capture, exécuteur cron, CI | code et tests (§5) | ✅ validé |
| Phase 4, lot 1 | Démo (création, quotas, expiration, purge), authentification complète (comptes de test), layout, vue d'ensemble, profil et onboarding | code et tests (§6) | ✅ validé |
| Phase 4, lot 2 | Diagnostic (questionnaire v1, scoring, résultats partiels) | code et tests (§7) | ✅ validé |
| **Phase 4, lot 3** | Pricing (simulateur, aperçus, finalisation, versions) | code et tests (§8) | ⏳ livré, à valider |
| Phase 4, lot 4 | Revenus récurrents (offres, contrats, scénarios, projections) | — | — |
| Phase 4, lot 5 | Stratégie (moteur de règles, sources des recommandations, plan d'action) | — | — |
| Phase 4, lot 6 | Dashboard complet, historique, méthodologie, RGPD (export et suppression, utiles aux comptes de test), pages légales du portfolio | — | — |
| Phase 5 | Tests de sécurité (locaux) ; déploiement **portfolio** sur Hostinger, avec ton accord pour chaque action ; checklist de mise en ligne du portfolio | runbooks et checklist | — |
| **Plus tard** | **SaaS commercial** : choix de l'infrastructure isolée, A3, A5, A6, A7, A8 et A9, revue de sécurité complète, checklist « données réelles », documents RGPD définitifs, éventuellement facturation | — | ⏸ |

## 5. Lot 0 : ce qui a été livré (10/11/2026)

| Exigence | Mise en œuvre | Vérification |
|---|---|---|
| Calcul exact | `Support\Decimal` (BCMath, 12 décimales internes, arrondi unique moitié éloignée de zéro) | 20 tests, dont les cas limites de 03 §0.2 et PR-1 |
| Profils | `Support\Config` refuse au démarrage : données réelles ou SMTP en portfolio, HTTP ou cookie non sécurisé en production, clé trop courte | 7 tests |
| Isolation (niveaux 1 et 2) | organisation lue en session puis revérifiée en base à chaque requête ; `OrgScopedRepository` refuse toute requête sans `organization_id = :org` | tests de session falsifiée, d'époque révoquée, de démo expirée, de requête non restreinte |
| CSRF | jeton de session et vérification de `Origin` | tests sans jeton, avec un faux jeton, avec une origine étrangère |
| Authentification | compte actif, email vérifié, époque de session, permissions par rôle (refus par défaut), restrictions de la démo | 6 tests |
| Sessions | réglages forcés par le code (cookie `__Host-`, `Secure`, `HttpOnly`, `SameSite=Lax`, mode strict), stockage dédié, expiration après 60 min d'inactivité ou 12 h, **aucune session pour une visite anonyme** | tests d'expiration et de minimisation ; vérification HTTP réelle |
| En-têtes | CSP stricte avec Trusted Types, `nosniff`, `DENY`, COOP/CORP, `no-store`, HSTS en production, `noindex` hors production | test sur toutes les réponses, y compris les erreurs |
| Erreurs | message générique et référence d'incident ; journal sans montant ni email | test avec une exception contenant un montant et un email |
| Chiffrement des champs | `FieldCrypto` : AES-256-GCM (OpenSSL) ou XChaCha20-Poly1305 (sodium), clés versionnées hors base, AAD liant la valeur à la table, la colonne, l'organisation et la ligne | 18 tests de contrat sur les deux implémentations (altération, mauvaise clé, rotation, déplacement de valeur) |
| Mots de passe | bcrypt coût 12 (Argon2id si disponible), refus au-delà de 72 octets | 4 tests |
| Limitation des tentatives | fenêtre glissante, clés HMAC (aucune donnée en clair) | test de fenêtre, de casse et de purge |
| Journal de sécurité | événements en liste blanche, contexte filtré, IP et user-agent haché, purge à 6 mois | 4 tests |
| Validation | liste blanche des champs (mass assignment), décimaux en chaînes, pourcentages convertis par le serveur | 8 tests |
| Emails (A5) | `Mailer` : capture par défaut (fichiers en 600), liste blanche, SMTP PHPMailer prêt mais interdit en portfolio | 3 tests |
| Cron (A3) | `bin/cron.php` : une seule ligne cron, échéances en heure de Paris, anti-doublon, reprise après échec partiel, verrou, battement — **non programmé sur Hostinger** | 7 tests, dont les jours de changement d'heure |
| Sauvegardes (A7) | interface `BackupStorage` et stockage local immuable ; stockage externe non souscrit | — |
| Migrations | `0001_identity`, `0002_security` ; empreintes SHA-256 et détection d'une migration modifiée | 3 tests |
| Fichiers exposés | seul `public_html/index.php` est un fichier PHP public ; `.htaccess` refuse fichiers cachés et sensibles ; `Require all denied` dans tous les autres répertoires | tests de structure |
| Qualité | PHPStan **niveau max sans erreur** ; `tools/check-forbidden.php` (SQL concaténé, sinks XSS, sorties non échappées, fonctions dangereuses, aléa faible) | CI GitHub Actions : MariaDB 10.11 et MySQL 8.0, audit Composer, gitleaks |

**Total : 118 tests, 1 027 assertions, tous verts en local** (PHP 8.3, MariaDB 10.11).

## 6. Lot 1 : ce qui a été livré (11/11/2026)

| Exigence | Mise en œuvre | Vérification |
|---|---|---|
| Profil financier | Migration `0003_finance` ; `FinancialCalculator` (période et annualisation, résultat estimé avec inclus/exclus, coût horaire, capacité, taux de charges variables générales, complétude) ; montants **chiffrés** en base ; journal des seules modifications réelles (valeurs chiffrées) | cas PER-1, RES-1, HC-1 à HC-3 ; aucun montant en clair en base ; valeur recopiée vers une autre agence refusée |
| Démo | Espace « Agence Lumen » fictif et isolé, 24 h, 5 par IP et par heure, 500 actives, quota de prestations, réinitialisation sans prolonger l'expiration, purge horaire et de secours, suppression vérifiée table par table | 6 tests ; parcours complet dans le navigateur |
| Authentification | Connexion à temps constant, limitation email+IP/email/IP, blocage même avec le bon mot de passe ; mot de passe oublié (jeton à usage unique de 30 min, retiré de l'URL, `no-referrer`) ; vérification de l'email (24 h, renvois limités) ; comptes de test en ligne de commande | 8 tests ; correction d'un défaut détecté : l'en-tête global écrasait `no-referrer` |
| Interface | Layout (sidebar, barre d'onglets mobile, bandeau démo avec compte à rebours) ; vue d'ensemble (résultat estimé, objectif, repères de production, complétude, états vides pour les modules à venir) ; onboarding en 3 étapes passables ; paramètres Agence / Finances / Prestations ; bulles d'information cliquables | parcours navigateur desktop et mobile, sans défilement horizontal ni violation CSP |
| API | `organization`, `financial-profile` (+ `derived`, `changes`), `services` ; décimaux en chaînes, taux en pourcentage, liste blanche | 422 sur nombre JSON ou champ inconnu, 400 sur JSON invalide, 404 et journalisation sur une prestation d'une autre agence |

**Total : 156 tests, 1 495 assertions**, PHPStan niveau max sans erreur, aucun motif interdit.

## 7. Lot 2 : ce qui a été livré (11/11/2026)

| Exigence | Mise en œuvre | Vérification |
|---|---|---|
| Questionnaire versionné | `app/Domain/Diagnostic/Questionnaires/v1.php` (30 questions, 6 dimensions, politiques « Je ne sais pas », conditions, préremplissage) ; un diagnostic est toujours relu avec **sa** version | empreinte SHA-256 de la v1 vérifiée par un test : toute modification impose une v2 |
| Scoring | `DiagnosticScorer` (fonctions pures, BCMath) : score par dimension, seuils de calculabilité (n ≥ 3 et 60 %), score global seulement si les 6 dimensions sont calculables, fiabilité, forces et axes | cas DIAG-1 (58,06 · forces Fidélisation et Organisation · axes Stratégie et Rentabilité), DIAG-2 (diagnostic partiel), conditions, bornes des seuils et des niveaux |
| Cycle de vie | Migration `0004_diagnostic` : un seul brouillon par agence (colonne générée unique), réponses et scores liés par clés étrangères composites ; clôture transactionnelle qui **fige** les scores (2 décimales) ; « Compléter ces réponses » crée un nouveau brouillon prérempli ; abandon du brouillon possible, jamais d'un diagnostic terminé | 6 tests fonctionnels : reprise du brouillon, réponses refusées après clôture, double clôture refusée, 27 questions pour une agence solo sans maintenance |
| Préremplissage | `fin.hourly_cost` suggéré depuis le profil (coût calculé → niveau 2, saisi → niveau 1), signalé « Issu de votre profil, modifiable » | test dédié |
| Isolation | Toutes les requêtes passent par `OrgScopedRepository` ; un identifiant d'une autre agence renvoie 404 et est journalisé ; impossible de recopier le diagnostic d'une autre agence | test d'isolation (lecture, écriture, clôture, copie, page HTML) |
| Interface | Accueil (Commencer / Reprendre x/6, dernier résultat, historique) ; questionnaire par dimension avec sauvegarde automatique séquentielle, questions conditionnelles affichées ou masquées selon les règles du serveur, explication de la politique « Je ne sais pas » sous chaque question ; résultats figés (anneau de score ou « Diagnostic partiel », radar Chart.js local avec axes non calculables grisés et jamais placés à 0, forces et axes, détail par dimension, comparaison avec le diagnostic précédent si même version) ; carte « Maturité business » sur la vue d'ensemble | parcours navigateur desktop et mobile : aucun défilement horizontal, aucune violation CSP ni Trusted Types |
| Démo | Lumen dispose d'un diagnostic terminé cohérent avec DIAG-1 (score 58 affiché dès l'arrivée) ; la réinitialisation le recrée | test dédié |

**Compatibilité MySQL 8.** La CI a détecté que MySQL 8 (contrairement à MariaDB) refuse une clé étrangère `ON DELETE CASCADE` sur une colonne de base d'une colonne générée stockée : la colonne `draft_key` ne dérive plus que de `status`, avec un index unique (`organization_id`, `draft_key`). La même règle est reportée dans 05 pour les simulations du lot 3.

**Diagnostic partiel (ajouté à ta demande).** Avec le questionnaire v1, une seule question par dimension a la politique « inconnue » : une clôture complète ne peut donc pas produire de dimension non calculable. Plutôt que de changer le sens des questions (une pratique qu'on ignore reste une pratique absente), la clôture est désormais possible **avec des questions sans réponse, après confirmation explicite** (« Terminer quand même ») : elles sont exclues du calcul comme une réponse inconnue, les dimensions trop incomplètes sont « Données insuffisantes », le score global n'est pas affiché et l'encadré « Informations manquantes » liste chaque question (« sans réponse » ou « Je ne sais pas ») avec **[Compléter ces réponses]**. La clôture reste refusée si aucune dimension n'est calculable. Règle reportée dans 03 §6.3 et 05 §3.3 ; le fichier v1 n'est pas modifié (empreinte inchangée).

**Total : 168 tests, 1 788 assertions**, PHPStan niveau max sans erreur, aucun motif interdit.

## 8. Lot 3 : ce qui a été livré (11/11/2026)

| Exigence | Mise en œuvre | Vérification |
|---|---|---|
| Formules | `PricingCalculator` (BCMath, fonctions pures) : heures avec aléas, coût direct et de structure, prix plancher, prix recommandé (marge **sur prix de vente** exacte), résultat, marge sur prix de vente, majoration sur coût, écart, manque à gagner ; arrondi commercial proposé **à côté** du prix exact (réglage `price_rounding`) ; erreurs stables (`margin_plus_variable_rate_too_high`, `empty_scenario`, `hourly_cost_unavailable`…) | cas PR-1 à PR-4 au centime, bornes, alertes, reproductibilité |
| Entrées du profil | Coût horaire et taux de charges variables générales lus **exacts** (non arrondis) dans le profil ; coût horaire personnalisé possible par scénario ; taux non calculable signalé (0 % utilisé, alerte) | tests de l'aperçu (67,01 €/h, 2 %) |
| Aperçu serveur | `POST /api/v1/pricing/calculate` : rien n'est enregistré ; anti-rebond de 350 ms, requête précédente annulée (`AbortController`), réponse périmée ignorée (`X-Request-Seq` renvoyé) ; `affects_strategy: false` | test (en-tête renvoyé, aucune ligne écrite) ; navigateur |
| Scénarios | 1 à 3 par simulation, duplication, scénario de référence, lignes de temps et de frais directs (40 au plus), modèles de temps indicatifs sur demande avec l'avertissement « pas une référence de marché » | tests (limite de 3, suppression du dernier refusée) |
| Cycle de vie | Migration `0005_pricing` ; finalisation transactionnelle : scénarios tous calculables, contexte (coût horaire, `tv`, période, prix et volume de la prestation) et résultats **figés et chiffrés (D4)** ; la simulation active précédente de la prestation passe en `superseded` ; **au plus une simulation active par prestation garantie par la base** ; nouvelle version (un seul brouillon par famille), archivage, historique des versions ; 409 sur toute modification d'une version figée | tests (409, chiffrement `k1.gcm:` sans valeur en clair, contrainte unique, versions) |
| Dérive | Indicateur calculé à la lecture si le coût horaire (scénarios qui suivent le profil) ou `tv` ont changé depuis la finalisation ; les valeurs figées ne bougent pas | test |
| Isolation | Dépôt restreint à l'organisation, clés étrangères composites ; 404 identique à « inexistant » et journalisation pour une simulation, un scénario ou une prestation d'une autre agence (y compris via l'aperçu) | test d'isolation (8 accès refusés, aucune écriture) |
| Interface | Liste avec filtres (actives, brouillons, remplacées et archivées) et écart au prix recommandé ; simulateur (paramètres, résultats en direct, alertes locales, bulles d'information, enregistrement explicite, finalisation confirmée) ; version figée en lecture seule avec bandeau, dérive et historique ; comparaison des scénarios ; résumé fixe sur mobile | parcours navigateur desktop et mobile : aucune erreur console, aucun défilement horizontal |
| Démo | Lumen : « Site vitrine WordPress » finalisé (2 scénarios, prix actuel 3 200 € sous le prix recommandé) et un brouillon « Refonte de site » | test dédié |

Une prestation supprimée laisse ses simulations figées lisibles (le contexte figé garde son nom, son prix et son volume). La réévaluation des recommandations après finalisation sera branchée au lot 5 (Stratégie).

**Total : 182 tests, 2 013 assertions**, PHPStan niveau max sans erreur, aucun motif interdit.

