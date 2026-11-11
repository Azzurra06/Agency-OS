# Agency OS — Formules financières, scoring et règles

> Statut : **validé avec ajustements le 10/11/2026** (livrable de la phase 2). Décisions consolidées dans [01-cadrage.md §11 et §12](01-cadrage.md).
> Ce document est la **spécification de référence** des calculs. Le code PHP (`app/Domain/*`) doit s'y conformer, et chaque cas chiffré ci-dessous deviendra un test automatisé (`tests/fixtures/*.json`).
> Tous les montants sont **HT, en euros**.

---

## 0. Conventions

| Notation | Signification |
|---|---|
| `NULL` | donnée non renseignée. Un calcul qui en dépend renvoie « non calculable » et la liste des champs manquants, **jamais 0** |
| Taux | stockés en fraction (0,30 = 30 %), échangés en pourcentage dans l'API (`"30.00"`) |
| Statuts | Déclaré · Calculé · Calculé avec hypothèses · Simulé · Démo |
| Figé | une simulation finalisée, un scénario finalisé, un diagnostic terminé ou un instantané conservent les hypothèses et les résultats de leur date de validation, avec `calc_version` |

### 0.1 Politique de précision et d'arrondi

**Outil.** Tous les calculs monétaires, les taux, les heures et les scores passent par `Support\Decimal` (BCMath). **Aucun flottant binaire** n'intervient dans ces calculs. Les valeurs entrent sous forme de chaînes : celles de MySQL `DECIMAL`, renvoyées en chaînes par PDO, et celles de l'API, qui exige des chaînes.

**Précision interne : 12 décimales** pour chaque opération intermédiaire. BCMath tronque à cette échelle, soit une erreur inférieure à 10⁻¹², sans effet sur un arrondi à 2 ou 4 décimales. Les valeurs « pile à la moitié » (0,125 ; 2,675…) sont représentées exactement et s'arrondissent donc correctement.

**Ordre des opérations : multiplier avant de diviser.** Exemple : `X × 12 ÷ D`, et non `X × (12 ÷ D)`, pour éviter de propager un quotient tronqué.

**Arrondi : une seule fois, en sortie**, au plus proche, avec la **moitié éloignée de zéro** (2,675 → 2,68 ; −2,345 → −2,35). Un résultat dérivé est toujours calculé à partir des valeurs **non arrondies**, jamais à partir d'un résultat déjà arrondi.

| Grandeur | Échelle de sortie (API) | Affichage |
|---|---|---|
| Montants, prix, coût horaire | 2 décimales | 2 décimales (`3 970,59 €`) |
| Taux (marge, majoration, écart, part…) | pourcentage à 2 décimales (`"13.63"`) | 1 décimale (`13,6 %`) |
| Heures | 2 décimales | 1 ou 2 décimales |
| Clients projetés | 2 décimales | entier précédé de « ≈ » |
| Scores de diagnostic | 2 décimales | entier |
| Facteur d'annualisation `k`, taux `tv` internes | non arrondis | — |

**Cohérence des totaux.** Un total est l'arrondi de la somme exacte. Une ligne de détail est l'arrondi de sa propre valeur exacte. Un écart d'au plus 0,01 € par ligne entre la somme affichée des lignes et le total est donc possible. Il est accepté, sans ventilation forcée, et signalé par une infobulle.

**Seuils des règles.** Ils sont évalués sur les valeurs **exactes**, pas sur les valeurs arrondies affichées. La valeur exacte figure dans `evidence`.

**Zéro négatif.** `-0.00` est normalisé en `0.00`.

**Valeurs figées.** Les résultats sont stockés **arrondis à l'échelle de sortie** (ce que l'utilisateur a validé), à côté des entrées exactes. Un test vérifie que recalculer à partir des entrées figées et de la même `calc_version` redonne exactement les mêmes résultats.

### 0.2 Cas limites testés (`tests/fixtures/decimal-cases.json`)

