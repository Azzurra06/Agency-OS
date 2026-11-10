# Agency OS — Phase 1 : Cadrage fonctionnel

> Statut : **validé le 10/11/2026**. Les décisions des §10 (phase 1) et §11 (phase 2) **priment** sur les propositions des §4 à §9 en cas de divergence.
> Périmètre de ce document : vision, parcours, périmètre du MVP, dépendances entre modules, ambiguïtés et arborescence.
> Aucun code n'est produit à ce stade. L'architecture technique détaillée (schéma SQL, endpoints, services) fera l'objet de la phase 2.

---

## 1. Vision produit reformulée

### 1.1 En une phrase

Agency OS est un **copilote de pilotage** pour les dirigeants de petites agences digitales. Il relie ce que l'agence *est* (diagnostic), ce qu'elle *vend* (pricing, offres récurrentes) et ce qu'elle *gagne* (rentabilité). Il en tire ensuite **un plan d'action priorisé et suivi**.

### 1.2 Le problème adressé

Un dirigeant d'agence de 1 à 15 personnes cumule souvent ces difficultés :

- il fixe ses prix « au marché » ou à l'intuition, sans connaître son vrai coût horaire ;
- il dépend des projets ponctuels, ce qui donne un chiffre d'affaires en dents de scie et peu de visibilité ;
- il vend de la maintenance sans savoir si elle est rentable ;
- il n'a pas d'outil qui fasse le lien entre ces sujets : la comptabilité regarde le passé, le CRM regarde les prospects, l'outil de gestion de projet regarde la production ;
- il n'est pas financier : les notions de marge, de majoration, de coût complet ou de MRR doivent lui être expliquées.

### 1.3 Ce qu'Agency OS est / n'est pas

| Agency OS **est** | Agency OS **n'est pas** |
|---|---|
| Un outil d'aide à la décision | Un logiciel de comptabilité (pas d'écritures, pas de TVA, pas de bilan) |
| Un simulateur de prix et de rentabilité | Un outil de devis/facturation (pas de documents clients au MVP) |
| Un modélisateur de revenus récurrents | Un CRM (pas de fiches clients ni de pipeline) |
| Un générateur de plan d'action | Un outil de gestion de projet (pas de tâches de production) |
| Un outil pédagogique | Une certification ou un audit financier officiel |

### 1.4 Différenciation : la boucle de pilotage

L'élément différenciant est la **boucle** entre les modules, pas chaque module pris isolément :

```
        ┌────────────── Profil agence (données déclarées) ──────────────┐
        │                                                                │
        ▼                                                                ▼
   Diagnostic ──► scores ──┐                         ┌── coût horaire ──► Pricing
                           │                         │                      │
                           ▼                         │                      │ écarts de prix
                    Moteur de règles ◄───────────────┴──────────────────────┤
                           │                                                │
                           ▼                                                │
                 Recommandations ──► Plan d'action ──► Dashboard ◄──────────┤
                           ▲                                                │
                           │                                                │
                Revenus récurrents ◄── MRR, part récurrente, capacité ◄─────┘
```

Chaque recommandation est **traçable** : elle cite les données qui l'ont déclenchée (« Votre score Rentabilité est de 38/100 et votre prix Site vitrine est 22 % sous le prix recommandé »).

### 1.5 Principes directeurs

1. **Aucune donnée inventée.** Toute valeur affichée a un statut explicite (voir §5.1).
2. **Une source de vérité par information.** Chaque indicateur est calculé à un seul endroit, côté serveur.
3. **La pédagogie est intégrée à l'écran.** Chaque indicateur a une définition, une formule lisible et un ordre de grandeur.
4. **Saisie progressive.** On ne bloque jamais l'utilisateur : chaque module fonctionne avec un profil partiel et signale ce qui manque.
5. **Sobriété.** Peu d'indicateurs, bien hiérarchisés, avec une action associée.

---

## 2. Utilisateurs cibles

| Persona | Description | Besoin principal | Niveau finance |
|---|---|---|---|
| **Claire, fondatrice solo/duo** (cible principale, cas le plus fréquent) | Agence WordPress de 1 à 3 personnes, CA de 80 à 250 k€ | Savoir si ses prix couvrent ses coûts, sécuriser du récurrent | Faible |
| **Marc, dirigeant d'agence structurée** (cible principale) | 5 à 15 collaborateurs, plusieurs offres, CA de 400 k€ à 1,5 M€ | Piloter la marge par offre, réduire la dépendance aux gros projets | Moyen |
| **Visiteur portfolio** (recruteur, prospect) | Découvre le produit sans créer de compte | Comprendre la valeur en 2 minutes | Variable |
| *Freelance / consultant* (cible future) | Indépendant | Coût horaire, TJM, revenu cible | Faible |

Conséquence de conception : le parcours par défaut est pensé pour **Claire**. Les détails utiles à Marc (répartition production / structure, plusieurs scénarios) sont disponibles sans être imposés.

---

## 3. Parcours utilisateurs

### P0 — Visiteur : découvrir la démo

1. La page d'accueil présente la promesse, trois captures et deux boutons : **Essayer la démo** et **Créer un compte**.
2. **Essayer la démo** connecte le visiteur à une organisation de démonstration (« Agence Lumen », fictive, données cohérentes).
3. Un bandeau permanent indique : *« Mode démonstration — données fictives »*, avec un lien vers l'inscription.
4. Le visiteur peut tout manipuler. Ses changements sont isolés (une copie par session de démo) et purgés automatiquement.

### P1 — Première connexion et onboarding

