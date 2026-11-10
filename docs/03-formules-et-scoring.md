# Agency OS — Formules financières, scoring et règles

> Statut : **proposition, en attente de validation** (livrable de la phase 2)
> Ce document est la **spécification de référence** des calculs. Le code PHP (`app/Domain/*`) doit s'y conformer, et chaque cas chiffré ci-dessous deviendra un test automatisé (`tests/fixtures/*.json`).
> Tous les montants sont **HT, en euros**.

---

## 0. Conventions

| Notation | Signification |
|---|---|
| `NULL` | donnée non renseignée. Un calcul qui en dépend renvoie « non calculable » et la liste des champs manquants, **jamais 0** |
| Arrondi | précision double pendant le calcul, arrondi **au centime** (arrondi commercial, la moitié vers le haut) uniquement en sortie. Taux : 4 décimales en API, 1 décimale à l'affichage |
| Taux | stockés en fraction (0,30 = 30 %) |
| Statuts | Déclaré · Calculé · Calculé avec hypothèses · Simulé · Démo |

---

## 1. Période de référence

| Symbole | Champ | Règle |
|---|---|---|
| — | `period_type` | `closed_fiscal_year` (par défaut) ou `trailing_12_months` |
| `d₀`, `d₁` | dates de début et de fin | `d₀ < d₁` ; durée comprise entre 1 et 24 mois |
| `D` | durée en mois | nombre de mois calendaires entiers entre `d₀` et `d₁ + 1 jour`. Si ce n'est pas un nombre entier : `jours ÷ 30,4375`, arrondi à 0,1 |
| `k` | facteur d'annualisation | `k = 12 ÷ D` |

- Pour `trailing_12_months`, les dates sont proposées automatiquement (les 12 mois révolus précédant la saisie) et restent modifiables.
- **Affichage obligatoire** partout où un montant dépend de la période : « Exercice clos du 01/07/2024 au 31/12/2025 · 18 mois », ou « 12 derniers mois du 01/11/2025 au 31/10/2026 · 12 mois ».
- Si `D ≠ 12`, les montants annualisés (`X × k`) portent le badge « Calculé (annualisé) » et le montant déclaré reste affiché.

**Cas PER-1** : exercice du 01/07/2024 au 31/12/2025 → `D = 18`, `k = 0,6667`. CA déclaré 270 000 € → CA annualisé **180 000,00 €**.

---

## 2. Profil financier et résultat estimé

### 2.1 Données déclarées (sur la période)

| Symbole | Champ |
|---|---|
| `R` | CA HT |
| `CF` | charges fixes hors personnel |
| `CP` | coût employeur chargé des salariés |
| `CVa` | frais directement attribuables aux projets (total de la période) |
| `CVg` | charges variables générales non attribuables |
| `RDc` | coût complet annuel de la rémunération du dirigeant, **cotisations comprises** |
| `RDi` | montant de cette rémunération **déjà inclus** dans `CF` ou `CP` |

Validation : `0 ≤ RDi ≤ RDc` et `RDi ≤ CF + CP`.

### 2.2 Formules (valeurs annualisées)

```
Complément dirigeant      Cd = max(0, RDc − RDi)                      (RDc est déjà annuel : il n'est pas multiplié par k)
Total des charges         C  = (CF + CP + CVa + CVg) × k + Cd
Résultat estimé           Re = R × k − C
Taux de résultat          Re ÷ (R × k)
Taux de charges variables générales   tv = CVg ÷ R                  (sans dimension ; NULL si R absent ou nul)
```

> `RDc` et `RDi` sont saisis explicitement **« par an »**, donc ils ne sont pas multipliés par `k`. Les autres montants portent sur la période déclarée et sont annualisés.

### 2.3 Ce que le résultat estimé inclut et exclut (affiché à l'écran)

| Inclus | Exclus |
|---|---|
| CA HT de la période (annualisé) | TVA |
| Charges fixes déclarées (loyer, logiciels, assurances, comptable…) | Impôt sur les sociétés ou sur le revenu |
| Coût chargé des salariés | Charges et produits financiers et exceptionnels, *sauf s'ils ont été saisis dans les charges fixes* |
| Rémunération du dirigeant, cotisations comprises (sans double comptage) | Amortissements, *sauf s'ils ont été saisis dans les charges fixes* |
| Frais attribuables aux projets et charges variables générales | Variations d'encours, factures à émettre, décalages de trésorerie |