| Cas | Attendu | Piège évité |
|---|---|---|
| arrondi(2,675 ; 2) | 2,68 | un flottant donne 2,67 |
| arrondi(1,005 ; 2) | 1,01 | un flottant donne 1,00 |
| arrondi(0,125 ; 2) | 0,13 | arrondi au pair (banquier) |
| arrondi(−2,345 ; 2) | −2,35 | sens de la moitié pour les négatifs |
| arrondi(−0,004 ; 2) | 0,00 | pas de `-0.00` |
| 0,1 + 0,2 | 0,3 exactement | 0,30000000000000004 |
| 100 ÷ 3, puis × 3 | 33,33 affiché ; 100,00 en recalcul exact | arrondi intermédiaire |
| 270 000 × 12 ÷ 18 | 180 000,00 | ordre des opérations |
| 99 999 999,99 × 9 999 h | exact, sans dépassement | débordement (les résultats figés sont stockés en JSON texte) |
| H = 0 | erreur `no_billable_hours` | division par zéro |
| P = 0 (marge sur prix) | non calculable | division par zéro |
| R = 0 | `tv` = NULL, signalé | division par zéro |
| m + tv = 1 exactement | erreur `margin_plus_variable_rate_too_high` | prix infini |
| m = 0,90 et tv = 0,0999 | prix valide, très élevé, avec un avertissement | borne |
| saisie `"12,5"`, `"1e3"` ou nombre JSON | 422 | format |


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

**Cas PER-1** : exercice du 01/07/2024 au 31/12/2025 → `D = 18`, `k = 12/18`. CA déclaré 270 000 € → CA annualisé `270 000 × 12 ÷ 18` = **180 000,00 €**.

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

### 4.5 Brouillon et simulation finalisée

