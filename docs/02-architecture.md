# Agency OS — Phase 2 : Architecture

> Statut : **proposition, en attente de validation**
> Prérequis : [01-cadrage.md](01-cadrage.md) (validé).
> Les formules financières et le scoring sont documentés à part : [03-formules-et-scoring.md](03-formules-et-scoring.md).
> Ce document décrit l'architecture ; il ne contient pas encore de code d'implémentation.

---

## 1. Principes d'architecture

1. **Les formules n'existent qu'en un seul endroit : le PHP.** Les aperçus instantanés passent par des endpoints de calcul sans état (`POST /api/v1/.../calculate`), appelés au fil de la saisie. Le JavaScript ne contient **aucune formule métier**. C'est la réponse directe à l'exigence « ne pas dupliquer les formules » (cela remplace la piste « double implémentation PHP + JS » évoquée en phase 1).
2. **On ne stocke pas les valeurs dérivées modifiables.** Le coût horaire, le prix recommandé, le MRR ou le résultat estimé sont **recalculés à la lecture** à partir des données sources. Aucun cache ne peut donc se désynchroniser. Il y a deux exceptions, toutes deux **immuables par nature** :
   - les scores d'un diagnostic terminé (photo à une date donnée) ;
   - les instantanés d'indicateurs (historique).
3. **Calculs purs.** Les calculateurs (`Domain/*Calculator`) ne touchent ni à la base de données, ni à la session, ni au HTTP. Ils reçoivent des objets de valeur et renvoient des résultats. Ils sont donc testables unitairement et réutilisables, par exemple par un futur export PDF.
4. **Isolation par organisation dès la couche d'accès aux données.** Chaque méthode de dépôt (repository) exige un `organizationId`. Aucune requête métier ne peut s'exécuter sans lui.
5. **Aucune dépendance en production.** Chart.js est stocké localement. L'autoloader PSR-4 est écrit à la main. Composer ne sert qu'au développement (PHPUnit).
6. **Compatible avec l'hébergement mutualisé.** Pas de processus permanent. Une tâche cron Hostinger sert à la purge de la démo. `.htaccess` est pris en charge (Apache / LiteSpeed).

---

## 2. Vue d'ensemble

```
Navigateur
  │  pages HTML (rendues par PHP)            appels JSON (fetch + jeton CSRF)
  ▼                                           ▼
public_html/index.php  ──►  Kernel  ──►  Router ──┬──► PageController ──► Vue PHP (layout + page)
                                                   └──► Api\*Controller ──► JSON
                                │
                     Middlewares : en-têtes de sécurité → session → CSRF → authentification → contexte organisation
                                │
                     Services applicatifs (orchestration, autorisations, transactions)
                       │                    │                        │
             Domaine (calculs purs)   Moteur de règles        Repositories (PDO, requêtes préparées)
                                                                     │
                                                                   MySQL
```

### Arborescence sur le serveur

```
domains/agency-os.azzurra-pro.com/
├── app/                              ← non public
│   ├── bootstrap.php                 autoloader, configuration, gestion des erreurs
│   ├── Http/
│   │   ├── Kernel.php  Router.php  Request.php  Response.php
│   │   ├── Middleware/               SecurityHeaders, Session, Csrf, Authenticate, OrganizationContext, RateLimit
│   │   ├── Controllers/Pages/        une méthode par écran (rendu de vues)
│   │   └── Controllers/Api/          Auth, Organization, FinancialProfile, Services, Diagnostic,
│   │                                 Pricing, Recurring, Recommendations, ActionPlan, Dashboard, Account, Demo
│   ├── Application/                  services applicatifs (cas d'usage)
│   ├── Domain/
│   │   ├── Finance/                  ReferencePeriod, FinancialProfile (VO), FinancialCalculator
│   │   ├── Pricing/                  PricingInput, PricingCalculator, PricingResult
│   │   ├── Recurring/                OfferInput, RecurringCalculator, ProjectionResult
│   │   ├── Diagnostic/               Questionnaire (définition versionnée), DiagnosticScorer
│   │   └── Strategy/                 Facts, Rule (interface), Rules/*, RecommendationProvider, RulesProvider
│   ├── Infrastructure/
│   │   ├── Database/                 Connection (PDO), Transaction, Migrator
│   │   └── Repositories/             un dépôt par agrégat, tous limités à une organisation
│   ├── Support/                      Validator, Env, Csrf, Money/Number (arrondis), Clock, Logger, Escaper
│   └── Views/                        layouts/, pages/, partials/ (PHP natif, échappement systématique)
├── config/                           ← non public
│   ├── .env                          (non versionné) identifiants de la base, clé d'application, URL
│   └── app.php                       lit .env et renvoie un tableau de configuration
├── database/
│   ├── migrations/                   0001_init.sql, 0002_..., appliquées dans l'ordre
│   └── seeds/demo.php                jeu de données « Agence Lumen »
├── bin/                              scripts CLI : migrate.php, purge-demo.php, seed-demo.php
├── storage/logs/                     journaux applicatifs (non publics)
├── tests/                            Unit/, Functional/, fixtures/*.json
└── public_html/                      ← seule racine web
    ├── index.php                     contrôleur frontal (require ../app/bootstrap.php)
    ├── .htaccess                     réécriture vers index.php, interdiction des fichiers sensibles, en-têtes de cache
    └── assets/
        ├── css/                      tokens.css, base.css, components/*.css, pages/*.css
        ├── js/
        │   ├── core/                 api.js, format.js (Intl fr-FR), dom.js, debounce.js
        │   ├── ui/                   toast, modal, tabs, stepper, form, empty-state, tooltip
        │   ├── charts/               theme.js, radar.js, bars.js, line.js (enveloppes de Chart.js)
        │   └── pages/                dashboard.js, onboarding.js, diagnostic.js, pricing.js, recurring.js, strategy.js, settings.js
        ├── vendor/chart.umd.min.js   (version figée, ex. 4.4.x)
        └── fonts/  icons/            police et icônes SVG auto-hébergées (sprite)
```