1. Inscription (email, mot de passe, nom de l'agence), puis création de l'organisation et du compte propriétaire.
2. Onboarding en **3 étapes courtes**, toutes passables :
   - **L'agence** : nom, type d'activité, taille de l'équipe, statut juridique.
   - **Les chiffres** : CA annuel, charges fixes, charges variables, coûts de personnel, rémunération du dirigeant, objectif de CA, part récurrente estimée.
   - **Les offres** : sélection des prestations vendues (site vitrine, e-commerce, refonte, maintenance, SEO, sur mesure), avec prix moyen et volume annuel facultatifs.
3. Arrivée sur le dashboard, avec une checklist **« Bien démarrer »** : compléter le profil (x %), faire le diagnostic, créer une simulation de prix, créer une offre de maintenance.
4. Les indicateurs sans donnée affichent un **état vide explicatif** (« Renseignez vos charges pour calculer votre résultat estimé »), jamais un 0 trompeur.

### P2 — Diagnostic de maturité

1. Écran d'introduction : 6 dimensions, environ 10 minutes, reprise possible, méthode de scoring visible.
2. Questionnaire multi-étapes, une dimension par étape, avec barre de progression et sauvegarde automatique à chaque réponse.
3. Questions conditionnelles : par exemple, si l'agence ne vend pas de maintenance, les questions sur le taux de renouvellement sont masquées et **exclues du calcul**.
4. Écran de résultats :
   - score global sur 100 ;
   - radar sur 6 axes ;
   - forces (les 2 meilleures dimensions) et axes d'amélioration (les 2 plus faibles) ;
   - recommandations générées, avec un lien vers la Stratégie.
5. Historique : un nouveau diagnostic peut être refait plus tard, et les scores sont comparés au précédent.

### P3 — Simuler le prix d'une prestation

1. L'utilisateur choisit une prestation de son catalogue, ou « Prestation sur mesure ».
2. Le **coût horaire** est prérempli depuis le profil financier. Sa provenance est indiquée : calculé, ou saisi manuellement.
3. Il saisit le temps par phase (cadrage, design, développement, contenus, recette, gestion de projet). Un modèle indicatif par type de prestation est proposé, clairement marqué comme hypothèse modifiable.
4. Il ajoute les frais externes (licences, thème premium, sous-traitance) et la marge cible.
5. Les résultats s'affichent en direct : coût de revient, prix plancher, prix recommandé, marges, écart avec le prix pratiqué.
6. Il duplique en scénario B ou C (par exemple moins de temps de design, ou une marge différente) et les compare côte à côte.
7. À l'enregistrement, le serveur recalcule et fait foi. Si l'écart avec le prix actuel est significatif, une recommandation « Revoir le prix de … » est créée ou mise à jour.

### P4 — Concevoir une offre de maintenance

1. Création d'un package (Essentiel, Sérénité, Premium…) : prix mensuel ou annuel, prestations incluses (checklist), temps mensuel estimé, coûts externes par client.
2. Rentabilité par formule : marge par client et par mois, taux de marge, prix plancher.
3. Simulation de portefeuille : clients au départ, nouveaux clients par mois, taux d'attrition (churn) mensuel. Projection sur 6 et 12 mois du MRR et de l'ARR.
4. **Vérification de capacité** : heures mensuelles nécessaires comparées à la capacité de l'équipe (issue du profil).
5. L'utilisateur saisit le **nombre de contrats réellement signés** par formule. Ces contrats forment le MRR contractualisé, distinct du MRR simulé.

### P5 — Passer à l'action

1. La page Stratégie liste les recommandations actives, triées par priorité.
2. Pour chacune : titre, explication, données déclencheuses, priorité, impact attendu, difficulté, modules concernés, actions concrètes.
3. Actions possibles : **Accepter** (la recommandation passe au plan d'action), **Ignorer** (avec un motif facultatif), **Reporter**.
4. Dans le plan d'action : échéance, statut (à faire, en cours, terminé), cases à cocher pour les actions concrètes, progression en %.
5. La progression du plan apparaît sur le dashboard. Une recommandation dont la condition n'est plus remplie (par exemple après une hausse de prix) passe en **« Résolue automatiquement »**.

### P6 — Suivi récurrent (mensuel)

1. L'utilisateur met à jour ses chiffres dans Paramètres, puis Données financières.
2. Un **instantané** des indicateurs clés est enregistré : à chaque mise à jour significative, et au plus une fois par jour.
3. Le dashboard affiche l'évolution de ces instantanés (graphique en courbes).

---

## 4. Périmètre détaillé du MVP

Légende : **M** = indispensable (Must), **S** = souhaitable si le temps le permet (Should), **W** = hors MVP, avec l'architecture prévue pour plus tard (Won't now).

### Socle

| Fonction | Priorité |
|---|---|
| Inscription, connexion, déconnexion, sessions sécurisées | M |
| CSRF, validation serveur, échappement XSS, requêtes préparées | M |
| Limitation des tentatives de connexion | M |
| Isolation des données par organisation, contrôle d'accès sur chaque endpoint | M |
| Table d'appartenance utilisateur ↔ organisation avec rôle (un seul « owner » utilisé au MVP) | M |
| Mot de passe oublié par email (SMTP Hostinger) | S |
| Changement de mot de passe, suppression du compte et des données | M |
| Mode démonstration isolé et purgé automatiquement | M |
| Invitations de collaborateurs, rôles multiples | W |

### Module A — Profil agence

| Fonction | Priorité |
|---|---|
| Onboarding en 3 étapes passables | M |
| Édition ultérieure dans Paramètres | M |
| Indicateur de complétude pondéré (les champs financiers clés pèsent plus) | M |
| Statut de chaque valeur : déclarée ou hypothèse | M |
| Catalogue des prestations (nom, type, prix actuel, volume annuel facultatif) | M |
| Coût horaire calculé, avec possibilité de le forcer manuellement | M |
| Séparation des coûts de personnel entre production et structure | S |
| Historique détaillé des modifications du profil | W |

### Module B — Diagnostic

| Fonction | Priorité |
|---|---|
| 6 dimensions, 5 à 6 questions chacune (≈ 32 questions) | M |
| Multi-étapes, progression, sauvegarde automatique, reprise | M |
| Questions conditionnelles, exclues du score si non applicables | M |
| Score global /100 et score par dimension, déterministes et documentés | M |
| Radar Chart.js, forces et axes d'amélioration | M |
| Recommandations déclenchées | M |
| Historique et comparaison avec le diagnostic précédent | S |
| Page « Méthodologie » publique expliquant le scoring | M |

### Module C — Pricing et rentabilité

| Fonction | Priorité |
|---|---|
| Simulation par prestation : temps par phase, frais externes, marge cible | M |
| Calculs : coût de revient, prix plancher, prix recommandé, marge sur prix de vente, taux de marge sur coût, résultat estimé, écart avec le prix actuel | M |
| Calcul instantané côté client pour l'affichage, recalcul serveur à l'enregistrement | M |
| Jusqu'à 3 scénarios par simulation, comparaison côte à côte | M |
| Enregistrer, modifier, dupliquer, supprimer | M |
| Modèles de temps indicatifs par type de prestation | M |
| Manque à gagner annuel (écart × volume annuel, si le volume est connu) | S |
| Provision pour aléas (% de temps supplémentaire) | S |
| Export PDF de la simulation | W |

### Module D — Revenus récurrents

| Fonction | Priorité |
|---|---|
| Création de packages : prix mensuel ou annuel, prestations incluses, temps mensuel, coûts externes | M |
| Rentabilité par formule | M |
| Nombre de contrats signés par formule, qui donne le MRR contractualisé | M |
| Scénarios de portefeuille (départ, acquisition mensuelle, churn) et projection sur 6 et 12 mois | M |
| MRR, ARR, graphiques (barres empilées par formule, courbe de projection) | M |
| Comparaison de scénarios (jusqu'à 3) | M |
| Vérification de capacité (heures nécessaires comparées aux heures disponibles) | S |
| Récurrent hors maintenance (SEO mensuel, hébergement) via un type d'offre générique | S |

### Module E — Stratégie et plan d'action

| Fonction | Priorité |
|---|---|
| Moteur de règles métier (≈ 15 à 20 règles au MVP) | M |
| Recommandation complète : titre, explication, données déclencheuses, priorité, impact, difficulté, modules, actions | M |
| Accepter, ignorer, reporter | M |
| Plan d'action : échéance, statut, sous-actions cochables, progression | M |
| Résolution automatique quand la condition n'est plus vraie | M |
| Ajout d'actions libres, non issues d'une recommandation | S |
| Interface prévue pour un enrichissement par IA générative, non branché | M (interface seulement) |
| Assistant IA | W |

### Module F — Dashboard

| Fonction | Priorité |
|---|---|
| Cartes KPI : score de maturité, CA annuel, charges totales, résultat estimé, MRR, part récurrente | M |
| Progression vers l'objectif de CA | M |
| 3 recommandations prioritaires et progression du plan d'action | M |
| Mini-radar du diagnostic et répartition du MRR par formule | M |
| Évolution des indicateurs à partir des instantanés | M |
| Checklist « Bien démarrer », états vides pédagogiques | M |
| Accès rapides aux modules | M |
| Benchmark sectoriel | W |

### Hors MVP (architecture préparée, rien de développé)

Générateur de propositions commerciales, assistant IA, intégrations comptables (Pennylane, etc.), multi-collaborateurs, benchmark, abonnements SaaS (Stripe), coaching, export PDF avancé, version freelance.

Préparations minimales et peu coûteuses prévues au MVP :
- table d'appartenance avec un rôle ;
- un champ `plan` sur l'organisation ;
- un moteur de recommandations derrière une interface (`RecommendationProvider`) ;
- des services de calcul indépendants de l'API, réutilisables par un futur export PDF ;
- des données isolées par `organization_id` partout.

---

## 5. Données, sources de vérité et dépendances entre modules

### 5.1 Statut des données (affiché dans l'interface)

| Badge | Signification | Exemple |
|---|---|---|
| **Déclaré** | Saisi par l'utilisateur comme une réalité | CA annuel 2025 : 180 000 € |
| **Calculé** | Dérivé par le serveur de données déclarées | Coût horaire complet : 62 €/h |
| **Hypothèse** | Valeur par défaut ou estimation, modifiable, à confirmer | Taux de facturabilité : 65 % |
| **Simulé** | Résultat d'un scénario prospectif | MRR à 12 mois : 4 200 € |
| **Démo** | Donnée fictive de l'organisation de démonstration | Tout le compte démo |

Règle : un indicateur calculé à partir d'au moins une hypothèse affiche « Calculé, dont hypothèses » et liste ces hypothèses dans une info-bulle.

### 5.2 Sources de vérité

| Information | Source unique | Consommateurs |
|---|---|---|
| CA annuel, charges, coûts de personnel, objectifs | Profil financier (A) | Dashboard, Règles, Pricing (indirectement) |
| Heures facturables annuelles | Profil (A) : équipe × heures × taux de facturabilité | Coût horaire, capacité (D) |
| **Coût horaire complet** | Calcul de A (ou valeur forcée dans A) | Pricing (C), Récurrent (D) |
| Catalogue des prestations, prix actuels, volumes | Profil (A) | Pricing (C), Règles (E) |
| Prix recommandé d'une prestation | Scénario de référence de la simulation (C) | Règles (E), Dashboard |
| MRR contractualisé | Contrats signés × prix des formules (D) | Dashboard, Règles, part récurrente |
| MRR simulé | Scénario de portefeuille (D) | Récurrent uniquement, jamais additionné au réel |
| Part récurrente du CA | Calculée (voir §6, A6) | Dashboard, Règles |
| Scores de maturité | Dernier diagnostic terminé (B) | Dashboard, Règles |
| Recommandations | Moteur de règles (E) | Stratégie, Dashboard |
| Progression du plan | Plan d'action (E) | Dashboard |
| Évolution | Instantanés d'indicateurs | Dashboard |

### 5.3 Matrice des dépendances

| Producteur → Consommateur | Diagnostic | Pricing | Récurrent | Stratégie | Dashboard |
|---|---|---|---|---|---|
| **Profil (A)** | préremplit certaines réponses factuelles | coût horaire, catalogue | coût horaire, capacité | ratios financiers | KPI financiers |
| **Diagnostic (B)** | — | — | — | scores par dimension | score, radar |
| **Pricing (C)** | — | — | — | écarts de prix, marges | prestations sous-évaluées |
| **Récurrent (D)** | — | — | — | MRR, part récurrente, rentabilité des formules | MRR, répartition |
| **Stratégie (E)** | — | lien « Ouvrir le simulateur » | lien « Créer une offre » | — | recommandations, progression |

### 5.4 Ordre de développement qui en découle

Socle et authentification, puis Profil (A), puis Dashboard en version squelette, puis Diagnostic (B), Pricing (C), Récurrent (D), Stratégie (E). On termine par le Dashboard complet et le mode démo.

Le Dashboard est construit tôt pour valider le socle, puis enrichi à chaque module. La Stratégie arrive en dernier, car elle consomme tous les autres modules.

### 5.5 Exemples de règles métier (illustration)

| Code | Condition | Recommandation | Priorité |
|---|---|---|---|
| `REC_SHARE_LOW` | Part récurrente < 20 % | Structurer une offre de maintenance | Haute |
| `PRICE_UNDER` | Prix actuel < prix recommandé − 10 % pour une prestation | Revoir le prix de {prestation} | Haute si l'écart dépasse 20 % |
| `PRICE_UNDER_FLOOR` | Prix actuel < prix plancher | Prestation vendue à perte : {prestation} | Critique |
| `MAINT_UNPROFITABLE` | Marge d'une formule de maintenance < 0 | Réviser la formule {offre} | Haute |
| `RESULT_NEGATIVE` | Résultat estimé < 0 | Plan de redressement : prix et charges | Critique |
| `GOAL_GAP` | Objectif de CA > CA × 1,3 sans levier identifié | Construire un plan de croissance chiffré | Moyenne |
| `DIM_POSITIONING_LOW` | Score Positionnement < 40 | Formaliser le positionnement et la cible | Moyenne |
| `DIM_SALES_LOW` | Score Acquisition < 40 | Mettre en place un processus commercial | Haute |
| `CAPACITY_OVER` | Heures de maintenance > 30 % de la capacité | Industrialiser ou augmenter les prix de maintenance | Moyenne |
| `PROFILE_INCOMPLETE` | Complétude < 60 % | Compléter le profil pour fiabiliser l'analyse | Info |

Priorité = sévérité de la règle × poids de la dimension concernée. Le calcul sera documenté en phase 2. Un impact chiffré n'est affiché que s'il est calculable (par exemple écart de prix × volume annuel déclaré), sinon il reste qualitatif.

---

## 6. Ambiguïtés fonctionnelles et financières (décisions proposées)

Chaque point comporte une **proposition**. Les points marqués ⚠️ demandent ta validation explicite.

### Financier

**F1. HT ou TTC.** Tous les montants sont **HT**, en euros. Pas de gestion de TVA, ni de franchise en base. Une mention le rappelle sur chaque écran financier.

**F2. Quel « CA annuel » ?** ⚠️ Proposition : *CA du dernier exercice clos ou des 12 derniers mois*, choix indiqué par l'utilisateur. L'objectif de CA porte sur l'exercice suivant. Pas de multi-exercices au MVP ; l'évolution passe par les instantanés.

**F3. Rémunération du dirigeant.** ⚠️ Dans une SARL/SAS, elle est parfois en salaire ; en micro-entreprise ou en EI, elle n'existe pas comptablement. Si on l'ignore, le coût horaire est sous-estimé et les prix recommandés aussi. Proposition : un champ dédié **« Rémunération annuelle souhaitée du dirigeant »**, avec une case « déjà incluse dans les coûts de personnel » pour éviter le double comptage.

**F4. Coûts de personnel.** On demande le **coût employeur chargé**, salaire brut plus charges patronales. On ne modélise pas les cotisations selon le statut : c'est trop variable et ce n'est pas le rôle du produit. Une aide contextuelle donne l'ordre de grandeur (environ brut × 1,45 en France).

**F5. Coût horaire.**
```
Coûts annuels à couvrir   = charges fixes + coûts de personnel chargés + rémunération dirigeant (si non incluse)
Heures facturables / an   = Σ (personnes de production × heures travaillées/an × taux de facturabilité)
Coût horaire complet      = Coûts annuels à couvrir / Heures facturables
```
- Valeurs par défaut, marquées **Hypothèse** : 1 607 h travaillées par an et par ETP, taux de facturabilité de 65 %.
- ⚠️ Les **charges variables** du profil (commissions, sous-traitance récurrente, frais par projet) **ne sont pas intégrées** au coût horaire. Elles sont saisies par projet dans le pricing, sous forme de frais externes, pour éviter le double comptage. Elles servent uniquement au résultat estimé global du dashboard.
- Option avancée (S) : séparer production et structure, ce qui permet de calculer un *coût horaire direct* (production seule) en plus du *coût horaire complet*.

**F6. Marge ou majoration : vocabulaire figé.** Ces termes seront utilisés partout, avec une définition en info-bulle :

| Terme affiché | Formule | Exemple (coût 1 000 €, prix 1 500 €) |
|---|---|---|
| Coût de revient | temps × coût horaire complet + frais externes | 1 000 € |
| Prix plancher | = coût de revient (résultat nul) | 1 000 € |
| Résultat estimé de la prestation | prix de vente − coût de revient | 500 € |
| **Marge sur prix de vente** (taux de marque) | résultat ÷ prix de vente | 33,3 % |
| **Taux de marge sur coût** (majoration) | résultat ÷ coût de revient | 50 % |
| Prix recommandé | coût de revient ÷ (1 − marge cible sur prix de vente) | marge cible 30 % → 1 428,57 € |
| Écart avec le prix actuel | (prix actuel − prix recommandé) ÷ prix recommandé | — |

- ⚠️ La **marge cible** est saisie **sur le prix de vente**, ce qui est plus intuitif et évite l'erreur classique « coût × 1,3 = 30 % de marge ». Elle est bornée entre 0 % et 90 %.
- Le terme comptable « marge brute » (prix − achats consommés) n'est **pas** utilisé pour une prestation en coût complet, car il serait trompeur. Si l'option production/structure est activée, on ajoute une *marge sur coûts directs*.

**F7. Arrondis.** Calculs en précision décimale côté serveur (centimes stockés en entiers, ou `DECIMAL(12,2)`, à trancher en phase 2). Les arrondis ne sont appliqués qu'à l'affichage. Le prix recommandé peut être arrondi de façon commerciale (à la dizaine supérieure), présenté comme option, à côté de la valeur exacte.

**F8. MRR et ARR.**
```
MRR (formule)        = prix mensuel × contrats actifs      (prix annuel ÷ 12 si facturé annuellement)
MRR total            = Σ MRR des formules
ARR                  = MRR × 12
Marge mensuelle      = prix mensuel − (heures/mois × coût horaire complet) − coûts externes/mois
Projection (mois m)  = clients(m) = clients(m−1) × (1 − churn) + nouveaux ;  MRR(m) = Σ clients(m) × prix
```
- Le MRR **contractualisé** et le MRR **simulé** ne sont jamais additionnés.
- Une formule facturée annuellement n'est pas encaissée chaque mois. Le MRR reste un indicateur normalisé et non de trésorerie, ce que l'interface précise.

**F9. Part récurrente du CA.** ⚠️ Deux sources sont possibles : la part déclarée à l'onboarding, et celle calculée à partir des contrats saisis. Proposition :
- si au moins un contrat est saisi : `part récurrente = (MRR contractualisé × 12) ÷ CA annuel`, avec le badge Calculé ;
- sinon : la part déclarée, avec le badge Déclaré ;
- en cas d'écart de plus de 10 points entre les deux, un message invite à mettre à jour le profil.

**F10. « Marge estimée » du dashboard.** Le cahier des charges parle de « marge estimée ». Il s'agit en réalité d'un **résultat estimé simplifié** :
`CA − charges fixes − charges variables − coûts de personnel − rémunération du dirigeant (si non incluse)`.
Il est affiché avant impôt, avec la mention « estimation, ne remplace pas votre bilan ». Le libellé à l'écran sera **« Résultat estimé »**.

### Fonctionnel

**B1. Recouvrement entre diagnostic et profil.** Certaines questions du diagnostic portent sur des faits déjà connus, par exemple la part récurrente. Proposition : ces questions sont **préremplies depuis le profil**, avec une indication de provenance. Le diagnostic reste majoritairement qualitatif et le profil reste la source de vérité des chiffres.

**B2. Échelle de réponse et scoring.** Questions à choix unique sur 4 niveaux (0, 33, 67, 100 points). Certaines ont des choix spécifiques : par exemple « Comment fixez-vous vos prix ? » propose 4 réponses graduées. Score de dimension = moyenne pondérée des questions applicables. Score global = moyenne pondérée des dimensions, à poids égaux au MVP. Table de scoring publiée dans la page Méthodologie. ⚠️ Faut-il des poids différents selon les dimensions (par exemple Rentabilité ×1,5) ?

**B3. Diagnostic incomplet.** Les scores ne sont calculés et utilisés par les règles qu'une fois le diagnostic terminé. Un brouillon est conservé indéfiniment.

**C1. Modèles de temps indicatifs.** Les temps proposés par type de prestation (par exemple site vitrine ≈ 35 h) sont des **hypothèses de départ**. Ils sont marqués comme tels, modifiables, et ne sont jamais présentés comme une norme du marché. ⚠️ Les conserver, ou démarrer à vide ?

**C2. Simulation et prestation du catalogue.** Une simulation est rattachée à une prestation du catalogue, ou à « sur mesure ». L'utilisateur désigne un **scénario de référence** : c'est lui que les règles et le dashboard utilisent. Le prix actuel reste celui du catalogue, qui est la source unique.

**D1. Contrats sans CRM.** On saisit un **nombre de contrats actifs par formule**, pas une liste de clients. Cela suffit au MRR sans dériver vers un CRM.

**E1. Cycle de vie des recommandations.** Une règle produit au plus une recommandation active par organisation et par sujet (par exemple une par prestation pour `PRICE_UNDER`). Une réévaluation a lieu après chaque modification de données liée. Statuts :
`nouvelle → acceptée (dans le plan) | ignorée | reportée (date)`, puis `résolue automatiquement` ou `terminée`.
Une recommandation ignorée ne réapparaît pas tant que sa sévérité n'augmente pas.

**E2. Branchement de l'IA plus tard.** Les recommandations sont produites par un `RulesProvider` derrière une interface commune. Plus tard, un `AiEnrichmentProvider` pourra reformuler les explications à partir des mêmes données déclencheuses, sans changer le modèle de données.

**G1. Mode démonstration.** ⚠️ Proposition : un bouton « Essayer la démo » crée une **organisation temporaire** à partir d'un jeu de données de référence. Elle est marquée `is_demo`, porte un bandeau, et est supprimée après 24 h par une tâche cron Hostinger. Chaque visiteur est ainsi isolé, sans compte partagé qu'un visiteur pourrait abîmer.

**G2. Langue et format.** Interface en français uniquement au MVP, avec des textes centralisés pour une traduction future. Format `fr-FR` (1 234,56 €).

**G3. Historique et évolution.** Une table d'**instantanés d'indicateurs** est alimentée à chaque modification significative, au plus un par jour. Sans elle, le graphique « évolution » serait impossible ou inventé.

**G4. Suppression de données.** La suppression du compte efface l'organisation et toutes ses données, par cascade (conformité RGPD de base). Pas de corbeille au MVP.

---

## 7. Arborescence de l'application

### 7.1 Écrans et routes

```
Public
├── /                         Accueil produit (promesse, captures, CTA démo / inscription)
├── /connexion
├── /inscription
├── /mot-de-passe-oublie      (S)
├── /methodologie             Méthode de scoring et formules financières (transparence)
└── /demo                     Crée une organisation de démo puis redirige vers /app

Application (/app — authentification requise)
├── /app                      Vue d'ensemble (dashboard)
├── /app/bienvenue            Onboarding 3 étapes (agence · chiffres · offres)
│
├── /app/diagnostic           Accueil : dernier score, historique, bouton « Commencer / Reprendre »
│   ├── /questionnaire        Étapes 1 à 6 (une dimension par étape)
│   └── /resultats/{id}       Score, radar, forces / faiblesses, recommandations
│
├── /app/pricing              Liste des simulations, filtrable par prestation
│   ├── /nouvelle             Choix de la prestation
│   └── /{id}                 Simulateur (scénarios A / B / C, comparaison)
│
├── /app/recurrent            Synthèse : MRR, ARR contractualisés, répartition par formule
│   ├── /offres               Liste des formules, création et édition (prix, inclus, temps, contrats)
│   └── /scenarios/{id}       Projection sur 6 et 12 mois, comparaison
│
├── /app/strategie            Recommandations (nouvelles · acceptées · ignorées)
│   └── /plan                 Plan d'action (échéances, statuts, progression)
│
└── /app/parametres
    ├── /agence               Identité, activité, équipe, statut
    ├── /finances             Chiffres, hypothèses (heures, facturabilité), coût horaire
    ├── /prestations          Catalogue (prix actuel, volume)
    ├── /compte               Email, mot de passe
    └── /donnees              Export JSON (S), suppression du compte
```

### 7.2 Navigation

- **Desktop (≥ 1024 px)** : sidebar fixe avec Vue d'ensemble, Diagnostic, Pricing, Revenus récurrents, Stratégie, puis Paramètres en bas. En-tête avec le nom de l'agence et le menu du compte.
- **Tablette** : sidebar repliée en icônes, avec libellés au survol.
- **Mobile** : barre d'onglets en bas (5 modules), Paramètres dans le menu du compte. Les tableaux de comparaison deviennent des cartes empilées.

### 7.3 Organisation du code (pressentie, détaillée en phase 2)

Point structurant pour Hostinger : la configuration et le code ne doivent pas être publics. Sur ton hébergement, le site est servi depuis `domains/agency-os.azzurra-pro.com/public_html`. Le dossier parent n'est pas exposé au web.

```
domains/agency-os.azzurra-pro.com/
├── app/              Code PHP (contrôleurs API, services métier, calculs, accès données)  ← non public
├── config/           Configuration et identifiants base de données                         ← non public
├── database/         Migrations et données de démo                                          ← non public
├── tests/
└── public_html/      Seul dossier exposé : index.php (front controller), assets CSS/JS, Chart.js
```

Choix pressenti à confirmer en phase 2 : des **pages HTML servies par PHP** (une par module), enrichies par des modules JavaScript qui appellent l'API JSON. C'est plus simple à sécuriser, plus robuste et mieux référencé qu'une SPA vanilla, sans routeur maison complexe.

---

## 8. Hypothèses techniques et risques identifiés

| Sujet | Hypothèse / risque | Proposition |
|---|---|---|
| PHP 8.2+ | Hostinger permet de choisir la version par site | Vérifier et fixer PHP 8.3 au déploiement |
| Tests | PHPUnit demande Composer (disponible chez Hostinger, utile seulement en dev) | ⚠️ PHPUnit en dépendance de développement uniquement, rien en production |
| Chart.js | CDN ou fichier local | Fichier local versionné (pas de dépendance externe à l'exécution) |
| Purge de la démo | Nécessite une tâche cron | Cron Hostinger quotidien (disponible sur l'offre) |
| Email | Le mot de passe oublié nécessite un SMTP | Boîte email Hostinger, fonction classée S |
| Calculs dupliqués entre client et serveur | Risque de divergence | Une seule bibliothèque de formules en PHP, qui fait foi ; le JS n'affiche qu'un aperçu ; tests de non-régression sur des cas de référence partagés (fichier JSON de cas attendus lu par les deux) |

---

## 9. Décisions à valider avant la phase 2

1. **Périmètre MVP** (§4) : la répartition M / S / W te convient-elle ?
2. **F2** : le CA annuel est celui du dernier exercice ou des 12 derniers mois ; pas de multi-exercices au MVP.
3. **F3** : champ « rémunération souhaitée du dirigeant » intégré au coût horaire.
4. **F5** : charges variables exclues du coût horaire et traitées en frais externes par projet.
5. **F6** : marge cible saisie sur le prix de vente ; vocabulaire figé du tableau F6.
6. **F9** : règle de calcul de la part récurrente (calculée si des contrats sont saisis, sinon déclarée).
7. **B2** : poids égaux entre dimensions, ou pondération (laquelle ?).
8. **C1** : proposer des modèles de temps indicatifs (marqués hypothèse), ou démarrer à vide.
9. **G1** : démo par organisation temporaire individuelle, purgée après 24 h.
10. **Tests** : PHPUnit via Composer en développement uniquement.
11. **Architecture front** : pages servies par PHP et modules JS, plutôt qu'une SPA.

Après validation (ou ajustements), la **phase 2 — Architecture** produira : le schéma MySQL complet, la liste des endpoints REST, le découpage des services métier et la documentation formelle des formules avec des cas de test chiffrés.

---

## 10. Décisions validées (10/11/2026)

Ces décisions **remplacent** les propositions correspondantes du §6 en cas de divergence.

| # | Sujet | Décision |
|---|---|---|
| 1 | Période de référence (F2) | Par défaut : **dernier exercice clos**. Option : **12 derniers mois**. La période (dates de début et de fin) et sa **durée en mois** sont affichées partout où un chiffre en dépend. Un exercice qui ne dure pas 12 mois est annualisé ; la valeur annualisée porte le badge « Calculé » et la valeur déclarée reste visible. |
| 2 | Rémunération du dirigeant (F3) | On saisit son **coût complet annuel, cotisations comprises**, ainsi que le **montant déjà inclus** dans les charges déclarées. Seule la différence est ajoutée, ce qui évite le double comptage. |
| 3 | Charges variables (F5) | Il y a deux catégories. Les **frais directement attribuables à un projet** sont saisis par projet dans le pricing (ils figurent aussi dans le profil, en montant global, pour le résultat). Les **charges variables générales non attribuables** (commissions de paiement, frais bancaires proportionnels, outils facturés à l'usage…) sont converties en **taux sur le CA** et intégrées au prix de chaque prestation. |
| 4 | Marge et majoration (F6) | La formule est validée. Les libellés distinguent toujours **marge sur prix de vente** et **majoration sur coût**. |
| 5 | Part récurrente (F9) | La valeur **déclarée reste la référence**. La part calculée à partir des contrats saisis est affichée **à côté**, comme « couverture par les contrats saisis ». Elle ne remplace jamais automatiquement la valeur déclarée. Seul l'utilisateur peut mettre à jour la valeur déclarée, par une action explicite. |
| 6 | Résultat estimé (F10) | Le libellé « Résultat estimé » est retenu. Le détail de **ce qui est inclus et exclu** est toujours accessible. |
| 7 | Pondération du diagnostic (B2) | Les **six dimensions ont des poids identiques** au MVP. |
| 8 | Modèles de temps (C1) | Le préremplissage est **facultatif**, à la demande, **entièrement modifiable**, et jamais présenté comme une référence universelle. |
| 9 | Démo (G1) | Une organisation temporaire par visiteur, avec **isolation des données**, une **expiration visible** (compte à rebours) et une **suppression fiable** (tâche planifiée, purge de secours, suppression en cascade, vérification par les tests). |
| 10 | Tests | PHPUnit, en dépendance de développement uniquement. |
| 11 | Architecture front | Pages servies par PHP et modules JavaScript ES. |

---

## 11. Décisions d'architecture validées (phase 2, 10/11/2026)

### 11.1 Décisions

| # | Sujet | Décision | Report dans les documents |
|---|---|---|---|
| A1 | Aperçus instantanés | **Validé.** Toutes les formules sont centralisées dans les services PHP du domaine. Temporisation de **350 ms** (fourchette de 300 à 400 ms), `AbortController`, numéro de séquence contre les réponses obsolètes. Les aperçus **n'enregistrent jamais** de données (vérifié par un test). | 02 §1, §3 |
| A2 | Valeurs calculées | **Ajusté.** Les KPI courants et les **brouillons** sont recalculés à la lecture. Les **simulations finalisées**, les **diagnostics terminés** et les **instantanés** conservent les hypothèses et les résultats de leur date de validation (`frozen_context`, `frozen_results`, `calc_version`). Un enregistrement figé n'est jamais modifié : on crée une nouvelle version. | 02 §1, §5.3 à §5.7 ; 03 §0, §4.5 |
| A3 | Questionnaire versionné | **Validé.** Une version par fichier, immuable une fois publiée (questions, options, conditions, politique « Je ne sais pas », scoring), avec des **identifiants stables**, jamais réutilisés. L'empreinte de chaque version est vérifiée par les tests. Un ancien diagnostic s'interprète toujours avec sa propre version. | 02 §5.5 ; 03 §6.4 |
| A4 | Profil financier | **Validé avec ajout.** Un seul profil actif par organisation, accompagné d'un **journal léger** (`financial_profile_changes`) des champs financiers importants. Ce journal sert à tracer les modifications ; les instantanés servent à suivre l'évolution des KPI. | 02 §5.2 |
| A5 | Précision financière | **Corrigé.** `DECIMAL` en MySQL et **BCMath** en PHP, via l'objet de valeur `Decimal`. Aucun flottant dans les calculs monétaires. Précision interne de 12 décimales, arrondi unique en sortie (moitié éloignée de zéro), échelles définies, cas limites testés. BCMath est **vérifié actif** sur le sous-domaine (PHP 8.3.36). | 02 §1, §5, §6, §11 ; 03 §0.1, §0.2 |
| A6 | « Je ne sais pas » | **Corrigé.** Une politique par question : `penalize` (pratique absente, niveau 0) ou `exclude` (inconnu, retiré du calcul). Indicateur de **fiabilité**. Une dimension est calculable avec au moins 3 réponses exploitables **et** au moins 60 % des questions applicables ; le score global n'est affiché que si les 6 dimensions sont calculables. | 02 §5.5 ; 03 §6.2, §6.3 |
| A7 | Rythmes de calcul | **Validé avec distinction.** À la demande pour les aperçus ; **après enregistrement** des données pertinentes pour les recommandations ; **cron quotidien** pour les instantanés (insertion seule, jamais recréés lors d'une modification). | 02 §3, §7.2, §11 |

### 11.2 Contradictions relevées avec les choix précédents, et résolution

| # | Choix précédent | Contradiction | Résolution appliquée |
|---|---|---|---|
| C1 | §3 P3, étape 7 : « à l'enregistrement, le serveur recalcule et fait foi ; une recommandation de prix est créée » | Avec A2, l'enregistrement d'un **brouillon** n'est plus une validation. Déclencher les règles sur les brouillons ferait apparaître et disparaître des recommandations au fil des essais. | Le parcours P3 gagne une étape **« Finaliser »**. Les règles de prix ne lisent que la **dernière simulation finalisée** de chaque prestation. Enregistrer un brouillon ne déclenche aucune réévaluation. ⚠️ **Q1** |
| C2 | 02 §5.3 : `hourly_cost_mode = 'profile'`, c'est-à-dire que le scénario suit le profil automatiquement | Une simulation finalisée ne peut plus suivre le profil. | Ce mode ne vaut que pour les brouillons. À la finalisation, le coût horaire est figé. Un **indicateur de dérive** et la règle `PRICING_DRIFT` invitent à revalider. |
| C3 | §4, Module A : « Historique détaillé des modifications du profil = hors MVP » | A4 demande un journal **dans** le MVP. | Le **journal léger** (champs financiers importants, valeur avant et après, auteur, date) entre au MVP. L'historique **détaillé** (tous les champs, restauration d'une version) reste hors MVP. |
| C4 | §6 G3 et parcours P6 : « un instantané à chaque modification significative, au plus un par jour » ; 02 (première version) : écouteurs d'instantané sur chaque événement | Contraire à A7. | Les instantanés sont **uniquement** produits par le cron quotidien. Conséquences : un nouveau compte n'a pas de courbe d'évolution avant le lendemain (état vide : « L'historique démarre demain ») ; un jour où le cron n'a pas tourné reste un trou visible, non reconstitué. La démo reçoit des instantanés fictifs marqués « Démo ». |
| C5 | §6 F7 (« centimes en entiers ou `DECIMAL`, à trancher ») et 02 décision 6 (flottants double précision) | Remplacés par A5. | BCMath et `DECIMAL`. Conséquence sur l'API : les valeurs décimales sont échangées **en chaînes**, et les taux **en pourcentage**, pour que le front n'effectue aucune opération. |
| C6 | §8, tableau des risques : « fichier JSON de cas attendus lu par PHP **et** JS » | Il n'y a plus de formule en JS (A1). | Les cas de référence ne servent qu'aux tests PHP. |
| C7 | §2 principe « aucune dépendance » et §1.5 | BCMath est une **extension** PHP. | Elle est acceptable : il s'agit d'une extension standard, déjà active sur l'hébergement. Un contrôle au démarrage (`bin/check-requirements.php`) rend le prérequis explicite. Aucune bibliothèque de remplacement n'est embarquée, pour rester simple. |
| C8 | §4, Module F : « Score de maturité » toujours affiché ; 02 décision 7 : « Je ne sais pas » vaut 0 | Avec A6, un diagnostic terminé peut n'avoir **aucun score global**. | Le dashboard, les règles et les instantanés acceptent un score NULL (« Score non disponible : données insuffisantes ») ; la règle `DIAG_LOW_RELIABILITY` est ajoutée. La décision 7 de la version précédente de 02 est annulée. |
| C9 | §5.2, ligne « Prix recommandé d'une prestation » : « scénario de référence de la simulation » | Il faut préciser **quelle version**. | Le scénario de référence de la **dernière version finalisée**. |
| C10 | §3 P4 (projection récurrente) et §5.2 « MRR simulé » | A2 parle de « simulations finalisées » sans préciser s'il s'agit aussi des **scénarios récurrents**. | Par cohérence, le même cycle brouillon, finalisée, remplacée est appliqué aux scénarios récurrents. Les **offres** et leurs **contrats** restent des données vivantes (le MRR contractualisé est recalculé à la lecture). ⚠️ **Q3** |

### 11.3 Questions ouvertes

| # | Question | Proposition |
|---|---|---|
| Q1 | Les règles de prix doivent-elles lire **uniquement les simulations finalisées** ? | Oui. Cela donne des recommandations stables et une intention explicite. En contrepartie, sans finalisation il n'y a pas de recommandation de prix, et `NO_PRICING` invite à finaliser. |
| Q2 | Seuils de calculabilité du diagnostic : au moins 3 réponses exploitables **et** au moins 60 % par dimension ; score global seulement si les **6** dimensions sont calculables ? | Oui. Avec des poids égaux, un global calculé sur 5 dimensions ne serait pas comparable à un global calculé sur 6. |
| Q3 | Appliquer la finalisation aux **scénarios récurrents** ? | Oui, avec le même modèle que le pricing, pour un seul concept à apprendre dans l'interface. |
| Q4 | Heure du cron quotidien | 02:15 (heure de Paris), en dehors des heures d'usage. |