| | Brouillon | Finalisée |
|---|---|---|
| Coût horaire, `tv`, prix actuel | lus en direct dans le profil et le catalogue | **figés** dans `frozen_context` |
| Résultats | recalculés à chaque lecture | **figés** dans `frozen_results` (arrondis à l'échelle de sortie) |
| Modification | libre | interdite (409) ; « Nouvelle version » crée un brouillon |
| Utilisée par les règles | **non**. Ses calculs, résultats et alertes locales (« sous le prix plancher », « marge inférieure à la cible ») s'affichent instantanément dans le simulateur, sans effet sur la stratégie globale | oui, **uniquement** la simulation finalisée **active** de la prestation (scénario de référence) |
| Dérive | — | indicateur, calculé à la lecture, si le coût horaire ou `tv` courants diffèrent des valeurs figées |
| Consultation | — | toujours possible, y compris pour les versions remplacées ou archivées |

**Simulation active d'une prestation** : pour chaque prestation, une seule simulation finalisée est **active**, la plus récemment finalisée. Finaliser une simulation pour une prestation fait passer l'éventuelle simulation active précédente de **cette prestation** en `superseded` (`superseded_by` renseigné). Les autres prestations ne sont pas concernées. Une simulation « sur mesure » (sans prestation du catalogue) n'est jamais utilisée par les règles de prix. L'utilisateur peut **archiver** une simulation finalisée : elle reste consultable, mais n'est plus active.

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

6 dimensions **à poids égaux**, 5 questions chacune (30 au total), dans la version `v1` du questionnaire. Chaque question propose 4 réponses graduées (niveaux 0 à 3) et une réponse « Je ne sais pas », dont le traitement est **défini question par question** (§6.2).

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

### 6.2 « Je ne sais pas » : une politique par question

Chaque question déclare, dans la version du questionnaire, une politique `unknown_policy`. **Par défaut, « Je ne sais pas » est une réponse inconnue, non exploitable.** Elle ne devient évaluable que si la question le déclare explicitement :

| Politique | Signification | Effet sur le calcul | Quand l'utiliser |
|---|---|---|---|
| `evaluable` (doit être **déclarée explicitement** dans le questionnaire) | ne pas savoir **est** un manque de maturité | compte au niveau 0 et entre dans le calcul | la question porte sur une donnée ou une pratique que le dirigeant devrait maîtriser (« Connaissez-vous votre coût horaire ? », « Suivez-vous la rentabilité par projet ? ») |
| `unknown` (**valeur par défaut**) | réponse inconnue, sans jugement | retirée du calcul ; elle réduit la fiabilité | l'information peut légitimement être inconnue ou difficile à estimer (« Taux de renouvellement de vos contrats », « Part des leads issus du bouche-à-oreille ») |

L'interface explique la politique au moment de la réponse. Pour une question `evaluable`, une note précise : « Si vous ne le savez pas, c'est un axe de progrès : la réponse est comptée comme pratique absente. » La politique de chaque question figure aussi sur `/methodologie`.

### 6.3 Calcul, seuils et fiabilité

```
Par dimension :
  A   = questions applicables (conditions remplies)
  Ue  = réponses « Je ne sais pas » sur des questions `unknown`
  Up  = réponses « Je ne sais pas » sur des questions `evaluable` (niveau 0)
  n   = A − Ue                                   questions entrant dans le calcul
  Sd  = 100 × Σ niveaux (n questions) ÷ (3 × n)
  Fiabilité Fd = n ÷ A

Dimension calculable   si  n ≥ 3  ET  Fd ≥ 0,60
Score global Sg        = moyenne simple des 6 Sd (non arrondis), calculé SEULEMENT si les 6 dimensions sont calculables
Fiabilité globale Fg   = Σ n ÷ Σ A
Clôture du diagnostic  normale si toutes les questions applicables ont une réponse (« Je ne sais pas » compris) ;
                       sinon, seulement après confirmation explicite (« Terminer quand même ») : les questions
                       sans réponse sont exclues du calcul comme une réponse inconnue (Ue) ; refusée si aucune
                       dimension n'est calculable
```

| Fiabilité globale | Libellé affiché à côté du score |
|---|---|
| Fg ≥ 0,90 | Fiabilité élevée |
| 0,75 ≤ Fg < 0,90 | Fiabilité moyenne : « x réponses inconnues ont été exclues » |
| Fg < 0,75 | Fiabilité faible : « interprétez ce score avec prudence » |

Le nombre de réponses `Up` (comptées comme pratique absente) est également affiché.

**Diagnostic partiel.** (Décision du 11/11/2026 : avec le questionnaire v1, une seule question par dimension a la politique `unknown` ; le statut partiel n'est donc atteignable que par une clôture confirmée avec des questions sans réponse.) Si au moins une dimension n'est pas calculable, le diagnostic terminé porte le statut **« Diagnostic partiel »** :
- les scores des dimensions calculables sont affichés ; les autres apparaissent « Données insuffisantes », avec la liste des questions restées inconnues et un bouton « Compléter ces réponses » (qui crée un nouveau diagnostic prérempli) ;
- **le score global n'est pas affiché** : « Score global disponible lorsque les 6 dimensions sont renseignées » ;
- le radar grise les axes non calculables, sans les placer à 0 ;
- les recommandations **peuvent** s'appuyer sur les dimensions calculables, avec la mention « Fondée sur un diagnostic partiel ». Elles ne présentent jamais un résultat global.

Affichage : entier. Stockage : 2 décimales. Arrondi selon §0.1.

| Niveau | Score |
|---|---|
| Fragile | 0 à 39 |
| En construction | 40 à 59 |
| Structuré | 60 à 79 |
| Avancé | 80 à 100 |

- Seules les dimensions **calculables** sont éligibles aux forces et aux axes d'amélioration.
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

→ Aucune réponse inconnue, fiabilité 1,00. Score global **58,06**, affiché **58 / 100**, niveau « En construction », fiabilité élevée. Forces : D5, D4. Axes d'amélioration : D6, D3.

**Cas DIAG-2** (« Je ne sais pas »)
- D2, 5 questions applicables. Réponses : 3, 2, *inconnu (`unknown`)*, 2, *inconnu (`evaluable`)* → `n` = 4, niveaux 3 + 2 + 2 + 0 = 7 → `Sd` = 100 × 7 ÷ 12 = **58,33** ; `Fd` = 0,80 → calculable.
- D6, 5 questions applicables, dont 3 inconnues `unknown` → `n` = 2 < 3 → **non calculable**.
- Conséquence : D2 est affichée (58), D6 apparaît comme « Données insuffisantes », et le **score global n'est pas affiché**. Statut **Diagnostic partiel**. La règle `DIM_LOW_D6` ne se déclenche pas. `DIM_LOW_D2` peut se déclencher (D2 est calculable) avec la mention « diagnostic partiel ». `DIAG_LOW_RELIABILITY` se déclenche (§7.2).

### 6.4 Versions

L'interprétation d'un diagnostic utilise **toujours sa propre version** : libellés, conditions, politiques et scoring d'origine. Ses scores sont figés à la clôture. Un diagnostic `v1` reste lisible et comparable par dimension après la publication d'une `v2` (voir 02 §5.5).

### 6.5 Mention

« Autoévaluation indicative, fondée sur vos réponses. Ce n'est ni une certification, ni un diagnostic financier officiel. » La table complète des questions, des réponses et des points est publiée sur `/methodologie`.

---

## 7. Moteur de règles

### 7.1 Priorité (déterministe)

```
base(sévérité)     info = 20 · medium = 45 · high = 70 · critical = 90
bonus_dimension    = arrondi(10 × (1 − Sd ÷ 100))   si la règle est liée à une dimension CALCULABLE du dernier diagnostic terminé, sinon 0
priority_score     = min(100, base + bonus_dimension)
```

Libellés : Critique ≥ 90 · Haute 70 à 89 · Moyenne 45 à 69 · Faible < 45. Tri par `priority_score` décroissant, puis `first_detected_at` croissant.

### 7.2 Catalogue des règles du MVP

| Code | Condition (sur `Facts`) | Sujet | Sévérité | Dimension | Impact |
|---|---|---|---|---|---|
| `RESULT_NEGATIVE` | `Re < 0` | org | critical | D3 | qualitatif, avec le montant du déficit |
| `PRICE_BELOW_FLOOR` | prix actuel < prix plancher (scénario de référence de la dernière simulation **finalisée**) | service | critical | D3 | perte par vente × volume si connu |
| `PRICE_UNDER` | même source ; −20 % < écart ≤ −10 % → medium ; écart ≤ −20 % → high | service | medium/high | D3 | manque à gagner annuel si volume connu |
| `PRICING_DRIFT` | simulation finalisée dont le coût horaire ou `tv` figés s'écartent de plus de 5 % des valeurs actuelles | service | info | D3 | aucun (« à revalider ») |
| `MAINT_UNPROFITABLE` | résultat mensuel par contrat < 0 | offer | high | D5 | perte mensuelle × contrats |
| `MAINT_LOW_MARGIN` | 0 ≤ marge sur prix de vente < 20 % | offer | medium | D5 | qualitatif |
| `RECURRING_SHARE_LOW` | part déclarée < 20 % | org | high | D5 | qualitatif |
| `NO_RECURRING_OFFER` | aucune formule active | org | high | D5 | qualitatif |
| `RECURRING_DATA_GAP` | écart couverture / déclarée > 10 pts | org | info | — | aucun (fiabilité des données) |
| `NO_HOURLY_COST` | coût horaire non calculable | org | high | D3 | aucun (données à compléter) |
| `HOURLY_ASSUMPTIONS` | coût horaire calculé avec des hypothèses par défaut | org | info | D3 | aucun |
| `TARGET_GAP` | objectif > CA annualisé × 1,3 | org | medium | D6 | écart en € (déclaré) |
| `CAPACITY_RECURRING_HIGH` | heures de récurrent > 30 % de la capacité | org | medium | D4 | qualitatif |
| `DIM_LOW_<D1…D6>` | dimension **calculable** et score < 40 | dimension | high si < 25, sinon medium | Dx | qualitatif, recommandation spécifique à la dimension |
| `DIAG_LOW_RELIABILITY` | dernier diagnostic avec une dimension non calculable ou une fiabilité globale < 0,75 | org | info | — | aucun (« compléter ou refaire le diagnostic ») |
| `PROFILE_INCOMPLETE` | complétude < 60 % | org | info | — | aucun |
| `NO_DIAGNOSTIC` | aucun diagnostic terminé | org | info | — | aucun |
| `NO_PRICING` | aucune simulation **finalisée** pour une prestation active | org | info | D3 | aucun |

Chaque règle définit en code son titre, son explication paramétrée (avec les valeurs de `evidence`), sa difficulté, ses modules concernés et 2 à 5 actions concrètes. Un impact chiffré n'apparaît **que** dans les cas indiqués « chiffré » ci-dessus, avec le détail de son calcul.

**Cas RULE-1** : simulation « Site vitrine » finalisée avec PR-1 comme scénario de référence, volume de 8, aucun diagnostic.
→ `PRICE_UNDER` (sujet `service:<id>`). L'écart est de −19,4 %, donc compris entre −20 % et −10 % : sévérité **medium**, `priority_score` = **45**. Impact chiffré : manque à gagner de **6 164,71 €/an**.

**Cas RULE-2** : avec un diagnostic où D3 = 40 → bonus = arrondi(10 × 0,6) = 6, d'où `priority_score` = **51**.

**Cas RULE-3** : prix du catalogue relevé à 4 000 € → la condition n'est plus vraie et la recommandation passe en `auto_resolved`.

**Cas RULE-4** : PR-1 encore en **brouillon** → aucune règle de prix ne se déclenche, seulement `NO_PRICING`.

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
