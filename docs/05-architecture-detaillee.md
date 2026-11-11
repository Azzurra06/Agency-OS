# Agency OS — Architecture détaillée

> Statut : **proposition, en attente de validation**. Aucun développement avant validation.
> Ce document complète [02-architecture.md](02-architecture.md), [03-formules-et-scoring.md](03-formules-et-scoring.md) et [04-securite.md](04-securite.md). Il intègre les décisions de [01-cadrage.md §12](01-cadrage.md).
> Les éléments qui dépendent d'un arbitrage de sécurité encore ouvert sont marqués **[D…]**.

---

## 1. Nature des données : un modèle commun à tous les modules

### 1.1 Les natures

| Nature | Code API | Définition | Exemples | Badge (texte et icône, pas seulement une couleur) |
|---|---|---|---|---|
| **Déclaré** | `declared` | Fait réel saisi par l'utilisateur | CA de l'exercice 2025, charges, nombre de contrats signés, prix actuel d'une prestation | « Déclaré », icône crayon |
| **Hypothèse** | `assumption` | Valeur supposée : valeur par défaut acceptée, estimation ou paramètre de scénario | taux de facturabilité de 65 %, heures estimées d'un projet, churn de 3 % par mois, marge cible | « Hypothèse », icône ampoule |
| **Simulé** | `simulated` | Résultat prospectif issu d'hypothèses | MRR projeté à 12 mois, prix recommandé d'un brouillon | « Simulé », icône courbe en pointillés |
| **Calculé** | `calculated` | Résultat déterministe calculé à partir de données réelles (et éventuellement d'hypothèses, qui sont alors listées) | coût horaire, résultat estimé, MRR contractualisé, score de dimension | « Calculé » ou « Calculé avec hypothèses », icône fonction |
| **Figé** | `frozen` | Version historique validée, immuable, avec sa date et sa version | simulation finalisée v3, scénario finalisé, diagnostic terminé, instantané du 12/11 | « Figé le 12/11/2026 · v3 », icône cadenas |
| **Démo** | `demo` | Donnée fictive de l'espace de démonstration (se superpose aux autres natures) | tout le compte démo | bandeau global, et « Démo » en filigrane sur les exports et captures |

### 1.2 Règles de combinaison

1. **La nature d'un résultat est la plus incertaine de ses entrées.** Un calcul qui utilise une hypothèse est « Calculé avec hypothèses » et liste chaque hypothèse. Un calcul qui utilise un paramètre de scénario prospectif est « Simulé ».
2. **On n'additionne jamais des natures différentes** dans un même total : MRR réel et MRR simulé, contrats signés et clients projetés, CA déclaré et CA projeté.
3. **Un objet figé conserve la nature d'origine de chaque valeur** au moment du gel (par exemple « coût horaire *calculé avec hypothèses*, figé le 12/11 »). La nature `frozen` s'ajoute, sans remplacer l'origine.
4. **Graphiques** :
   - trait plein pour le réel et le calculé ;
   - **pointillés** pour le simulé ;
   - marqueurs creux pour le figé ;
   - **trous** pour les jours manquants ;
   - légende explicite dans tous les cas, et texte alternatif reprenant ces distinctions.

### 1.3 Format dans l'API

Chaque valeur métier exposée par un endpoint de lecture suit le même format :

```json
{
  "value": "67.01",
  "unit": "EUR/h",
  "nature": "calculated",
  "assumptions": ["billable_rate", "annual_hours_per_fte"],
  "as_of": "2026-11-10",
  "source": { "type": "financial_profile", "period": "2025-01-01/2025-12-31", "months": 12 },
  "frozen": null,
  "missing": []
}
```

- Pour une valeur figée, `"frozen": {"at": "2026-11-12T09:41:00Z", "version": 3, "calc_version": "1.0"}`.
- Pour une valeur non calculable : `"value": null` et `"missing": ["production_fte"]`.
- Les listes volumineuses (séries de graphiques) portent la nature **au niveau de la série**, et non de chaque point.
- Le front a **un seul** composant d'affichage de badge (`ui/nature-badge`) et **un seul** formateur de valeur. Aucune page ne choisit librement comment présenter la nature d'une donnée.

### 1.4 Nature par module

| Module | Déclaré | Hypothèse | Calculé | Simulé | Figé |
|---|---|---|---|---|---|
| Profil | montants, période, ETP, objectif, part récurrente déclarée | heures par ETP et taux de facturabilité par défaut, coût horaire forcé | coût horaire, annualisation, résultat estimé, `tv`, complétude | — | journal des modifications (valeurs passées) |
| Diagnostic | réponses | — | scores, fiabilité (diagnostic en cours : aperçu) | — | diagnostic terminé (complet ou partiel) |
| Pricing | prix actuel et volume du catalogue | heures, frais directs du projet, aléas, marge cible, prix proposé | — | résultats d'un **brouillon** | simulation **finalisée** (active, remplacée ou archivée) |
| Récurrent | offres, prix, contrats signés | temps et coûts externes par contrat ; départ, acquisition et churn d'un scénario | MRR et ARR **contractualisés**, rentabilité par formule, couverture | projection d'un **brouillon** | scénario **finalisé** |
| Stratégie | statut, échéance et sous-actions saisis | — | recommandations (avec leurs sources) | — | `evidence` d'une recommandation à sa dernière évaluation |
| Dashboard | — | — | KPI courants | — | instantanés (historique) |

---

## 2. Traçabilité, fraîcheur et complétude des recommandations

### 2.1 Les sources d'une recommandation

Chaque recommandation stocke dans `evidence` la liste des **sources** utilisées lors de sa dernière évaluation :

```json
{
  "sources": [
    { "type": "financial_profile", "nature": "declared", "as_of": "2026-11-10",
      "period": "2025-01-01/2025-12-31", "freshness": "fresh", "completeness": "complete" },
    { "type": "pricing_simulation", "ref": "01JC9…", "label": "Site vitrine", "version": 3,
      "nature": "frozen", "as_of": "2026-11-12", "freshness": "fresh", "drift": false },
    { "type": "diagnostic", "ref": "01JC2…", "nature": "frozen", "as_of": "2026-10-02",
      "status": "partial", "dimensions_used": ["profitability"] }
  ],
  "values": { "price_gap_rate": "-19.4074", "recommended_price": "3970.59", "current_price": "3200.00" },
  "thresholds": { "price_gap_medium": "-10.00", "price_gap_high": "-20.00" },
  "data_quality": "partial"
}
```

- `values` contient les valeurs **exactes** qui ont déclenché la règle ; `thresholds` les seuils appliqués.
- `evidence` est réservé aux données financières de l'organisation elle-même : ce champ suit donc les règles de chiffrement des champs sensibles **[D4]**.

### 2.2 Qualité des données : règles déterministes

| Indicateur | Condition | Effet |
|---|---|---|
| **Incomplet** (`partial`) | un champ utile à la règle est absent, mais la règle reste évaluable sans lui (par exemple, volume annuel absent : impact non chiffré) ; ou la règle s'appuie sur un **diagnostic partiel** | la recommandation est produite avec la mention « Données incomplètes : … » et la liste des champs manquants |
| **Non évaluable** | une donnée **indispensable** à la règle manque | **aucune recommandation** n'est créée à partir de données absentes. La règle de complétude correspondante (`NO_HOURLY_COST`, `PROFILE_INCOMPLETE`…) prend le relais |
| **Ancien** (`stale`) | période de référence terminée depuis plus de **15 mois** ; profil financier non modifié depuis plus de **12 mois** ; diagnostic terminé depuis plus de **12 mois** ; simulation active finalisée depuis plus de **12 mois** ou **en dérive** (coût horaire ou `tv` actuels à plus de 5 % des valeurs figées) ; contrats non mis à jour depuis plus de **6 mois** | la recommandation est produite avec la mention « Repose sur des données anciennes : … » et un lien vers la mise à jour |
| **Démo** | organisation de démonstration | mention « Démo » |

`data_quality` prend l'une des valeurs `ok`, `partial`, `stale` ou `partial_stale`. **La priorité n'est jamais augmentée par une donnée ancienne ou partielle.** Le bonus de dimension (03 §7.1) ne s'applique qu'à une dimension **calculable**.

### 2.3 Présentation

Sous le titre de chaque recommandation, une ligne « **Repose sur** » liste ses sources, par exemple : *Profil financier, exercice 2025 (déclaré) · Simulation « Site vitrine » v3, figée le 12/11/2026 · Diagnostic du 02/10/2026 (partiel)*. Si nécessaire, un encadré d'avertissement indique les données incomplètes ou anciennes. Un lien « Voir le détail du calcul » affiche `values` et `thresholds`.

---

## 3. Cycles de vie

### 3.1 Simulation de prix

```
              finaliser                        nouvelle version / autre simulation finalisée pour la même prestation
 [draft] ───────────────────► [finalized] ─────────────────────────────────────► [superseded]
    ▲  │ dupliquer              │  (active pour sa prestation)
    │  ▼                        │ archiver
 [draft]                        ▼
                           [archived]
```

| Transition | Conditions | Effets |
|---|---|---|
| créer, dupliquer | — | brouillon recalculé à chaque lecture ; aucune règle déclenchée |
| **finaliser** | brouillon ; 1 à 3 scénarios valides ; scénario de référence désigné ; coût horaire calculable | une transaction : calcul, gel (`frozen_context`, `frozen_results`, `calc_version`), statut `finalized`. L'éventuelle simulation `finalized` **de la même prestation** passe en `superseded` (`superseded_by`). Puis réévaluation des recommandations |
| nouvelle version | depuis `finalized`, `superseded` ou `archived` | copie en brouillon, `version_number + 1`, `parent_simulation_id` |
| archiver | `finalized` | elle n'est plus active ; les recommandations de la prestation passent en `auto_resolved` s'il ne reste aucune autre simulation active |
| supprimer | brouillon uniquement. Une version figée n'est supprimée **qu'avec le compte** | — |

### 3.2 Scénario récurrent

Même cycle (`draft`, `finalized`, `superseded`, `archived`), avec l'action **« Dupliquer pour modifier »**. Une seule différence : il n'y a pas de notion de « scénario actif par prestation ». Plusieurs scénarios finalisés peuvent coexister ; ils sont **comparables** mais **jamais additionnés** au MRR réel. Les offres et les contrats (données réelles) n'ont **pas** de cycle de vie : ils restent modifiables, et leurs changements n'affectent que les brouillons.

### 3.3 Diagnostic

```
[draft] ──clôturer──► [completed]  avec  completeness = complete | partial
                            │
                            └──« Compléter ces réponses »──► nouveau [draft] prérempli avec les réponses précédentes (même version si elle est toujours en vigueur)
```

Un seul brouillon à la fois par organisation. Un diagnostic terminé est figé ; il est référencé par les recommandations et les instantanés.

### 3.4 Recommandation

`new` → `accepted` (dans le plan) | `dismissed` | `snoozed` → (échéance) `new` ; puis `auto_resolved` lorsque la condition disparaît, ou `done` lorsque l'action est terminée. Les règles de réapparition sont décrites en 02 §7.3. Chaque évaluation met à jour `evidence`, `data_quality` et `last_evaluated_at`.

### 3.5 Compte utilisateur

`pending_verification` → (email vérifié) `active` → (demande de suppression) `deletion_scheduled` (verrouillé, annulable 7 jours) → `purged`. À tout moment, `locked` est possible (suspicion de sondage, verrouillage administratif). **[D8, D9]**

### 3.6 Organisation de démonstration

`active` (24 h) → `expired` (accès refusé) → `purged`.

---

## 4. Tâches planifiées (cron)

### 4.1 Contrainte d'hébergement et réponse

Le fuseau horaire du cron Hostinger n'est **pas garanti** : le PHP est configuré par défaut en `UTC`, et l'heure d'été et d'hiver décale l'heure locale. De plus, PHP ne peut pas lancer de commande shell.

Réponse :
- **une seule ligne cron PHP** dans hPanel, appelée **toutes les 15 minutes** : `*/15 * * * * php …/bin/cron.php` ;
- l'application décide elle-même, **en heure de Paris** (`Europe/Paris`), quelles tâches sont dues ;
- une ligne cron **séparée**, en shell, pour la sauvegarde (`bin/backup-db.sh`), puisque PHP ne peut pas lancer `mysqldump`.

### 4.2 Planning (heure de Paris)

| Tâche | Échéance | Clé d'exécution (anti-doublon) |
|---|---|---|
| `detect` (alertes de sécurité) | toutes les 15 min | `detect:2026-11-12T10:15` |
| `purge-demo` | toutes les heures | `purge-demo:2026-11-12T10` |
| **`snapshot-daily`** | **chaque jour, dès 02:15** | `snapshot:2026-11-12` |
| `daily-maintenance` (reports arrivés à échéance, actions en retard, purge des journaux et des jetons, comptes inactifs, purge des comptes à J+7) | chaque jour, dès 02:30 | `maintenance:2026-11-12` |
| `backup-db.sh` (cron shell) | chaque jour, vers 03:00 | fichier horodaté et manifeste |
| `backup-check` (vérifie la sauvegarde du jour) | chaque jour, dès 04:00 | `backup-check:2026-11-12` |

« **Dès 02:15** » signifie que la tâche s'exécute lors du premier passage du cron où l'heure de Paris est ≥ 02:15 et où la clé du jour n'a pas encore abouti. Cela couvre deux cas :
- **le dernier dimanche de mars**, 02:15 n'existe pas (on passe de 02:00 à 03:00) : l'instantané part à 03:00 ;
- **le dernier dimanche d'octobre**, 02:15 existe deux fois : la clé du jour empêche le doublon.

### 4.3 Idempotence et journalisation

**Table `cron_runs`**

| Colonne | Notes |
|---|---|
| `job` | nom de la tâche |
| `run_key` | UNIQUE avec `job` |
| `status` | `running`, `succeeded`, `failed` ou `partial` |
| `started_at`, `finished_at` | |
| `items_ok`, `items_failed` | |
| `error_ref` | référence vers le journal technique (sans données) |

- **Verrou** : `GET_LOCK('aos_cron_<job>', 0)` de MySQL. Si une exécution est déjà en cours, la nouvelle s'arrête immédiatement. Une exécution `running` depuis plus d'une heure est considérée comme `failed`.
- **Instantanés** :
  - un passage traite les organisations non démo qui n'ont **pas** d'instantané pour la date de Paris du jour ;
  - lots de 50, dans la limite de temps du script ;
  - `INSERT` simple : la contrainte UNIQUE (organisation, date) rejette tout doublon, rejet traité comme un succès ;
  - **jamais d'écrasement**.
- **Statut `partial`** : si des organisations échouent, l'exécution est `partial`. Les organisations restantes sont **reprises aux passages suivants du même jour**, jusqu'à 23:59, heure de Paris. Au-delà, le jour reste manquant pour elles.
- **Échecs** : journal technique (sans données financières), événement de sécurité `cron.failed` et alerte par email (04 §10.2). Un « battement » par passage permet de signaler un cron absent depuis plus de 26 h.

### 4.4 Jours manquants

- `GET /dashboard/history` renvoie **chaque date** de la période. Les jours sans instantané valent `{"date": "2026-11-13", "missing": true}`, sans valeur.
- Graphique : **trou** dans la courbe (pas de liaison entre les points voisins), infobulle « Aucune donnée enregistrée ce jour ». **Aucune interpolation.** Une vue tabulaire liste les jours manquants.
- Chaque instantané est libellé « État au 12/11/2026 à 02:15 » (heure réelle de capture), et non « moyenne du jour ».
- Un nouveau compte n'a pas d'historique avant le premier passage : état vide « L'historique commence demain matin ».

---

## 5. Schéma consolidé (évolutions par rapport à 02 §5)

### 5.1 Règles transverses

- **`public_id`** CHAR(26) UNIQUE, aléatoire, sur toute table exposée dans l'API **[D7]**. Les `id` BIGINT restent internes.
- **Clés étrangères composites** `(x_id, organization_id)` vers `(id, organization_id)` sur les tables enfants, avec un index UNIQUE `(id, organization_id)` sur les tables parentes (04 §4, niveau 3).
- **Colonnes chiffrées [D4]** : `VARBINARY(255)` au format `v1:` + nonce et chiffré, avec un nom suffixé `_enc` pour rendre leur nature évidente dans le code.

### 5.2 Tables nouvelles ou modifiées

| Table | Évolution |
|---|---|
| `users` | + `public_id`, `status` ENUM('pending_verification','active','locked','deletion_scheduled'), `email_verified_at`, `session_epoch` INT, `deletion_scheduled_at`, `last_active_at`, `password_changed_at` |
| `email_verifications` | **nouvelle** : `user_id`, `token_hash` BINARY(32), `expires_at`, `used_at` **[D9]** |
| `password_resets` | indispensable au MVP : `token_hash`, `expires_at` (30 min), `used_at`, `requested_ip` |
| `user_mfa_methods`, `user_recovery_codes` | **nouvelles, vides au MVP** (04 §3.6) |
| `security_events` | **nouvelle**, en insertion seule (04 §10.1) ; INDEX (event, created_at), (user_id, created_at) ; purge à 6 mois |
| `deletion_registry` | **nouvelle** : `organization_public_id`, `deleted_at`, conservée 3 mois (réapplication après une restauration) |
| `cron_runs` | **nouvelle** (§4.3) |
| `organizations` | + `public_id`, `status` ENUM('active','demo_expired') |
| `financial_profiles` | montants sensibles en `*_enc` **[D4]** ; + `updated_by` |
| `financial_profile_changes` | `old_value_enc`, `new_value_enc` **[D4]** |
| `services` | + `public_id` |
| `pricing_simulations` | + `public_id`, `status` (draft, finalized, superseded, archived), `superseded_by`, `archived_at` ; index UNIQUE **fonctionnel** pour garantir **au plus une simulation `finalized` par prestation**. MySQL ne propose pas d'index partiel : on utilise une colonne générée `is_active_key = IF(status='finalized', 1, NULL)` avec UNIQUE (service_id, is_active_key). La colonne générée ne dérive **que de `status`** : MySQL 8 interdit `ON DELETE CASCADE` sur une colonne de base d'une colonne générée stockée (constaté au lot 2 avec `diagnostics.draft_key`). Compatible MySQL 8 et MariaDB 10.x, vérifié en CI sur les deux |
| `pricing_scenarios`, `pricing_scenario_lines` | clé étrangère composite avec l'organisation |
| `recurring_offers` | + `public_id` ; `active_contracts` et `contracts_updated_at` (sert au critère « ancien ») |
| `recurring_scenarios` | + `public_id`, cycle de vie (§3.2), `frozen_context`, `frozen_results` |
| `recurring_scenario_offers` | `offer_id` NULL possible (offre supprimée : le scénario finalisé reste lisible grâce à `frozen_context`) |
| `diagnostics` | + `public_id`, `completeness` ENUM('complete','partial') NULL (renseigné à la clôture), `calc_version` |
| `diagnostic_scores` | `unknown_excluded_count`, `unknown_evaluable_count`, `reliability`, `is_scorable` |
| `recommendations` | + `public_id`, `data_quality` ENUM('ok','partial','stale','partial_stale'), `evidence` (structure de §2.1) |
| `kpi_snapshots` | colonnes monétaires en `*_enc` **[D4]** ; `captured_at` (heure réelle de capture) |
| `action_items`, `action_item_tasks` | + `public_id` |

### 5.3 Ordre des migrations

1. `0001_identity` : utilisateurs, organisations, adhésions, réglages, vérification de l'email, réinitialisation, MFA
2. `0002_security` : événements de sécurité, limitation des tentatives, registre des suppressions, exécutions du cron
3. `0003_finance` : profil financier, journal des modifications, prestations
4. `0004_diagnostic`
5. `0005_pricing`
6. `0006_recurring`
7. `0007_strategy` : recommandations, plans, actions, sous-actions
8. `0008_history` : instantanés

Chaque migration est numérotée, appliquée **une seule fois** (`schema_migrations`), précédée d'une sauvegarde (04 §8.4), et testée sur une base vide **et** sur une copie de la version précédente.

---

## 6. Contrats d'API détaillés (exemples de référence)

### 6.1 `POST /api/v1/pricing/calculate` (aperçu, sans enregistrement)

Requête (en-têtes `X-CSRF-Token` et `X-Request-Seq: 42`) :

```json
{
  "hourly_cost": { "mode": "profile" },
  "contingency_pct": "0.00",
  "target_margin_pct": "30.00",
  "proposed_price": "3200.00",
  "lines": [
    { "kind": "time", "label": "Développement", "hours": "40.00" },
    { "kind": "direct_cost", "label": "Thème premium", "amount": "300.00" }
  ]
}
```

Réponse `200` (en-tête `X-Request-Seq: 42`) :

```json
{
  "data": {
    "nature": "simulated",
    "inputs": {
      "hourly_cost": { "value": "60.00", "nature": "calculated", "assumptions": ["billable_rate"] },
      "general_variable_rate_pct": { "value": "2.00", "nature": "calculated", "source": { "period": "2025-01-01/2025-12-31" } }
    },
    "results": {
      "base_cost": "2700.00", "floor_price": "2755.10", "recommended_price": "3970.59",
      "at_price": { "price": "3200.00", "result": "436.00", "full_cost": "2764.00",
                    "margin_on_price_pct": "13.63", "markup_on_cost_pct": "15.77", "gap_to_recommended_pct": "-19.41" }
    },
    "alerts": [ { "code": "price_below_recommended", "level": "warning",
                  "message": "Le prix testé est 19,4 % sous le prix recommandé." } ],
    "affects_strategy": false
  }
}
```

`affects_strategy: false` rappelle à l'interface qu'un brouillon n'influence pas la stratégie globale.

### 6.2 `GET /api/v1/dashboard/history?metrics=estimated_result,mrr_contracted&days=30`

```json
{
  "data": {
    "series": [
      { "metric": "mrr_contracted", "nature": "frozen", "unit": "EUR",
        "points": [ { "date": "2026-11-12", "value": "2300.00", "captured_at": "2026-11-12T01:15:04Z" },
                    { "date": "2026-11-13", "missing": true },
                    { "date": "2026-11-14", "value": "2450.00", "captured_at": "2026-11-14T01:15:02Z" } ] }
    ],
    "missing_days": ["2026-11-13"]
  }
}
```

### 6.3 Catalogue des codes d'erreur (extrait)

| HTTP | Code | Signification |
|---|---|---|
| 401 | `unauthenticated` | session absente ou expirée |
| 403 | `csrf_invalid` · `forbidden` · `demo_restricted` · `reauth_required` | |
| 404 | `not_found` | absent **ou** appartenant à une autre organisation (réponse identique) |
| 409 | `frozen_record` | modification d'une version figée : « Dupliquez ou créez une nouvelle version » |
| 409 | `scenario_limit` · `draft_exists` | |
| 413 | `payload_too_large` | |
| 422 | `validation_failed` (avec `fields`) · `unknown_field` · `decimal_must_be_string` · `calculation_not_possible` (avec `reason` : `missing_hourly_cost`, `no_billable_hours`, `margin_plus_variable_rate_too_high`…) | |
| 429 | `rate_limited` (avec `Retry-After`) | |
| 500 | `internal_error` (avec `error_ref`) | |

---

## 7. Points en attente avant le développement

1. Arbitrages de sécurité **D1 à D11** ([04 §13](04-securite.md)). Leurs effets sont déjà localisés dans ce document par les marques **[D…]**.
2. Vérifications à faire dans hPanel ([04 §1.2](04-securite.md)) : sauvegardes, SSH, privilèges MySQL, centre de données, version de MySQL ou MariaDB (pour la colonne générée de §5.2 et les contraintes `CHECK`), email.
3. Seuils de fraîcheur de §2.2 (15, 12 et 6 mois, dérive de 5 %) : à confirmer.

Après validation : **phase 3, UX/UI** (design system, composants dont le badge de nature et l'encadré « Repose sur », écrans et états), puis le développement, en commençant par le socle de sécurité.