Le dépôt Git reproduit exactement cette arborescence. Le déploiement copie le dépôt dans `domains/agency-os.azzurra-pro.com/` : seul `public_html/` est servi.

### Choix justifiés

| Choix | Pourquoi |
|---|---|
| Pages PHP et modules JS (validé) | Pas de routeur client, premier affichage rapide, sécurité plus simple (sessions et CSRF classiques), aucune étape de build |
| Vues en PHP natif | Pas besoin d'un moteur de templates : une fonction `e()` d'échappement suffit et la règle est appliquée par revue de code et par les tests |
| Chart.js stocké localement | Fonctionne sans CDN et permet une politique de sécurité de contenu (CSP) stricte `script-src 'self'` |
| Autoloader écrit à la main | Rien à installer sur Hostinger, pas de dossier `vendor/` en production |
| ES modules natifs (`<script type="module">`) | Pris en charge par tous les navigateurs ciblés, sans bundler |
| Pas de bibliothèque JS supplémentaire | `Intl.NumberFormat` couvre le formatage ; le DOM natif suffit pour les composants |

---

## 3. Flux d'une requête

### Page
`GET /app/pricing/42` passe par les middlewares (session, authentification, contexte organisation), puis `PricingPageController::show(42)`. Le contrôleur vérifie que la simulation appartient à l'organisation (sinon 404) et rend la page : layout, sidebar et conteneur, avec les données initiales injectées dans un `<script type="application/json">` échappé. Le module `pages/pricing.js` prend ensuite le relais.

### API
`PUT /api/v1/pricing/scenarios/17` passe par les middlewares (dont la vérification CSRF), puis `PricingApiController::updateScenario`. Le `Validator` contrôle les champs. `PricingService::updateScenario(orgId, 17, input)` s'exécute dans une transaction, puis émet `DataChanged('pricing')`. Les écouteurs `RecommendationService::reevaluate` et `SnapshotService::capture` sont appelés en synchrone. La réponse est un JSON contenant le scénario et ses résultats recalculés.

### Aperçu instantané
À chaque saisie, avec un délai de 250 ms, `POST /api/v1/pricing/calculate` est appelé avec les paramètres du formulaire. Le serveur valide, calcule et renvoie le résultat. Rien n'est enregistré. Un `AbortController` annule la requête précédente. Pendant le calcul, la dernière valeur reste affichée avec un indicateur discret. En cas d'erreur réseau, un message propose de réessayer.

### Événements internes

Un **dispatcher synchrone minimal**, sans file d'attente, adapté au mutualisé :

| Événement | Émis par | Écouteurs |
|---|---|---|
| `FinancialProfileChanged` | profil, catalogue | recommandations, instantané |
| `DiagnosticCompleted` | diagnostic | recommandations, instantané |
| `PricingChanged` | pricing | recommandations |
| `RecurringChanged` | offres, contrats | recommandations, instantané |
| `ActionPlanChanged` | plan | instantané |

Les instantanés sont regroupés : **un par organisation et par jour**. Le dernier écrase le précédent du même jour.

---

## 4. Sécurité

