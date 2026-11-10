# Prototype statique — design system Agency OS

Livrable de la **phase 3 (UX/UI)**. Il sert de validation visuelle et de base réutilisable par le développement (tokens et composants CSS).

| Page | Contenu |
|---|---|
| `index.html` | Design system : couleurs, typographie, natures de données, boutons, champs, indicateurs et états, alertes, dialogue de finalisation, graphiques |
| `vue-ensemble.html` | Vue d'ensemble (démo) : KPI, objectif, priorités avec leurs sources, plan d'action, évolution avec un jour manquant |
| `simulateur.html` | Simulateur de prix (brouillon v4) : hypothèses visibles, marge et majoration distinguées, alertes |
| `diagnostic.html` | Question du diagnostic : étapes, sauvegarde automatique, politique « Je ne sais pas » |

## Consulter

Les icônes sont chargées par `<use href="…sprite.svg#…">`, qui ne fonctionne pas en `file://`. Servir le dossier :

```
cd prototype
python3 -m http.server 8765
# puis ouvrir http://localhost:8765/
```

## Règles respectées

- **Aucune ressource externe** : polices, icônes et Chart.js sont stockés localement.
- **CSP stricte** déclarée dans chaque page (`script-src 'self'`, `style-src 'self'`, sans `unsafe-inline`, avec `require-trusted-types-for 'script'`). Vérifié dans Chromium : aucune violation, aucune erreur de console.
- **Aucun `innerHTML`** ni formule métier en JavaScript : les valeurs affichées sont **statiques et fictives**. Elles reprennent les cas de référence de `docs/03-formules-et-scoring.md` (PR-1, REC-3, DIAG-1…).
- Responsive vérifié à 1 440, 900 et 390 px, sans défilement horizontal.

## Ressources tierces

| Ressource | Version | Licence |
|---|---|---|
| Montserrat (500, 600, 700) et Poppins (400, 500, 600), sous-ensemble latin | via `@fontsource` 5.0 | SIL OFL 1.1 (`assets/fonts/LICENSE-*`) |
| Icônes Lucide (38, en sprite) | 0.441.0 | ISC (`assets/icons/LICENSE-lucide.txt`) |
| Chart.js | 4.4.4 | MIT (`assets/vendor/LICENSE-chartjs.md`) |
