# Agency OS — Phase 3 : UX/UI

> Statut : **proposition, en attente de validation**.
> Contraintes héritées :
> - HTML, CSS et JavaScript natifs, sans framework ;
> - **CSP stricte**, sans style ni script en ligne (04 §5) ;
> - nature des données toujours visible (05 §1) ;
> - profil **portfolio** : démo avec des données fictives uniquement (06) ;
> - accessibilité **WCAG 2.2 AA**.

---

## 1. Principes d'expérience

1. **Une question par écran.** Chaque écran répond à une question de dirigeant : « Où en suis-je ? » (Vue d'ensemble), « Mes prix couvrent-ils mes coûts ? » (Pricing), « Mon récurrent est-il rentable ? », « Que faire en priorité ? » (Stratégie).
2. **Hiérarchie visuelle en trois niveaux** : un indicateur principal, 3 ou 4 indicateurs secondaires, puis le détail à la demande. Pas plus de **6 cartes KPI** visibles sur un écran.
3. **La pédagogie est intégrée.** Chaque indicateur a une icône (i) qui ouvre sa définition, sa formule lisible et sa source. Pas de glossaire séparé à aller chercher.
4. **On voit toujours d'où vient un chiffre** : badge de nature (déclaré, hypothèse, simulé, calculé, figé) et, pour les recommandations, la ligne « Repose sur ».
5. **Jamais de 0 trompeur.** Une donnée absente produit un **état vide explicatif** avec une action (« Renseignez vos charges pour calculer le résultat »).
6. **Des formulaires courts et progressifs** : 5 à 7 champs par étape, valeurs par défaut explicites (badge « Hypothèse »), champs avancés repliés.
7. **Sobriété** : pas de dégradés, ni d'animations décoratives, ni d'illustrations gadgets. La couleur sert au sens (accent, statuts, natures), jamais à la décoration.
8. **Démo honnête** : l'espace est clairement identifié, avec des données fictives préremplies, et rien n'invite à saisir de vraies données.

---

## 2. Design system : les tokens

Tous les tokens sont des **variables CSS** (`assets/css/tokens.css`). Les composants n'utilisent **jamais** de valeur en dur.

### 2.1 Couleurs (contrastes vérifiés)

| Token | Valeur | Usage | Contraste |
|---|---|---|---|
| `--color-bg` | `#F7F7F9` | fond de l'application (gris très clair, légèrement froid) | — |
| `--color-surface` | `#FFFFFF` | cartes, panneaux, champs | — |
| `--color-surface-sunken` | `#F1F1F4` | zones secondaires, en-têtes de tableau | — |
| `--color-border` | `#E4E4E9` | séparateurs, contours de cartes (décoratifs) | — |
| `--color-border-strong` | `#8C8C99` | contours des **champs de formulaire** | 3,3:1 sur le blanc ✅ (minimum 3:1 pour les éléments d'interface) |
| `--color-text` | `#17171C` | texte principal | 16,7:1 ✅ |
| `--color-text-secondary` | `#52525E` | libellés, texte d'aide | 7,7:1 ✅ |
| `--color-text-tertiary` | `#71717D` | métadonnées, dates | 4,8:1 ✅ |
| `--color-accent` | `#4F46E5` | **indigo** : actions principales, lien actif, focus | 6,3:1 ✅ ; texte blanc sur l'accent 6,3:1 ✅ |
| `--color-accent-hover` | `#4338CA` | survol | — |
| `--color-accent-subtle` | `#EEF0FF` | fond de l'élément de navigation actif, sélection | texte `#4338CA` à 7,0:1 ✅ |
| `--color-success` | `#15803D` | atteint, rentable | 5,0:1 ✅ |
| `--color-warning` | `#A16207` | attention, données anciennes | 4,9:1 ✅ |
| `--color-danger` | `#C42B2B` | perte, sous le prix plancher, erreurs | 5,6:1 ✅ |
| `--color-info` | `#1D4ED8` | information | 6,7:1 ✅ |

Couleurs des **natures de données** : chaque badge combine toujours **une icône et un libellé**, jamais la couleur seule.

| Nature | Texte sur fond | Icône | Contraste |
|---|---|---|---|
| Déclaré | `#17171C` sur `#F1F1F4` | crayon | 15:1 ✅ |
| Hypothèse | `#92400E` sur `#FEF6E7` | ampoule | 6,6:1 ✅ |
| Simulé | `#5B21B6` sur `#F3EEFF` | courbe en pointillés | 7,9:1 ✅ |
| Calculé | `#1E40AF` sur `#EAF1FF` | fonction | 7,7:1 ✅ |
| Figé | `#334155` sur `#EEF1F5` | cadenas | 9,1:1 ✅ |
| Démo | `#92400E` sur `#FEF6E7`, avec un bandeau dédié | drapeau | 6,6:1 ✅ |

Mode sombre : **hors MVP**. Les tokens sont prévus pour l'ajouter par simple redéfinition des variables.

### 2.2 Typographie

- **Inter** (licence SIL OFL), **auto-hébergée** en police variable WOFF2 avec sous-ensemble latin. Pas de Google Fonts (CSP et RGPD). Repli : `system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`.
- **Chiffres tabulaires** (`font-variant-numeric: tabular-nums`) dans tous les montants, tableaux et KPI, pour que les colonnes restent alignées.
- Échelle (taille / interligne / graisse) :

| Token | Usage | Taille | Interligne | Graisse |
|---|---|---|---|---|
| `--text-display` | valeur du KPI principal | 36 px | 40 | 600 |
| `--text-h1` | titre de page | 24 px | 32 | 600 |
| `--text-h2` | titre de section | 18 px | 26 | 600 |
| `--text-h3` | titre de carte | 15 px | 22 | 600 |
| `--text-body` | texte courant, champs | 14 px | 22 | 400 |
| `--text-small` | aide, métadonnées | 13 px | 18 | 400 |
| `--text-micro` | badges, étiquettes | 12 px | 16 | 500, avec un léger espacement des lettres |

- Sur mobile, le corps de texte passe à **16 px** dans les champs, pour éviter le zoom automatique d'iOS.

### 2.3 Espacements, formes et élévation

- **Grille de 4 px** : `--space-1` = 4 px, puis 8, 12, 16, 20, 24, 32, 40, 48 et 64 px (`--space-10`).
- Rayons : `--radius-sm` 6 px (badges, petits boutons), `--radius-md` 8 px (champs, boutons), `--radius-lg` 12 px (cartes, modales).
- Bordures : 1 px partout.
- Ombres, très légères, sur deux niveaux seulement :
  - `--shadow-1: 0 1px 2px rgba(23,23,28,.05)` pour les cartes ;
  - `--shadow-2: 0 8px 24px rgba(23,23,28,.08)` pour les menus, modales et infobulles.
- Focus : `--focus-ring: 0 0 0 2px #FFFFFF, 0 0 0 4px #4F46E5`, toujours visible au clavier (`:focus-visible`).

### 2.4 Mise en page et points de rupture

| Point de rupture | Mise en page |
|---|---|
| < 768 px (mobile) | une colonne, gouttière de 16 px, **barre d'onglets en bas** (5 modules), en-tête compact |
| 768 à 1023 px (tablette) | **sidebar repliée en icônes** (64 px), contenu sur 8 colonnes |
| ≥ 1024 px (desktop) | **sidebar de 240 px**, contenu sur 12 colonnes, gouttière de 24 px, largeur maximale de 1 200 px |
| ≥ 1440 px | marges latérales élargies, mais la largeur du contenu reste plafonnée (lisibilité) |

Tactile : cibles d'au moins **44 × 44 px** sur mobile, et 24 × 24 px au minimum ailleurs (WCAG 2.5.8).

### 2.5 Icônes

Un sous-ensemble d'environ 40 icônes de **Lucide** (licence ISC), copiées en **sprite SVG local** (`assets/icons/sprite.svg`, utilisé avec `<use>`). Trait de 1,5 px, tailles de 16 et 20 px. C'est un fichier statique, sans bibliothèque JavaScript. L'intérêt est d'avoir un style cohérent et maintenu, sans dessiner chaque icône.

### 2.6 Mouvement

Transitions de 150 à 200 ms (`ease-out`), limitées à l'opacité et aux déplacements de menus et de modales. **`prefers-reduced-motion`** respecté : les transitions sont supprimées. Aucune animation sur les chiffres (pas de compteurs qui défilent).

### 2.7 Graphiques (thème Chart.js commun, `charts/theme.js`)

- Séries : l'accent `#4F46E5`, puis `#0F766E` (sarcelle), `#B45309` (ambre), `#64748B` (ardoise) et `#BE185D` (framboise). **Cinq séries au maximum**, différenciées aussi par la forme des marqueurs.
- Natures (05 §1.2) : **trait plein** pour le réel et le calculé, **pointillés** pour le simulé, marqueurs creux pour le figé, **trous** pour les jours manquants (aucune liaison entre les points voisins). La légende rappelle ces conventions.
- Grille gris très clair, axes discrets, police Inter, infobulles au format fr-FR (`3 970,59 €`).
- **Alternative accessible** : chaque graphique a un bouton « Voir les données » qui affiche un tableau, et un texte alternatif qui résume la tendance.
- Radar du diagnostic : 6 axes, échelle de 0 à 100. Un axe non calculable est **grisé et hachuré**, avec la mention « Données insuffisantes » ; il n'est jamais tracé à 0.

### 2.8 Formats

| Type | Format |
|---|---|
| Montants | `3 970,59 €` (espace fine insécable) ; dans les KPI, `180 k€` avec la valeur exacte dans l'infobulle |
| Taux | `13,6 %` |
| Heures | `40 h` ou `1,5 h` |
| Dates | `12 nov. 2026` |
| Périodes | « Exercice clos · 01/01/2025 → 31/12/2025 · 12 mois » |
| Montants négatifs | signe moins **et** couleur `--color-danger`, jamais la couleur seule |
| Écarts | `▲ +12,4 %` ou `▼ −19,4 %`, avec flèche et signe |

---

## 3. Composants

Chaque composant est un **module JavaScript** (`assets/js/ui/*.js`) qui crée des nœuds DOM, associé à une **feuille CSS** (`assets/css/components/*.css`). Il n'utilise **jamais `innerHTML`** (04 §5). Les valeurs dynamiques de largeur (barres de progression) passent par des propriétés CSS personnalisées définies via `element.style.setProperty()` (manipulation CSSOM, compatible avec la CSP) ou par les éléments natifs `<progress>` et `<meter>`.

### 3.1 Fondations

| Composant | Variantes | États |
|---|---|---|
| **Button** | primary (accent plein), secondary (contour), ghost, danger ; tailles sm, md, lg ; icône seule (avec `aria-label`) | repos, survol, focus, actif, **chargement** (indicateur et `aria-busy`, largeur conservée), désactivé |
| **Link** | dans le texte, de navigation | visité, focus |
| **Field** (libellé, champ, aide, erreur) | texte, email, mot de passe (afficher ou masquer), **décimal avec unité** (€, %, h, suffixe visible), date, zone de texte | repos, focus, **erreur** (bordure danger, message relié par `aria-describedby`, icône), désactivé, **lecture seule** (version figée), **prérempli par une hypothèse** (badge) |
| **Select**, **RadioGroup**, **Checkbox**, **Switch** | natifs et stylés | idem |
| **RadioCard** | grande carte cliquable (réponses du diagnostic, choix d'une prestation) | sélectionnée, focus, désactivée |
| **Badge** | neutre, statut, priorité | — |
| **NatureBadge** | déclaré, hypothèse, simulé, calculé, figé, démo ; option « avec hypothèses » qui ouvre la liste au clic | — |
| **Tooltip** et **Popover** | définition d'un indicateur, formule, source | ouverture au survol, au focus et au clic (tactile), fermeture avec Échap |

### 3.2 Données et pilotage

| Composant | Description |
|---|---|
| **KpiCard** | libellé, valeur (`--text-display` ou h2), unité, `NatureBadge`, variation (flèche et signe), période de référence, bouton (i) d'explication. **État vide** avec message et action. **État partiel** (« calculé avec 2 hypothèses ») |
| **FormulaExplainer** | panneau « Comment est-ce calculé ? » : formule en langage clair, valeurs utilisées avec leur nature, lien vers la méthodologie |
| **EvidenceBlock** (« Repose sur ») | liste compacte des sources d'une recommandation (nature, date, version), avec alerte « incomplet » ou « ancien » |
| **DataQualityAlert** | encadré d'attention : données incomplètes, anciennes ou en dérive, avec une action de mise à jour |
| **ProgressBar** et **Meter** | progression (plan, diagnostic), jauge (score, objectif de CA) avec texte accessible |
| **ScoreRing** | score global du diagnostic (0 à 100) avec niveau et fiabilité. Version « Diagnostic partiel » : anneau en pointillés, sans chiffre global |
| **ScenarioColumns** | comparaison de 1 à 3 scénarios côte à côte ; écarts mis en évidence ; référence marquée d'une étoile. Empilés sur mobile, avec onglets |
| **ResultLine** | ligne libellé, valeur et nature dans les panneaux de résultats (coût de revient, prix plancher…) |
| **RecommendationCard** | priorité, titre, explication courte, `EvidenceBlock`, impact (chiffré seulement s'il est justifiable), difficulté, modules concernés, actions (Accepter, Reporter, Ignorer) |
| **ActionItem** | case, titre, échéance (en retard : rouge et icône), statut, sous-actions dépliables, progression |
| **VersionHistory** | liste des versions d'une simulation ou d'un scénario (active, remplacée, archivée) avec date de gel et action « Consulter » |
| **Chart** | enveloppe de Chart.js : thème, alternative en tableau, état vide, trous |
| **DataTable** | tri, en-têtes figés ; **devient une liste de cartes** sous 768 px |

### 3.3 Navigation et structure

| Composant | Description |
|---|---|
| **Sidebar** | logo, 5 modules (Vue d'ensemble, Diagnostic, Pricing, Revenus récurrents, Stratégie), Paramètres en bas, sélecteur d'organisation (préparé pour le multi-organisation) ; repliable |
| **TabBar** (mobile) | 5 icônes avec libellés ; Paramètres accessible par le menu du compte |
| **PageHeader** | titre, sous-titre (question à laquelle répond l'écran), actions principales, fil d'Ariane sur les sous-pages |
| **Tabs** | sous-sections (Offres / Scénarios ; Recommandations / Plan d'action) |
| **Stepper** | étapes de l'onboarding (3) et du diagnostic (6), avec progression et état « enregistré » |
| **DemoBanner** | bandeau permanent en haut de page : « Démonstration · données fictives · expire dans 23 h 12 », avec les boutons **Réinitialiser** et **Quitter la démo** ; non masquable |
| **AccountMenu** | profil, sécurité, déconnexion |

### 3.4 Retours et dialogues

| Composant | Usage |
|---|---|
| **Toast** | confirmation non bloquante (« Simulation enregistrée »), en `aria-live="polite"`, 5 s, fermable |
| **InlineStatus** | « Enregistré », « Enregistrement… », « Calcul… » à côté d'un formulaire (sauvegarde automatique, aperçu) |
| **Dialog** | confirmation d'une action destructive, avec un bouton danger **à droite**, nommé par son action (« Supprimer le brouillon ») et jamais « OK » |
| **FinalizeDialog** | explique le gel : « Cette simulation deviendra une version figée et ne pourra plus être modifiée. Elle servira de référence pour la stratégie de *Site vitrine*. » Récapitulatif des hypothèses figées |
| **ReauthDialog** | saisie du mot de passe pour les actions sensibles |
| **SessionTimeoutDialog** | « Votre session expire dans 5 minutes », avec les boutons Rester connecté et Se déconnecter |
| **EmptyState** | icône, titre, une phrase d'explication et **une** action principale |
| **ErrorState** | message clair, référence d'incident, bouton Réessayer ; jamais de détail technique |
| **Skeleton** | blocs gris animés (désactivés si l'utilisateur limite les animations) pendant les chargements de plus de 300 ms |

---

## 4. Écrans

Notation des maquettes : `[ ]` bouton, `( )` badge, `▢` carte, `···` contenu.

### 4.1 Public

**Accueil `/`** : promesse en une phrase, 3 bénéfices (rentabilité, récurrent, plan d'action), une capture du tableau de bord **marquée « données fictives »**, bouton principal **[Explorer la démo]**, liens Méthodologie et Code source (portfolio). En profil portfolio, pas de bouton « Créer un compte ».

**Connexion `/connexion`** : email et mot de passe, lien « Mot de passe oublié ». En profil portfolio, une mention précise : « Comptes de test uniquement. Pour découvrir Agency OS, utilisez la démo. » Les erreurs restent génériques.

**Inscription `/inscription`** : en profil portfolio, la page explique que l'inscription est désactivée sur cette démonstration et propose **[Explorer la démo]**.

**Vérification de l'email, mot de passe oublié, réinitialisation** : un seul champ par écran, messages identiques quel que soit l'email, page de confirmation.

**Méthodologie `/methodologie`** : formules, scoring et politique « Je ne sais pas », avec des exemples chiffrés (03).

**Erreurs** : 404 (« Cette page n'existe pas ou n'est pas accessible »), 500 (avec référence), session expirée, démo expirée (« Cette démonstration a expiré et ses données ont été supprimées », avec **[Nouvelle démo]**), maintenance.

### 4.2 Vue d'ensemble `/app`

```
┌ Démonstration · données fictives · expire dans 23 h 12 ─────────── [Réinitialiser] [Quitter] ┐
│ ☰ Agency OS   Vue d'ensemble                                Exercice clos 2025 · 12 mois ▾ │
├──────────┬────────────────────────────────────────────────────────────────────────────────────┤
│ ◉ Vue    │ Où en est votre agence ?                                                           │
│ ○ Diag.  │ ▢ Résultat estimé ─────────────────────┐ ▢ Maturité ──────┐ ▢ MRR contractualisé ┐ │
│ ○ Pricing│ │ 24 400 €  (Calculé)              (i) │ │  58 /100   (Figé)│ │ 2 300 €/mois (Calc.)│ │
│ ○ Récur. │ │ 13,6 % du CA · 180 k€ de CA          │ │ En construction  │ │ 15,3 % du CA (contr.)│ │
│ ○ Strat. │ └──────────────────────────────────────┘ └──────────────────┘ │ part déclarée 25 %  │ │
│          │ ▢ Objectif de CA 2026 : 250 k€ ███████████░░░░ 72 %  écart 70 k€          (Déclaré)  │
│          │ ▢ Priorités ─────────────────────────────────┐ ▢ Plan d'action ───────────────────┐ │
│          │ │ ● Haute  Revoir le prix « Site vitrine »    │ │ 3 / 7 actions · 43 %  ███░░░░     │ │
│          │ │   Repose sur : simulation v3 · profil 2025  │ │ Prochaine : « Packager l'offre…»  │ │
│          │ │ ● Haute  Structurer une offre de maintenance│ │ 15 nov. · 1 en retard (!)         │ │
│          │ │ ● Moy.   Formaliser le processus commercial │ └───────────────────────────────────┘ │
│          │ └─────────────────────── [Voir la stratégie]──┘                                      │
│          │ ▢ Évolution (30 j) ── résultat estimé ── MRR ── · · jours manquants signalés        │
│ ⚙ Param. │ ▢ Bien démarrer : ✓ Profil 92 %  ✓ Diagnostic  ○ Finaliser une simulation  ○ …       │
└──────────┴────────────────────────────────────────────────────────────────────────────────────┘
```

- **Hiérarchie** : la ligne 1 montre la santé (résultat, maturité, récurrent), la ligne 2 l'objectif, la ligne 3 l'action (priorités et plan), la ligne 4 la tendance.
- Les charges et le CA restent accessibles dans l'infobulle et le détail du résultat estimé, pour ne pas multiplier les cartes.
- **États** : profil vide (cartes en état vide et checklist mise en avant), diagnostic absent (« Faites le diagnostic en 10 minutes »), diagnostic partiel (anneau en pointillés, « Diagnostic partiel : 5/6 dimensions »), historique vide (« L'historique commence demain » ; en démo, des instantanés fictifs marqués « Démo »).
- **Mobile** : les cartes sont empilées dans le même ordre ; le graphique passe à 7 jours avec un bouton « Voir 30 jours ».

### 4.3 Onboarding `/app/bienvenue` (3 étapes passables)

1. **Votre agence** : nom, activité (cartes), taille de l'équipe, statut juridique.
2. **Vos chiffres** :
   - choix de la période (« Dernier exercice clos » par défaut, ou « 12 derniers mois »), dates et **durée affichée** ;
   - CA, charges fixes, personnel, charges variables (deux champs, avec une aide qui explique la différence) ;
   - rémunération du dirigeant (coût complet et part déjà incluse, avec une infobulle d'exemple) ;
   - ETP de production ;
   - section « Hypothèses de production » repliée (1 607 h, 65 %, avec badges Hypothèse) ;
   - objectif, part récurrente.
3. **Vos prestations** : cartes à cocher, avec prix et volume facultatifs.

En démo, tout est **prérempli avec l'agence fictive « Lumen »**, avec la mention : « Valeurs fictives de démonstration. N'indiquez pas les chiffres réels de votre entreprise. »

À la fin : écran de synthèse « Voici ce que nous avons calculé », avec le coût horaire, le résultat estimé et la complétude, puis **[Aller à la vue d'ensemble]**.

### 4.4 Diagnostic

- **Accueil** `/app/diagnostic` : dernier résultat (score, ou « Diagnostic partiel »), date, historique, bouton **[Commencer]** ou **[Reprendre (3/6)]**, durée estimée, lien vers la méthodologie.
- **Questionnaire** `/app/diagnostic/questionnaire` :

```
 Rentabilité et pilotage financier                       Étape 3 / 6   ██████░░░░  ✓ Enregistré
 ────────────────────────────────────────────────────────────────────────────────────────────
 Comment fixez-vous le prix de vos prestations ?
 ▢ Au feeling ou en fonction des concurrents
 ▢ À partir du temps estimé, sans coût horaire calculé
 ▣ À partir du temps et d'un coût horaire calculé              ← RadioCard sélectionnée
 ▢ Coût complet, marge cible et suivi de la rentabilité réelle
 ▢ Je ne sais pas            ⓘ Réponse inconnue : elle ne compte pas dans le score
 ────────────────────────────────────────────────────────────────────────────────────────────
 [← Précédent]                                                         [Question suivante →]
```

  - Une question visible à la fois sur mobile ; une dimension par page sur desktop (5 questions).
  - Clavier : flèches pour les choix, Entrée pour passer à la suite.
  - Sous « Je ne sais pas », une note explique la politique de la question : « inconnue » ou, si elle est **évaluable**, « comptée comme pratique absente ».
  - Les réponses préremplies depuis le profil portent la mention « Issu de votre profil, modifiable ».
- **Résultats** `/app/diagnostic/resultats/{id}` : `ScoreRing` (ou **Diagnostic partiel**), fiabilité, radar, forces et axes d'amélioration (2 et 2), détail par dimension (score, réponses exploitables, inconnues), recommandations liées, comparaison avec le précédent si elle est possible (« méthode différente » si la version change). Si le diagnostic est partiel, l'encadré « Informations manquantes » liste les questions concernées, avec **[Compléter ces réponses]**.

### 4.5 Pricing

- **Liste** `/app/pricing` : une ligne par prestation, avec la simulation **active** (version, date de gel, prix recommandé figé, écart avec le prix actuel) et les brouillons en cours. Filtres : tous, actifs, brouillons, archivés. État vide : « Créez votre première simulation à partir d'une prestation ».
- **Simulateur** `/app/pricing/{id}` (brouillon) :

```
 Site vitrine · Brouillon (v4)     (Brouillon : sans effet sur la stratégie)   [Dupliquer] [Finaliser]
 ┌ Paramètres ─────────────────────────────┐ ┌ Résultats ─────────────────── Calcul… ┐
 │ Coût horaire  60,00 €/h (Calculé, 1 hyp.)│ │ Coût de revient        2 764,00 €      │
 │   ○ Suivre le profil ○ Personnalisé      │ │ Prix plancher          2 755,10 €      │
 │ Temps                                    │ │ Prix recommandé        3 970,59 € ★    │
 │  Cadrage ........ 4 h      [+ ligne]     │ │ ─────────────────────────────────────  │
 │  Développement .. 40 h                   │ │ Au prix actuel 3 200 € (Déclaré)       │
 │  [Préremplir avec un modèle indicatif]   │ │ Résultat               436,00 €        │
 │ Frais directs                            │ │ Marge sur prix de vente 13,6 %  (i)    │
 │  Thème premium .. 300 €                  │ │ Majoration sur coût     15,8 %  (i)    │
 │ Aléas 0 %   Marge cible 30 % (Hypothèse) │ │ ⚠ 19,4 % sous le prix recommandé       │
 │ Prix testé  [ 3 200 € ]                  │ │ [Comment est-ce calculé ?]             │
 └──────────────────────────────────────────┘ └────────────────────────────────────────┘
 Scénarios :  [A ★]  [B]  [+ Scénario]      [Comparer]
```

  - Les résultats se mettent à jour pendant la saisie (aperçu serveur, 350 ms), avec l'indicateur « Calcul… » et l'annonce `aria-live` des résultats principaux uniquement (pour ne pas saturer les lecteurs d'écran).
  - Les **alertes locales** (sous le plancher, marge inférieure à la cible) s'affichent dans le panneau de résultats.
  - Le badge « Brouillon : sans effet sur la stratégie » est permanent.
- **Version figée** : même mise en page en **lecture seule**, bandeau « Version figée le 12/11/2026 · v3 · active pour Site vitrine », hypothèses figées affichées avec leur nature d'origine, alerte de dérive le cas échéant, actions **[Nouvelle version]** et **[Archiver]**, et **historique des versions**.
- **Comparaison** : `ScenarioColumns`, avec les écarts mis en évidence.
- **Mobile** : les paramètres puis les résultats, avec un **résumé fixe en bas** (prix recommandé et marge) qui reste visible pendant la saisie.

### 4.6 Revenus récurrents

- **Synthèse** `/app/recurrent` : séparation **visuellement stricte** en deux zones.
  - **« Réel : contrats signés »** : MRR et ARR contractualisés, couverture du CA comparée à la part déclarée (avec un écart signalé si nécessaire), répartition par formule (barres empilées), capacité utilisée.
  - **« Prévisionnel : scénarios »** : scénarios finalisés et brouillons, chacun **portant le badge Simulé**, sans aucun total commun avec le réel.
- **Offres** : liste des formules (prix, temps, marge par contrat, contrats signés) ; la modification du nombre de contrats se fait sur place, avec « mis à jour le … ».
- **Scénario** : paramètres par formule (départ, nouveaux clients par mois, churn) ; projection sur 6 ou 12 mois en courbe **pointillée** ; tableau mensuel ; capacité. Une version figée est en lecture seule, avec **[Dupliquer pour modifier]**.

### 4.7 Stratégie

- **Recommandations** `/app/strategie` : onglets Nouvelles, Acceptées, Reportées, Ignorées, Résolues ; tri par priorité ; `RecommendationCard` avec `EvidenceBlock` et `DataQualityAlert` ; filtre par module.
- **Plan d'action** `/app/strategie/plan` : progression globale, liste groupée par statut (à faire, en cours, terminé), échéances, retards, sous-actions ; ajout d'une action libre. Une recommandation résolue automatiquement affiche « Objectif atteint » sur l'action liée.

### 4.8 Paramètres

Agence · Finances (avec **Historique des modifications**) · Prestations · Compte · **Sécurité** (dernières connexions, déconnexion de tous les appareils) · **Données** (export, suppression du compte avec les étapes D8). En démo, Compte, Sécurité et Données affichent « Non disponible en démonstration ».

---

## 5. Interactions et états transverses

| Situation | Comportement |
|---|---|
| Chargement de moins de 300 ms | rien (évite le clignotement) |
| Chargement de plus de 300 ms | squelette de la zone concernée, sans spinner plein écran |
| Aperçu en cours | dernière valeur conservée et « Calcul… » discret ; une réponse obsolète est ignorée |
| Sauvegarde automatique (diagnostic) | « Enregistrement… » puis « ✓ Enregistré à 10:42 » ; en cas d'échec, « Non enregistré, nouvelle tentative » avec trois essais, puis un bouton |
| Validation | au moment de quitter le champ et à l'envoi ; erreurs du serveur (422) placées sous les bons champs ; résumé des erreurs en haut du formulaire, avec des liens d'ancrage |
| Erreur réseau | message en ligne avec **[Réessayer]** ; les saisies sont conservées |
| 409 « version figée » | dialogue « Cette version est figée » avec **[Créer une nouvelle version]** |
| 429 | « Trop de requêtes : réessayez dans X secondes » |
| Session bientôt expirée | dialogue 5 minutes avant ; à l'expiration, redirection vers la connexion avec le message « Session expirée » ; les saisies non enregistrées sont signalées |
| Démo bientôt expirée | bandeau orange à moins d'1 h, puis écran « Démonstration expirée » |
| Action destructive | dialogue explicite ; suppression définitive seulement pour les brouillons |
| Succès | toast et mise à jour sur place ; pas de rechargement de page |

---

## 6. Rédaction et ton

- **Vouvoiement**, phrases courtes, vocabulaire de dirigeant. Les termes financiers sont toujours définis dans une infobulle (marge sur prix de vente, majoration sur coût, MRR, coût complet, churn).
- Titres de page formulés comme des **questions** ; boutons formulés par **verbe et objet** (« Finaliser la simulation »).
- Messages d'erreur sur le modèle : **ce qui s'est passé**, puis **comment corriger** (« Le taux de marge et le taux de charges variables dépassent 100 % : réduisez la marge cible »).
- Aucune promesse chiffrée sans fondement ; « estimation » et « indicatif » sont employés lorsque c'est le cas.
- Libellés et textes centralisés (`app/Lang/fr.php` et `assets/js/core/i18n.js`), pour préparer la traduction.

---

## 7. Accessibilité (WCAG 2.2 AA)

- Contrastes vérifiés (§2.1) ; information jamais transmise par la couleur seule (icônes, signes, libellés).
- Navigation complète au clavier, ordre logique, **lien d'évitement** « Aller au contenu », focus visible, piège du focus dans les modales et retour du focus à la fermeture.
- Sémantique native d'abord (`button`, `nav`, `main`, `fieldset`/`legend` pour les questions, `table` pour les données). ARIA seulement si nécessaire (onglets, `aria-live`, `aria-invalid`, `aria-describedby`).
- Graphiques : alternative en tableau et résumé textuel.
- Respect de `prefers-reduced-motion` ; agrandissement à 200 % sans perte ; affichage correct à 320 px de large.
- Vérifications prévues : axe-core (outil de développement, en local), navigation au clavier et lecteur d'écran (VoiceOver et NVDA) sur les parcours principaux.

---

## 8. Spécificités de la démo (profil portfolio)

- `DemoBanner` permanent, avec compte à rebours, **[Réinitialiser]** et **[Quitter la démo]**.
- Tous les formulaires financiers sont **préremplis avec l'agence fictive** et portent la mention « N'indiquez pas les chiffres réels de votre entreprise ».
- Aucun appel à l'action ne propose de « saisir vos vrais chiffres ». Les écrans d'accueil invitent à **explorer** et à **modifier les hypothèses fictives**.
- Fonctions non disponibles (export, compte, emails) : elles sont visibles mais désactivées, avec une infobulle « Non disponible en démonstration », pour que le recruteur voie qu'elles existent.
- Filigrane « Données fictives » sur les impressions et les captures (`@media print`).

---

## 9. Décisions à valider

1. **Palette** : fond `#F7F7F9`, accent **indigo `#4F46E5`**, couleurs de statut et de nature du §2.1.
2. **Typographie** : Inter auto-hébergée, chiffres tabulaires, corps de 14 px sur desktop et 16 px dans les champs sur mobile.
3. **Icônes** : sous-ensemble de Lucide en sprite SVG local (fichier statique).
4. **Vue d'ensemble** : 3 KPI principaux (résultat estimé, maturité, MRR contractualisé), puis l'objectif, les priorités, le plan et l'évolution. **Le CA et les charges ne sont pas des cartes à part** : ils apparaissent dans le détail du résultat.
5. **Revenus récurrents** : séparation visuelle stricte entre « Réel » et « Prévisionnel ».
6. **Mode sombre** hors MVP.
7. **Livrable suivant de la phase 3** : un **prototype statique du design system** en HTML et CSS (tokens, composants et 3 écrans clés : Vue d'ensemble, Simulateur, Question du diagnostic), avec des données fictives. Il servirait de validation visuelle et de base réutilisable par le développement.