| Sujet | Mise en œuvre |
|---|---|
| Mots de passe | `password_hash(PASSWORD_DEFAULT)` (bcrypt, ou argon2id si disponible) et `password_needs_rehash` à la connexion. 10 caractères minimum, contrôle contre une petite liste de mots de passe courants |
| Session | Nom de cookie dédié ; `HttpOnly`, `Secure`, `SameSite=Lax` ; `session.use_strict_mode=1` ; `session_regenerate_id(true)` à la connexion et à l'élévation de droits ; expiration après 2 h d'inactivité et 12 h au maximum ; déconnexion = destruction côté serveur et suppression du cookie |
| CSRF | Jeton synchronisé par session (32 octets aléatoires), exposé dans `<meta name="csrf-token">`, envoyé dans l'en-tête `X-CSRF-Token` par `api.js` et vérifié avec `hash_equals` pour toute méthode autre que GET/HEAD. Contrôle complémentaire de l'en-tête `Origin` |
| Limitation des tentatives | Table `rate_limit_hits`. Connexion : 5 échecs par couple email + IP en 15 min, puis blocage de 15 min ; 30 échecs par IP en 15 min. Le message d'erreur reste générique (« Identifiants invalides ») et le temps de réponse est constant, même pour un email inconnu. Création de démo : 5 par IP et par heure |
| XSS | Échappement systématique `e()` dans les vues. En JavaScript, uniquement `textContent` et des nœuds créés par le DOM, jamais `innerHTML` avec une donnée. CSP : `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'` |
| SQL | PDO avec `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false` et `utf8mb4` ; requêtes préparées exclusivement. Les tris dynamiques passent par une liste blanche |
| Isolation | `OrganizationContext` résout l'organisation active depuis la session et vérifie l'appartenance (`organization_members`). Les dépôts filtrent `WHERE organization_id = ?`. Un identifiant étranger donne un **404**, pas un 403, pour ne pas révéler son existence |
| Autorisations | Une `Policy` par ressource (rôles `owner`, `admin`, `member`). Au MVP, seul `owner` existe, mais chaque contrôleur appelle `authorize()` dès maintenant |
| Validation | `Validator` déclaratif : type, bornes, longueur, énumérations, dates. Les erreurs sont renvoyées champ par champ (`422`). Les montants vont de 0 à 99 999 999,99 ; les taux sont compris entre 0 et 1 |
| Erreurs | Gestionnaire global : journal détaillé dans `storage/logs/` (sans mot de passe ni jeton) ; réponse générique `{"error":{"code":"internal_error","message":"Une erreur est survenue."}}`. `display_errors=0` en production |
| En-têtes | `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` restrictive, HSTS une fois le HTTPS confirmé |
| Fichiers | `.htaccess` interdit l'accès aux fichiers cachés et sensibles. Le code et la configuration sont **hors de** `public_html` |
| Suppression de compte | Confirmation par mot de passe, puis transaction : suppression de l'organisation (cascade) et de l'utilisateur |

---

## 5. Schéma MySQL

Conventions :
- moteur InnoDB, `utf8mb4_unicode_ci` ;
- `id BIGINT UNSIGNED AUTO_INCREMENT` ;
- `created_at` et `updated_at` en `DATETIME` (UTC) sur chaque table ;
- montants en `DECIMAL(12,2)`, taux en `DECIMAL(6,4)` (fraction : 0,6500 = 65 %), heures en `DECIMAL(8,2)` ;
- toutes les tables métier portent `organization_id`, avec une clé étrangère `ON DELETE CASCADE`.

### 5.1 Comptes et organisations

**users**
| Colonne | Type | Notes |
|---|---|---|
| id | PK | |
| email | VARCHAR(190) | UNIQUE, stocké en minuscules |
| password_hash | VARCHAR(255) | NULL pour les utilisateurs de démo (pas de connexion par mot de passe) |
| first_name, last_name | VARCHAR(100) | facultatifs |
| is_demo | TINYINT(1) | défaut 0 |
| last_login_at | DATETIME NULL | |

**organizations**
| Colonne | Type | Notes |
|---|---|---|
| id | PK | |
| name | VARCHAR(150) | |
| activity_type | ENUM('wordpress','web','digital','marketing','other') NULL | |
| team_size | SMALLINT UNSIGNED NULL | nombre total de personnes |
| legal_status | ENUM('micro','ei','eurl','sarl','sasu','sas','other') NULL | |
| plan | VARCHAR(30) | défaut `'free'` (préparation des abonnements) |
| is_demo | TINYINT(1) | défaut 0 |
| demo_expires_at | DATETIME NULL | INDEX (is_demo, demo_expires_at) |

**organization_members** : `organization_id` et `user_id` (clé primaire composite, clés étrangères en cascade), `role` ENUM('owner','admin','member'), `created_at`. Cette table prépare l'arrivée de plusieurs collaborateurs.

**organization_settings** (1-1, clé primaire `organization_id`)
| Colonne | Type | Notes |
|---|---|---|
| currency | CHAR(3) | `'EUR'` |
| locale | VARCHAR(10) | `'fr-FR'` |
| onboarding_step | TINYINT | 0 à 3 |
| onboarding_completed_at | DATETIME NULL | |
| price_rounding | ENUM('none','1','10','50','100') | arrondi commercial proposé, affiché en complément |

### 5.2 Profil financier (une ligne par organisation)

**financial_profiles** : UNIQUE(organization_id). Les montants portent sur la **période déclarée**. `NULL` signifie « non renseigné », jamais 0.