Mention permanente : « Estimation de gestion à partir de vos déclarations. Elle ne remplace ni votre bilan, ni l'avis de votre expert-comptable. »

**Cas RES-1** (D = 12) : `R` = 180 000, `CF` = 30 000, `CP` = 60 000, `CVa` = 12 000, `CVg` = 3 600, `RDc` = 50 000, `RDi` = 0.
→ `Cd` = 50 000 ; `C` = **155 600,00 €** ; `Re` = **24 400,00 €** ; taux de résultat **13,6 %** ; `tv` = **0,0200**.

---

## 3. Coût horaire complet

```
Coûts de structure à couvrir   S  = (CF + CP) × k + Cd
Heures facturables annuelles   H  = ETP_production × heures_annuelles_par_ETP × taux_facturabilité
Coût horaire complet           CH = S ÷ H                          (ou valeur forcée hourly_cost_override)
Capacité mensuelle             H ÷ 12
```

- `CVa` est **exclu** du coût horaire : ces frais sont saisis projet par projet dans le pricing.
- `CVg` est **exclu** du coût horaire : il est appliqué en proportion du prix (§4). Inclure l'un ou l'autre dans le coût horaire créerait un double comptage.
- Hypothèses par défaut (badge « Hypothèse ») : 1 607 h par ETP et par an, taux de facturabilité de 65 %. La valeur affichée devient alors « Calculé avec hypothèses : taux de facturabilité ».
- Valeur forcée : badge « Saisi manuellement », avec un rappel du calcul théorique s'il est disponible.

**Cas HC-1** : `CF` = 30 000, `CP` = 60 000, `RDc` = 50 000, `RDi` = 0, 2 ETP de production, 1 607 h, 65 %.
→ `H` = **2 089,10 h** ; `S` = 140 000 ; `CH` = **67,01 €/h** ; capacité mensuelle **174,09 h**.

**Cas HC-2** : identique, avec `RDi` = 20 000 (salaire du dirigeant déjà dans `CP`).
→ `Cd` = 30 000 ; `S` = 120 000 ; `CH` = **57,44 €/h**.

**Cas HC-3** : `ETP_production` = 0 ou taux de facturabilité = 0 → erreur `no_billable_hours`, message : « Indiquez au moins une personne qui produit des heures facturables. »

---

## 4. Pricing d'une prestation

### 4.1 Entrées d'un scénario

Lignes de temps `hᵢ` ; aléas `a` (0 par défaut) ; frais directs `fⱼ` ; coût horaire `CH` (issu du profil ou personnalisé) ; marge cible sur prix de vente `m` ; taux `tv` (issu du profil, 0 si non calculable, ce qui est **signalé**) ; prix à évaluer `P` (prix actuel du catalogue, ou prix proposé dans le scénario).

### 4.2 Formules

```
Heures totales                T   = Σ hᵢ × (1 + a)
Coût du temps                     = T × CH
Frais directs                 F   = Σ fⱼ
Coût direct et de structure   C₀  = T × CH + F
Coût de revient au prix P     CR(P) = C₀ + tv × P                  (les charges variables générales dépendent du prix)

Prix plancher                 Pp  = C₀ ÷ (1 − tv)                  → résultat nul
Prix recommandé               Pr  = C₀ ÷ (1 − m − tv)              → marge sur prix de vente = m exactement
   condition : m + tv < 1  (sinon erreur margin_plus_variable_rate_too_high)

Pour un prix P :
  Résultat                    Res(P) = P × (1 − tv) − C₀
  Marge sur prix de vente     Res(P) ÷ P                           (« taux de marque »)
  Majoration sur coût         Res(P) ÷ CR(P)                       (« coefficient de majoration − 1 »)
  Écart au prix recommandé    (P − Pr) ÷ Pr
  Manque à gagner annuel      (Pr − P) × volume_annuel             (seulement si P < Pr et volume déclaré)
```

**Libellés à l'écran.** On affiche « Marge sur prix de vente » et « Majoration sur coût », chacune avec son infobulle. On n'emploie jamais « marge » seul. Bornes : `0 ≤ m ≤ 0,90` et `0 ≤ a ≤ 1`.

L'arrondi commercial (réglage `price_rounding`) est proposé **à côté** de `Pr` (par exemple « 3 980 € arrondi »), sans remplacer la valeur exacte.

### 4.3 Cas de référence

**Cas PR-1** : `CH` = 60, 40 h, `a` = 0, `F` = 300, `tv` = 0,02, `m` = 0,30, prix actuel `P` = 3 200.