| Colonne | Type | Notes |
|---|---|---|
| period_type | ENUM('closed_fiscal_year','trailing_12_months') | défaut `closed_fiscal_year` |
| period_start, period_end | DATE NULL | contrainte : start < end ; durée de 1 à 24 mois |
| revenue | DECIMAL NULL | CA HT de la période |
| fixed_costs | DECIMAL NULL | charges fixes hors personnel |
| personnel_costs | DECIMAL NULL | coût employeur chargé des salariés |
| variable_costs_attributable | DECIMAL NULL | frais directement attribuables aux projets (total de la période) |
| variable_costs_general | DECIMAL NULL | charges variables générales non attribuables |
| owner_comp_total_cost | DECIMAL NULL | coût complet annuel de la rémunération du dirigeant, cotisations comprises |
| owner_comp_included_amount | DECIMAL NULL | part déjà incluse dans `fixed_costs` ou `personnel_costs` |
| production_fte | DECIMAL(5,2) NULL | ETP qui produisent des heures facturables (dirigeant compris s'il produit) |
| annual_hours_per_fte | DECIMAL(7,2) NULL | |
| annual_hours_is_assumption | TINYINT(1) | 1 si la valeur par défaut (1 607 h) a été acceptée |
| billable_rate | DECIMAL(6,4) NULL | |
| billable_rate_is_assumption | TINYINT(1) | |
| hourly_cost_override | DECIMAL NULL | coût horaire saisi manuellement (remplace le calcul, signalé comme tel) |
| revenue_target | DECIMAL NULL | objectif de CA annuel HT |
| revenue_target_year | SMALLINT NULL | exercice visé |
| recurring_share_declared | DECIMAL(6,4) NULL | part récurrente déclarée (valeur de référence) |
| recurring_share_updated_at | DATETIME NULL | |

> Choix : une seule ligne par organisation au MVP. L'historique est porté par `kpi_snapshots`. Passer à plusieurs exercices plus tard reviendra à retirer la contrainte UNIQUE et à ajouter `is_current`.

### 5.3 Catalogue et pricing

**services** (prestations ponctuelles)
| Colonne | Type | Notes |
|---|---|---|
| organization_id | FK | INDEX |
| name | VARCHAR(150) | |
| category | ENUM('showcase','ecommerce','redesign','seo','custom','other') | |
| current_price | DECIMAL NULL | prix pratiqué HT (source unique du « prix actuel ») |
| annual_volume | SMALLINT UNSIGNED NULL | nombre vendu par an (facultatif) |
| is_active | TINYINT(1) | |
| position | SMALLINT | |

**pricing_simulations** : `organization_id`, `service_id` (FK NULL, `ON DELETE SET NULL`), `name`, `reference_scenario_id` (FK NULL vers pricing_scenarios, `SET NULL`), `notes` TEXT NULL. INDEX (organization_id, service_id).

**pricing_scenarios**
| Colonne | Type | Notes |
|---|---|---|
| simulation_id | FK cascade | |
| organization_id | FK | redondant volontairement pour un filtrage direct et sûr |
| label | VARCHAR(60) | « Scénario A » |
| hourly_cost_mode | ENUM('profile','custom') | `profile` = suit le profil (recalcul automatique) |
| hourly_cost_custom | DECIMAL NULL | si `custom` |
| contingency_rate | DECIMAL(6,4) | provision pour aléas, défaut 0 |
| target_margin_rate | DECIMAL(6,4) | marge cible **sur prix de vente** |
| proposed_price | DECIMAL NULL | prix testé dans ce scénario (facultatif) |
| position | TINYINT | au plus 3 scénarios par simulation (contrôlé par le service) |

**pricing_scenario_lines**
| Colonne | Type | Notes |
|---|---|---|
| scenario_id | FK cascade | |
| kind | ENUM('time','direct_cost') | |
| label | VARCHAR(120) | « Design », « Licence thème » |
| hours | DECIMAL NULL | pour `time` |
| amount | DECIMAL NULL | pour `direct_cost` |
| position | SMALLINT | |

Les résultats (coût de revient, prix recommandé…) **ne sont pas stockés** (voir principe 2).

### 5.4 Revenus récurrents

**recurring_offers**
| Colonne | Type | Notes |
|---|---|---|
| organization_id | FK | |
| name | VARCHAR(100) | |
| kind | ENUM('maintenance','seo','hosting','other') | |
| billing_period | ENUM('monthly','annual') | |
| price | DECIMAL | prix HT par période de facturation |
| hours_per_month | DECIMAL | temps estimé par contrat et par mois |
| external_costs_per_month | DECIMAL | coûts externes par contrat (licences, sauvegardes…) |
| active_contracts | SMALLINT UNSIGNED | **contrats réellement signés** (base du MRR contractualisé) |
| contracts_updated_at | DATETIME NULL | |
| is_active | TINYINT(1) | |

**recurring_offer_items** : `offer_id` (FK cascade), `label`, `position`. Ce sont les prestations incluses.

**recurring_scenarios** : `organization_id`, `name`, `horizon_months` TINYINT (6 ou 12, défaut 12).

**recurring_scenario_offers** : clé primaire (`scenario_id`, `offer_id`), `starting_clients` DECIMAL(8,2), `new_clients_per_month` DECIMAL(6,2), `monthly_churn_rate` DECIMAL(6,4).

### 5.5 Diagnostic

Le **questionnaire est défini dans le code** (`Domain/Diagnostic/questionnaire_v1.php`), versionné dans Git et non stocké en base : il est déterministe, relu et testé. La base ne conserve que les codes.

**diagnostics** : `organization_id`, `questionnaire_version` VARCHAR(10), `status` ENUM('draft','completed'), `started_by` (FK users, `SET NULL`), `completed_at`. INDEX (organization_id, status, completed_at).

**diagnostic_answers** : clé primaire (`diagnostic_id`, `question_code`), `option_code` VARCHAR(40), `prefilled_from_profile` TINYINT(1), `answered_at`.

**diagnostic_scores** (écrits **une seule fois** à la clôture, immuables) : clé primaire (`diagnostic_id`, `dimension_code`), `score` DECIMAL(5,2), `applicable_count`, `answered_count`. La ligne `dimension_code = 'global'` porte le score global.

### 5.6 Stratégie

**recommendations**
| Colonne | Type | Notes |
|---|---|---|
| organization_id | FK | |
| rule_code | VARCHAR(50) | ex. `PRICE_UNDER` |
| subject_key | VARCHAR(80) | ex. `service:12` ou `org` ; UNIQUE (organization_id, rule_code, subject_key) |
| provider | VARCHAR(20) | `rules` (plus tard `ai`) |
| rule_version | SMALLINT | |
| status | ENUM('new','accepted','dismissed','snoozed','auto_resolved','done') | |
| severity | ENUM('info','medium','high','critical') | |
| priority_score | TINYINT UNSIGNED | 0 à 100, calcul déterministe |
| title | VARCHAR(160) | |
| explanation | TEXT | |
| evidence | JSON | données déclenchantes (valeurs, seuils, sources) |
| impact_kind | ENUM('qualitative','quantified') | |
| impact_amount | DECIMAL NULL | uniquement si calculable |
| impact_text | VARCHAR(255) | |
| difficulty | ENUM('low','medium','high') | |
| modules | JSON | ex. `["pricing","strategy"]` |
| suggested_actions | JSON | liste des actions concrètes |
| snoozed_until | DATE NULL | |
| dismissed_severity | ENUM NULL | sévérité au moment où la recommandation a été ignorée (pour savoir si elle doit réapparaître) |
| first_detected_at, last_evaluated_at, resolved_at | DATETIME | |

**action_plans** : `organization_id`, `name`. Un seul plan par défaut au MVP ; la table prépare plusieurs plans.

**action_items**
| Colonne | Type | Notes |
|---|---|---|
| organization_id, plan_id | FK | |
| recommendation_id | FK NULL (`SET NULL`) | NULL pour une action libre |
| title | VARCHAR(160) | |
| description | TEXT NULL | |
| status | ENUM('todo','in_progress','done') | |
| due_date | DATE NULL | |
| completed_at | DATETIME NULL | |
| position | SMALLINT | |

**action_item_tasks** : `action_item_id` (FK cascade), `label`, `is_done`, `position`. Ce sont les sous-actions cochables, copiées depuis `suggested_actions` au moment de l'acceptation.

### 5.7 Historique et technique

**kpi_snapshots** : `organization_id`, `snapshot_date` DATE, UNIQUE (organization_id, snapshot_date). Colonnes : `maturity_score`, `revenue_annualized`, `total_costs_annualized`, `estimated_result`, `mrr_contracted`, `recurring_share_declared`, `hourly_cost`, `action_progress`, `calc_version`.

**rate_limit_hits** : `bucket` VARCHAR(30) (`login_email_ip`, `login_ip`, `demo_ip`), `key_hash` BINARY(32) (SHA-256 de la clé, sans email en clair), `hit_at`. INDEX (bucket, key_hash, hit_at). Purge des lignes de plus de 24 h.

**password_resets** (S) : `user_id`, `token_hash` BINARY(32), `expires_at`, `used_at`.

**schema_migrations** : `version` VARCHAR(50) PK, `applied_at`.

### 5.8 Diagramme des relations (simplifié)

```
users ─┬─< organization_members >─┬─ organizations ─┬─ organization_settings (1-1)
       │                          │                 ├─ financial_profiles (1-1)
       │                          │                 ├─< services ─< pricing_simulations ─< pricing_scenarios ─< pricing_scenario_lines
       │                          │                 ├─< recurring_offers ─< recurring_offer_items
       │                          │                 ├─< recurring_scenarios ─< recurring_scenario_offers >─ recurring_offers
       │                          │                 ├─< diagnostics ─< diagnostic_answers / diagnostic_scores
       │                          │                 ├─< recommendations ─< action_items ─< action_item_tasks
       │                          │                 ├─< action_plans ─< action_items
       │                          │                 └─< kpi_snapshots
       └─< password_resets
```

---

## 6. API REST (`/api/v1`)

**Conventions**
- JSON uniquement. Succès : `{"data": …, "meta": {…}}`. Erreur : `{"error": {"code": "validation_failed", "message": "…", "fields": {"price": "Doit être positif."}}}`.
- Codes HTTP : 200, 201, 204 ; 401 (non connecté) ; 403 (CSRF ou droit) ; 404 (absent ou d'une autre organisation) ; 409 (conflit, par exemple 3 scénarios atteints) ; 422 (validation) ; 429 (limitation) ; 500.
- Les montants sont envoyés en nombres JSON arrondis à 2 décimales, les taux en fractions à 4 décimales.
- Chaque valeur dérivée est accompagnée de sa provenance : `{"value": 67.01, "status": "calculated", "assumptions": ["billable_rate"]}`.
- L'organisation n'apparaît **jamais dans l'URL** : elle vient de la session. Cela prépare le multi-organisation sans exposer d'identifiant.

| Domaine | Méthode et route | Rôle |
|---|---|---|
| **Auth** | POST `/auth/register` | crée l'utilisateur, l'organisation, le rôle owner et les réglages, puis ouvre la session |
| | POST `/auth/login` · POST `/auth/logout` · GET `/auth/me` | |
| | POST `/auth/password/forgot` · POST `/auth/password/reset` | (S) |
| **Démo** | POST `/demo` | crée une organisation de démo et ouvre la session (limité à 5 par IP et par heure) |
| | POST `/demo/reset` | réinitialise les données de la démo en cours |
| **Organisation** | GET · PATCH `/organization` | identité, activité, équipe, statut |
| | GET · PATCH `/organization/settings` | étape d'onboarding, arrondi |
| **Finances** | GET · PUT `/financial-profile` | données déclarées |
| | GET `/financial-profile/derived` | période et durée, valeurs annualisées, coût horaire, taux de charges variables générales, total des charges, résultat estimé (inclus et exclus), complétude |
| | POST `/financial-profile/recurring-share/accept-computed` | mise à jour **explicite** de la part déclarée avec la valeur calculée |
| **Catalogue** | GET · POST `/services` · GET · PATCH · DELETE `/services/{id}` | |
| **Diagnostic** | GET `/diagnostic/questionnaire` | définition (dimensions, questions, options, conditions) |
| | GET `/diagnostics` · POST `/diagnostics` | historique ; démarrer (ou renvoyer le brouillon en cours) |
| | GET `/diagnostics/{id}` | réponses, progression, questions applicables |
| | PUT `/diagnostics/{id}/answers/{questionCode}` | sauvegarde automatique d'une réponse |
| | POST `/diagnostics/{id}/complete` | contrôle de complétude, calcul et figement des scores, événement |
| | GET `/diagnostics/{id}/results` | scores, forces et axes, comparaison avec le précédent, recommandations liées |
| **Pricing** | POST `/pricing/calculate` | aperçu sans état |
| | GET · POST `/pricing/simulations` · GET · PATCH · DELETE `/pricing/simulations/{id}` | |
| | POST `/pricing/simulations/{id}/scenarios` | (au plus 3) |
| | PUT · DELETE `/pricing/scenarios/{id}` · POST `/pricing/scenarios/{id}/duplicate` | |
| | GET `/pricing/templates/{category}` | modèle de temps indicatif (facultatif) |
| **Récurrent** | POST `/recurring/calculate` | aperçu d'une formule ou d'une projection, sans état |
| | GET · POST `/recurring/offers` · GET · PATCH · DELETE `/recurring/offers/{id}` | |
| | PATCH `/recurring/offers/{id}/contracts` | nombre de contrats signés |
| | GET `/recurring/summary` | MRR et ARR contractualisés, répartition, couverture comparée à la part déclarée, capacité |
| | GET · POST `/recurring/scenarios` · GET · PUT · DELETE `/recurring/scenarios/{id}` | |
| | GET `/recurring/scenarios/{id}/projection` | séries mensuelles |
| **Stratégie** | GET `/recommendations?status=` | |
| | POST `/recommendations/evaluate` | réévaluation manuelle |
| | POST `/recommendations/{id}/accept` · `/dismiss` · `/snooze` · `/reopen` | |
| | GET `/action-plan` | actions, sous-actions, progression |
| | POST `/action-items` · PATCH · DELETE `/action-items/{id}` | |
| | POST `/action-items/{id}/tasks` · PATCH · DELETE `/action-tasks/{id}` | |
| **Dashboard** | GET `/dashboard` | agrégat en un appel (voir §7) |
| | GET `/dashboard/history?metrics=…&months=12` | séries issues des instantanés |
| **Compte** | PATCH `/account` · PUT `/account/password` · DELETE `/account` | la suppression exige le mot de passe |
| | GET `/account/export` | (S) export JSON de toutes les données |

---

## 7. Services métier

### 7.1 Domaine (calculs purs, sans base de données ni HTTP)

| Classe | Responsabilité |
|---|---|
| `ReferencePeriod` | type, dates, durée en mois, facteur d'annualisation, libellé (« Exercice clos du 01/01/2025 au 31/12/2025 · 12 mois ») |
| `FinancialCalculator` | annualisation, complément de rémunération du dirigeant, total des charges, résultat estimé (avec le détail inclus/exclus), heures facturables, coût horaire complet, taux de charges variables générales, couverture récurrente, complétude |
| `PricingCalculator` | heures avec aléas, coût de revient, prix plancher, prix recommandé, résultat, marge sur prix de vente, majoration sur coût, écart, manque à gagner annuel |
| `RecurringCalculator` | prix mensuel normalisé, rentabilité d'une formule, MRR et ARR, projection mensuelle, besoin en heures comparé à la capacité |
| `DiagnosticScorer` | questions applicables (conditions), scores par dimension et global, forces et axes, niveaux |
| `Strategy\Facts` | objet en lecture seule regroupant tous les indicateurs utiles aux règles (construit par `FactsBuilder`) |
| `Strategy\Rule` (interface) | `code()`, `version()`, `evaluate(Facts): RecommendationDraft[]` |
| `Strategy\RecommendationProvider` (interface) | `RulesProvider` au MVP. Un futur `AiEnrichmentProvider` décorera les brouillons (explication enrichie) sans toucher aux règles ni au modèle de données |

Les résultats sont des objets typés (`PricingResult`, etc.). Les erreurs métier lèvent des exceptions dédiées, par exemple `CalculationNotPossible` avec un code `missing_hourly_cost` ou `margin_plus_variable_rate_too_high`. L'API les traduit en messages pédagogiques.

### 7.2 Application (cas d'usage)

| Service | Rôle |
|---|---|
| `AuthService` | inscription (transaction), connexion et limitation des tentatives, déconnexion, mots de passe |
| `OrganizationService` | identité, réglages, onboarding |
| `FinancialProfileService` | lecture et écriture du profil, `derived()` qui appelle `FinancialCalculator`, mise à jour explicite de la part récurrente |
| `ServiceCatalogService` | gestion des prestations |
| `DiagnosticService` | brouillon unique, sauvegarde des réponses, préremplissage depuis le profil, clôture et figement des scores |
| `PricingService` | simulations et scénarios (au plus 3), assemblage des données et appel de `PricingCalculator`, modèles de temps |
| `RecurringService` | offres, contrats, scénarios, synthèse, projection |
| `FactsBuilder` | une seule lecture de chaque source pour construire `Facts` |
| `RecommendationService` | évalue les règles, puis **réconcilie** avec la base (voir §7.3) |
| `ActionPlanService` | acceptation (création de l'action et copie des sous-actions), statuts, progression |
| `DashboardService` | assemble `GET /dashboard` à partir des autres services, sans formule propre |
| `SnapshotService` | instantané quotidien |
| `DemoService` | création, réinitialisation, purge |
| `AccountService` | profil, mot de passe, suppression, export |

### 7.3 Réconciliation des recommandations

Pour chaque brouillon `(rule_code, subject_key)` produit par les règles :

| Situation en base | Action |
|---|---|
| absente | création, statut `new` |
| `new`, `accepted` ou `snoozed` | mise à jour de l'explication, des preuves, de la sévérité et de la priorité (le statut est conservé ; `snoozed` repasse en `new` à l'échéance) |
| `dismissed` | ignorée, **sauf si la sévérité a augmenté** depuis : retour à `new` |
| `auto_resolved` ou `done` | retour à `new` (le problème est revenu) avec la mention « réapparue » |

Pour une recommandation active en base **sans brouillon correspondant** (la condition n'est plus vraie) : passage en `auto_resolved`. Une action du plan liée reste en place ; elle est marquée « objectif atteint » et peut être clôturée en un clic.

### 7.4 Contenu de `GET /dashboard`

```
period            { type, start, end, months, label }
maturity          { score, level, completed_at, delta_vs_previous } | null
revenue           { declared, annualized, status }
costs             { total_annualized, breakdown{fixed, personnel, owner_complement, variable_attributable, variable_general}, status }
estimated_result  { amount, rate_on_revenue, includes[], excludes[], status, missing[] }
recurring         { mrr_contracted, arr_contracted, share_declared, share_from_contracts, gap_points, by_offer[] }
target            { amount, year, gap_amount, gap_rate }
hourly_cost       { value, mode: calculated|override, assumptions[] }
recommendations   { top: [3 maximum], counts_by_status }
action_plan       { progress, done, total, overdue, next_due[] }
completeness      { rate, missing_fields[] }
onboarding        { checklist: [...] }
is_demo, demo_expires_at
```

Si une donnée manque, la valeur vaut `null` et `missing[]` indique les champs à compléter. Le front affiche alors un état vide pédagogique, jamais un 0.

---

## 8. Front-end (structure, sans maquette)

- **`core/api.js`** : enveloppe de `fetch` qui ajoute le jeton CSRF, analyse le JSON, normalise les erreurs (`ApiError` avec `fields`) et redirige vers la connexion en cas de 401. Elle gère aussi `AbortController` et le délai d'attente.
- **`core/format.js`** : `Intl.NumberFormat('fr-FR')` pour les montants, les pourcentages, les heures et les dates. C'est **uniquement du formatage**, aucun calcul.
- **`ui/*`** : composants sans framework (fonctions qui créent et renvoient des nœuds DOM, avec des classes CSS du design system). Le détail viendra en phase 3.
- **`pages/*.js`** : un module par écran. Il lit les données initiales (`<script type="application/json" id="initial-data">`), branche les événements et appelle l'API.
- **`charts/*`** : enveloppes de Chart.js avec le thème du design system (couleurs, police, infobulles en fr-FR) : radar, barres empilées, courbes.
- **États** : chaque composant de données a quatre états : chargement (squelette), vide (explication et action), erreur (message et réessai), contenu.

---

## 9. Démonstration : isolation, expiration, suppression

| Exigence | Mise en œuvre |
|---|---|
| Isolation | Chaque visiteur reçoit sa **propre** organisation (`is_demo=1`) et un utilisateur `is_demo` sans mot de passe. Les règles d'isolation sont les mêmes que pour un compte réel |
| Données cohérentes | `database/seeds/demo.php` décrit « Agence Lumen » (4 personnes, exercice clos 2025, 6 prestations, 3 formules de maintenance, un diagnostic terminé, des recommandations générées par le **vrai** moteur de règles, quelques instantanés antérieurs). Les instantanés antérieurs sont les seules données « historiques » fabriquées ; elles sont marquées démo |
| Expiration visible | `demo_expires_at = création + 24 h`. Bandeau permanent : « Mode démonstration — données fictives — expire dans 23 h 12 » avec les boutons « Réinitialiser » et « Créer mon compte » |
| Accès après expiration | Le middleware refuse l'accès, ferme la session et redirige vers l'accueil avec un message |
| Suppression fiable | 1) cron Hostinger toutes les heures : `php bin/purge-demo.php` ; 2) **purge de secours** à chaque création de démo (au plus 20 organisations expirées), au cas où le cron ne tourne pas ; 3) suppression par **transaction** : organisations expirées (cascade sur toutes les tables), puis utilisateurs démo devenus orphelins ; 4) journalisation du nombre supprimé ; 5) test fonctionnel qui vérifie qu'il ne reste **aucune ligne** pour une organisation purgée, table par table |
| Abus | 5 démos par IP et par heure ; au plus 500 organisations de démo actives (au-delà, message « démo momentanément indisponible ») |