| Résultat | Valeur |
|---|---|
| `C₀` | 2 700,00 € |
| Prix plancher | **2 755,10 €** |
| Prix recommandé | **3 970,59 €** |
| Résultat à 3 200 € | 436,00 € |
| Coût de revient à 3 200 € | 2 764,00 € |
| Marge sur prix de vente | **13,6 %** (0,1363) |
| Majoration sur coût | **15,8 %** (0,1577) |
| Écart au prix recommandé | **−19,4 %** (−0,1941) |
| Manque à gagner (8 ventes par an) | **6 164,71 €** |

**Cas PR-2** : comme PR-1, avec 10 % d'aléas → `T` = 44 h ; `C₀` = 2 940,00 ; prix plancher **3 000,00 €** ; prix recommandé **4 323,53 €**.

**Cas PR-3** : `m` = 0,70 et `tv` = 0,35 → erreur `margin_plus_variable_rate_too_high`.

**Cas PR-4** (vérification de la cohérence) : au prix `P = Pr` de PR-1, la marge sur prix de vente vaut exactement 0,3000 et la majoration sur coût vaut 0,3000 ÷ (1 − 0,3000) = **0,4286**.

### 4.4 Modèles de temps indicatifs (facultatifs)

- Ils ne sont proposés que si l'utilisateur clique sur « Préremplir avec un modèle indicatif ».
- Avertissement affiché : « Ordre de grandeur pour démarrer, à adapter à votre méthode. Ce n'est pas une référence de marché. »
- Chaque ligne est modifiable et supprimable. Les modèles sont définis dans le code (`Domain/Pricing/templates.php`) et versionnés.

---

## 5. Revenus récurrents

```
Prix mensuel normalisé       pₘ = prix                (si facturation mensuelle)
                             pₘ = prix ÷ 12           (si facturation annuelle)
Coût mensuel par contrat     cₘ = hₘ × CH + eₘ + tv × pₘ
Résultat mensuel par contrat     pₘ − cₘ
Marge sur prix de vente          (pₘ − cₘ) ÷ pₘ
Prix plancher mensuel            (hₘ × CH + eₘ) ÷ (1 − tv)

MRR contractualisé           Σ pₘ × contrats_actifs
ARR contractualisé           MRR × 12
Couverture par les contrats  (ARR contractualisé) ÷ (R × k)     ← affichée À CÔTÉ de la part déclarée, ne la remplace jamais
Écart                        |couverture − part déclarée| en points ; si > 10 pts : invitation à vérifier (contrats incomplets ou part déclarée obsolète)

Projection (scénario), pour chaque formule et chaque mois t = 1…horizon :
  N₀ = clients_de_départ
  Nₜ = Nₜ₋₁ × (1 − churn) + nouveaux_par_mois
  MRRₜ = Σ_formules Nₜ × pₘ        ;  cumul sur l'horizon = Σₜ MRRₜ
  Heures nécessairesₜ = Σ Nₜ × hₘ  ; comparées à la capacité mensuelle H ÷ 12
```

- Les clients projetés restent des nombres décimaux pendant le calcul et sont **affichés arrondis** (« ≈ 20 clients »).
- MRR **contractualisé** (badge Déclaré/Calculé) et MRR **simulé** (badge Simulé) ne sont **jamais additionnés**.
- Le MRR est un indicateur normalisé, pas un encaissement : une formule annuelle est encaissée en une fois.

**Cas REC-1** : formule mensuelle à 150 €, `hₘ` = 1,5 h, `eₘ` = 10, `CH` = 60, `tv` = 0,02.
→ `cₘ` = 103,00 ; résultat **47,00 €/mois** ; marge sur prix de vente **31,3 %** ; prix plancher **102,04 €**.

**Cas REC-2** : formule annuelle à 1 200 € (soit `pₘ` = 100), mêmes paramètres → résultat **−2,00 €/mois**. La formule est déficitaire et déclenche `MAINT_UNPROFITABLE`.

**Cas REC-3** : REC-1 avec 12 contrats et REC-2 avec 5 contrats → MRR **2 300,00 €**, ARR **27 600,00 €**. Avec le CA de RES-1, couverture **15,3 %**. Si la part déclarée est de 30 %, l'écart est de 14,7 pts et un message apparaît. La part déclarée n'est pas modifiée.

**Cas REC-4** (projection) : REC-1, `N₀` = 10, 2 nouveaux par mois, churn de 3 %.

| Mois | Clients | MRR |
|---|---|---|
| 1 | 11,70 | 1 755,00 € |
| 2 | 13,35 | 2 002,35 € |
| 6 | 19,47 | 2 919,74 € |
| 12 | 27,35 | 4 102,34 € |

Cumul sur 6 mois : **14 095,14 €**. Cumul sur 12 mois : **35 857,68 €**.

**Cas REC-5** (capacité) : REC-3 → 25,5 h par mois nécessaires, pour une capacité de 174,09 h (HC-1), soit **14,6 %**.

---

## 6. Diagnostic de maturité

### 6.1 Structure

6 dimensions **à poids égaux**, 5 questions chacune (30 au total). Chaque question propose 4 réponses graduées (niveaux 0 à 3) et une réponse « Je ne sais pas » (niveau 0, voir la décision 7 du document 02).

| Dimension | Thèmes des questions (rédaction définitive en phase 4) |
|---|---|
| **1. Positionnement et offres** | cible définie · spécialisation (secteur ou expertise) · offres packagées ou sur devis · différenciation formulée · cohérence de l'offre et du portfolio |
| **2. Acquisition et développement commercial** | sources de leads (dépendance au bouche-à-oreille) · processus commercial formalisé · taux de transformation suivi · prévisibilité du pipeline · visibilité (contenu, réseau, partenaires) |
| **3. Rentabilité et pilotage financier** | méthode de fixation des prix · connaissance du coût horaire · suivi du temps passé · rentabilité suivie par projet · tableau de bord ou prévisionnel |
| **4. Organisation et processus** | process de production documenté · outils de gestion de projet · gestion des demandes hors périmètre · dépendance au dirigeant · délégation et rôles |
| **5. Fidélisation et récurrent** | offre de maintenance existante · *taux de renouvellement*¹ · *revue des contrats de maintenance*¹ · ventes additionnelles aux clients existants · suivi de la satisfaction |
| **6. Stratégie et développement** | objectifs chiffrés à 12 mois · vision à 3 ans · revue stratégique régulière · plan de recrutement ou de capacité · veille et adaptation (IA, marché) |

¹ **Questions conditionnelles** : elles ne s'affichent que si « offre de maintenance existante » est au moins au niveau 1. Sinon, elles sont **non applicables** et exclues du dénominateur.

**Préremplissage depuis le profil** (modifiable, marqué « issu de votre profil ») : « offre de maintenance existante » est prérempli au niveau ≥ 1 si une formule récurrente active existe ; « connaissance du coût horaire » est prérempli au niveau 2 si le coût horaire est calculable. Le profil reste la source de vérité des chiffres. Le diagnostic évalue des pratiques.

### 6.2 Calcul

```
Score de dimension  Sd = 100 × Σ niveaux (questions applicables) ÷ (3 × nombre de questions applicables)
Score global        Sg = moyenne simple des 6 Sd (non arrondis)
Affichage           entier ; stockage avec 2 décimales
Clôture possible    seulement si toutes les questions applicables ont une réponse
```

| Niveau | Score |
|---|---|
| Fragile | 0 à 39 |
| En construction | 40 à 59 |
| Structuré | 60 à 79 |
| Avancé | 80 à 100 |