---

## 10. Tests

| Type | Outil | Périmètre |
|---|---|---|
| Unitaires | PHPUnit (dév. uniquement) | Tous les calculateurs du domaine, `DiagnosticScorer`, chaque règle, `ReferencePeriod`, `Validator`. Les cas de référence chiffrés de [03-formules-et-scoring.md](03-formules-et-scoring.md) sont stockés dans `tests/fixtures/*.json` et rejoués automatiquement |
| Fonctionnels | PHPUnit et `Kernel` en mémoire, base MySQL de test | Parcours inscription → onboarding → diagnostic → simulation → offre → recommandation → plan → dashboard ; **isolation** (une organisation B ne peut ni lire ni modifier A, sur chaque endpoint) ; CSRF ; limitation des tentatives ; purge de la démo |
| Sécurité | Checklist manuelle et tests ciblés | En-têtes, cookies, XSS (données piégées affichées échappées), injection (paramètres de tri), 404 sur les identifiants étrangers |
| Responsive | Checklist manuelle | 360, 768, 1024 et 1440 px |

---

## 11. Déploiement Hostinger (aperçu)

1. Créer la base MySQL et son utilisateur dans hPanel. Version PHP du site : 8.3.
2. Copier le dépôt dans `domains/agency-os.azzurra-pro.com/`. Seul `public_html/` est servi (le sous-domaine est déjà créé).
3. Créer `config/.env` à partir de `config/.env.example` (identifiants, `APP_KEY`, `APP_URL`, `APP_ENV=production`).
4. Appliquer les migrations : `php bin/migrate.php` en SSH, ou import manuel des fichiers SQL dans l'ordre via phpMyAdmin si SSH n'est pas disponible.
5. Créer la tâche cron horaire `php …/bin/purge-demo.php`.
6. Activer le SSL (Let's Encrypt) puis HSTS.

Les instructions détaillées seront fournies en phase 5.

---

## 12. Décisions à valider

1. **Aperçus instantanés calculés par le serveur**, via des endpoints sans état appelés au fil de la saisie, et aucune formule en JavaScript.
2. **Aucune valeur dérivée stockée**, sauf les scores de diagnostic figés et les instantanés.
3. **Questionnaire défini dans le code** (versionné), réponses en base.
4. **Un profil financier par organisation** au MVP, l'historique passant par les instantanés quotidiens.
5. **Traitement des charges variables générales** comme un taux sur le CA intégré au prix (détails dans le document 03).
6. **Calculs en nombres flottants (double) avec arrondi au centime uniquement en sortie**, et stockage en `DECIMAL`. Justification : les montants restent inférieurs à 10⁸ €, l'erreur relative d'un double (≈ 10⁻¹⁶) est négligeable, et les cas de référence sont testés avec une tolérance de 0,005 €. L'alternative (`bcmath`) n'apporte rien à ce niveau de précision et dépend d'une extension.
7. **« Je ne sais pas » vaut 0 point** dans le diagnostic (voir 03 §5). Le manque de visibilité est un signal de maturité ; la règle est documentée dans la méthodologie.
8. **Dispatcher d'événements synchrone**, sans file d'attente.