- **Forces** : les 2 meilleures dimensions avec un score ≥ 60.
- **Axes d'amélioration** : les 2 plus faibles avec un score < 60.
- En cas d'égalité, l'ordre des dimensions du tableau ci-dessus départage. Si aucune dimension n'est éligible, le message s'adapte (« Pas de point fort marqué pour l'instant »).

**Cas DIAG-1**

| Dimension | Somme des niveaux / questions applicables | Score |
|---|---|---|
| D1 | 8 / 5 | 53,33 |
| D2 | 10 / 5 | 66,67 |
| D3 | 6 / 5 | 40,00 |
| D4 | 9 / 4 (une question non applicable) | 75,00 |
| D5 | 12 / 5 | 80,00 |
| D6 | 5 / 5 | 33,33 |

→ Score global **58,06**, affiché **58 / 100**, niveau « En construction ». Forces : D5, D4. Axes d'amélioration : D6, D3.

### 6.3 Mention

« Autoévaluation indicative, fondée sur vos réponses. Ce n'est ni une certification, ni un diagnostic financier officiel. » La table complète des questions, des réponses et des points est publiée sur `/methodologie`.

---

## 7. Moteur de règles

### 7.1 Priorité (déterministe)

```
base(sévérité)     info = 20 · medium = 45 · high = 70 · critical = 90
bonus_dimension    = arrondi(10 × (1 − Sd ÷ 100))   si la règle est liée à une dimension et qu'un diagnostic est terminé, sinon 0
priority_score     = min(100, base + bonus_dimension)
```

Libellés : Critique ≥ 90 · Haute 70 à 89 · Moyenne 45 à 69 · Faible < 45. Tri par `priority_score` décroissant, puis `first_detected_at` croissant.

### 7.2 Catalogue des règles du MVP

| Code | Condition (sur `Facts`) | Sujet | Sévérité | Dimension | Impact |
|---|---|---|---|---|---|
| `RESULT_NEGATIVE` | `Re < 0` | org | critical | D3 | qualitatif, avec le montant du déficit |
| `PRICE_BELOW_FLOOR` | prix actuel < prix plancher (scénario de référence) | service | critical | D3 | perte par vente × volume si connu |
| `PRICE_UNDER` | −20 % < écart ≤ −10 % → medium ; écart ≤ −20 % → high | service | medium/high | D3 | manque à gagner annuel si volume connu |
| `MAINT_UNPROFITABLE` | résultat mensuel par contrat < 0 | offer | high | D5 | perte mensuelle × contrats |
| `MAINT_LOW_MARGIN` | 0 ≤ marge sur prix de vente < 20 % | offer | medium | D5 | qualitatif |
| `RECURRING_SHARE_LOW` | part déclarée < 20 % | org | high | D5 | qualitatif |
| `NO_RECURRING_OFFER` | aucune formule active | org | high | D5 | qualitatif |
| `RECURRING_DATA_GAP` | écart couverture / déclarée > 10 pts | org | info | — | aucun (fiabilité des données) |
| `NO_HOURLY_COST` | coût horaire non calculable | org | high | D3 | aucun (données à compléter) |
| `HOURLY_ASSUMPTIONS` | coût horaire calculé avec des hypothèses par défaut | org | info | D3 | aucun |
| `TARGET_GAP` | objectif > CA annualisé × 1,3 | org | medium | D6 | écart en € (déclaré) |
| `CAPACITY_RECURRING_HIGH` | heures de récurrent > 30 % de la capacité | org | medium | D4 | qualitatif |
| `DIM_LOW_<D1…D6>` | score de la dimension < 40 | dimension | high si < 25, sinon medium | Dx | qualitatif, recommandation spécifique à la dimension |
| `PROFILE_INCOMPLETE` | complétude < 60 % | org | info | — | aucun |
| `NO_DIAGNOSTIC` | aucun diagnostic terminé | org | info | — | aucun |
| `NO_PRICING` | aucune simulation pour une prestation active | org | info | D3 | aucun |

Chaque règle définit en code son titre, son explication paramétrée (avec les valeurs de `evidence`), sa difficulté, ses modules concernés et 2 à 5 actions concrètes. Un impact chiffré n'apparaît **que** dans les cas indiqués « chiffré » ci-dessus, avec le détail de son calcul.

**Cas RULE-1** : PR-1 défini comme scénario de référence de « Site vitrine », volume de 8, aucun diagnostic.
→ `PRICE_UNDER` (sujet `service:<id>`). L'écart est de −19,4 %, donc compris entre −20 % et −10 % : sévérité **medium**, `priority_score` = **45**. Impact chiffré : manque à gagner de **6 164,71 €/an**.

**Cas RULE-2** : avec un diagnostic où D3 = 40 → bonus = arrondi(10 × 0,6) = 6, d'où `priority_score` = **51**.

**Cas RULE-3** : prix relevé à 4 000 € → la condition n'est plus vraie et la recommandation passe en `auto_resolved`.

---

## 8. Complétude du profil

Score pondéré, sur 100 :

| Champ | Poids |
|---|---|
| Période de référence | 10 |
| CA | 15 |
| Charges fixes | 10 |
| Coûts de personnel (0 accepté si explicitement « aucun salarié ») | 10 |
| Rémunération du dirigeant (ou « aucune ») | 10 |
| ETP de production | 10 |
| Heures annuelles (confirmées ou hypothèse acceptée) | 5 |
| Taux de facturabilité (idem) | 5 |
| Charges variables (les deux catégories) | 5 |
| Objectif de CA | 5 |
| Part récurrente déclarée | 5 |
| Au moins une prestation avec prix actuel | 5 |
| Identité (nom, activité, taille, statut) | 5 |

Une valeur `0` saisie volontairement compte comme renseignée. `NULL` ne compte pas.
