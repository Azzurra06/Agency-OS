# Agency OS — Questionnaire de diagnostic, version 1

> Statut : **proposition, à valider avant le développement du lot 2**.
> Règles de calcul : [03-formules-et-scoring.md §6](03-formules-et-scoring.md). Une fois publiée, cette version est **immuable** : les codes et les niveaux ne changent plus ; toute modification de fond crée une version 2.

---

## Conventions

- **6 dimensions × 5 questions**, à poids égaux. Durée estimée : environ 10 minutes.
- Chaque question propose **4 réponses graduées**, du niveau 0 (pratique absente) au niveau 3 (pratique maîtrisée), plus **« Je ne sais pas »**.
- **Politique « Je ne sais pas »** (décision Q2) :
  - **inconnue** (valeur par défaut) : la réponse est retirée du calcul et réduit la fiabilité ;
  - **évaluable** (déclarée explicitement) : elle compte comme pratique absente (niveau 0), parce que ne pas savoir **est** le manque de maturité mesuré.
- **Identifiants stables** : `dimension.question` pour les questions, et `n0` à `n3` pour les réponses. Le libellé peut recevoir une correction typographique sans changer de sens ; un changement de fond entraîne une nouvelle version.
- **Score d'une dimension** = 100 × somme des niveaux ÷ (3 × nombre de questions prises en compte). Une dimension est **calculable** avec au moins 3 réponses exploitables représentant au moins 60 % de ses questions applicables. Le **score global** n'est affiché que si les 6 dimensions sont calculables.
- Rappel affiché au début et à la fin : « Autoévaluation indicative. Ce n'est ni une certification, ni un diagnostic financier officiel. »

Légende : **[É]** = « Je ne sais pas » évaluable (niveau 0) · **[I]** = « Je ne sais pas » inconnu (exclu du calcul).

---

## Dimension 1 — Positionnement et offres (`pos`)

| Code | Question | Niveau 0 | Niveau 1 | Niveau 2 | Niveau 3 | JNSP |
|---|---|---|---|---|---|---|
| `pos.target` | Votre clientèle cible est-elle clairement définie ? | Nous travaillons pour tout type de client | Nous en avons une idée générale, sans l'avoir formalisée | Elle est écrite (taille, secteur, besoin) mais guide peu nos choix | Elle est écrite et guide notre prospection, nos offres et les projets que nous refusons | [É] |
| `pos.specialisation` | Votre agence est-elle spécialisée ? | Généraliste, tout type de projet | Une dominante technique (par exemple WordPress), sans spécialisation métier | Spécialisée sur une expertise **ou** un secteur | Spécialisée et reconnue comme telle (références, contenus, recommandations) | [É] |
| `pos.packaged_offers` | Vos offres sont-elles packagées ? | Tout est sur devis, au cas par cas | Quelques prestations ont un prix indicatif | Nos prestations principales ont un contenu, un prix et un délai définis | Une gamme cohérente, avec options et montée en gamme | [É] |
| `pos.differentiation` | Pouvez-vous dire en une phrase pourquoi un client vous choisirait plutôt qu'un concurrent ? | Non | Oui, mais l'argument est générique (qualité, réactivité…) | Oui, un argument spécifique, mais peu mis en avant | Oui, un argument spécifique, prouvé et présent dans toute notre communication | [É] |
| `pos.portfolio_fit` | Vos réalisations présentées correspondent-elles à la cible et aux offres que vous voulez développer ? | Pas de portfolio à jour | Portfolio à jour mais hétérogène | Majoritairement aligné | Aligné, avec des études de cas chiffrées | [É] |

## Dimension 2 — Acquisition et développement commercial (`acq`)

| Code | Question | Niveau 0 | Niveau 1 | Niveau 2 | Niveau 3 | JNSP |
|---|---|---|---|---|---|---|
| `acq.lead_sources` | D'où viennent vos nouveaux clients ? | Presque uniquement du bouche-à-oreille, sans action de notre part | Surtout du bouche-à-oreille, avec quelques actions ponctuelles | De plusieurs canaux actifs (réseau, contenus, partenaires…) | De plusieurs canaux actifs et suivis, dont aucun ne dépasse la moitié des contacts | [I] |
| `acq.sales_process` | Votre processus commercial est-il formalisé ? | Chaque demande est traitée au cas par cas | Nous avons des étapes habituelles, non écrites | Les étapes sont écrites (qualification, découverte, proposition, relance) | Les étapes sont écrites, outillées (CRM, modèles) et appliquées par toute l'équipe | [É] |
| `acq.conversion` | Connaissez-vous votre taux de transformation des devis ? | Non | Un ordre de grandeur approximatif | Oui, calculé ponctuellement | Oui, suivi régulièrement et utilisé pour piloter | [É] |
| `acq.pipeline` | Savez-vous quel chiffre d'affaires est probable dans les 3 prochains mois ? | Non, nous le découvrons au fil de l'eau | Une vague estimation | Une liste des opportunités en cours, avec leurs montants | Un pipeline pondéré par probabilité, mis à jour chaque semaine | [É] |
| `acq.visibility` | Menez-vous des actions régulières de visibilité ? | Aucune | Ponctuelles, sans régularité | Régulières sur au moins un canal (contenus, réseau, événements) | Un plan de visibilité suivi, avec mesure des résultats | [É] |

## Dimension 3 — Rentabilité et pilotage financier (`fin`)

| Code | Question | Niveau 0 | Niveau 1 | Niveau 2 | Niveau 3 | JNSP |
|---|---|---|---|---|---|---|
| `fin.pricing_method` | Comment fixez-vous le prix de vos prestations ? | Au feeling ou en fonction des concurrents | À partir du temps estimé, sans coût horaire calculé | À partir du temps et d'un coût horaire calculé | Coût complet, marge cible et suivi de la rentabilité réelle | [É] |
| `fin.hourly_cost` | Connaissez-vous votre coût horaire de revient ? | Non | Un ordre de grandeur estimé | Oui, calculé à partir de nos charges et de notre temps facturable | Oui, calculé et mis à jour au moins une fois par an | [É] · **prérempli** (voir plus bas) |
| `fin.time_tracking` | Suivez-vous le temps réellement passé sur les projets ? | Non | Occasionnellement | Sur la plupart des projets | Systématiquement, et nous le comparons au temps vendu | [É] |
| `fin.project_profitability` | Connaissez-vous la rentabilité de chaque projet une fois terminé ? | Non | Une impression générale | Calculée pour les projets importants | Calculée pour chaque projet, avec analyse des écarts | [É] |
| `fin.steering` | Disposez-vous d'un tableau de bord financier ? | Non, seulement le bilan annuel | Un suivi de trésorerie uniquement | Un tableau de bord mensuel (CA, charges, marge) | Un tableau de bord mensuel, avec prévisionnel et objectifs | [É] |

## Dimension 4 — Organisation et processus internes (`org`)

| Code | Question | Niveau 0 | Niveau 1 | Niveau 2 | Niveau 3 | JNSP |
|---|---|---|---|---|---|---|
| `org.production_process` | Votre processus de production est-il documenté ? | Non | Des habitudes partagées oralement | Les étapes des projets principaux sont documentées | Un processus documenté, avec modèles et listes de contrôle utilisés systématiquement | [É] |
| `org.project_tools` | Utilisez-vous un outil de gestion de projet ? | Non (emails, mémoire) | Un outil, utilisé de façon irrégulière | Un outil utilisé sur tous les projets | Un outil utilisé partout, partagé avec les clients ou relié au suivi du temps | [É] |
| `org.scope_changes` | Comment gérez-vous les demandes hors périmètre ? | Nous les réalisons sans les facturer | Au cas par cas, souvent offertes | Signalées au client et souvent facturées | Une règle écrite au contrat : avenant ou devis systématique | [É] |
| `org.owner_dependency` | L'agence peut-elle fonctionner deux semaines sans le dirigeant ? | Non, tout passe par lui | Difficilement : beaucoup de décisions restent bloquées | Oui pour la production, pas pour le commercial | Oui : les rôles et les délégations sont clairs | [I] |
| `org.roles` | Les rôles et responsabilités sont-ils clairs dans l'équipe ? | Non définis | Implicites | Définis pour les rôles clés | Définis, partagés et revus régulièrement | [É] · **conditionnelle** |

## Dimension 5 — Fidélisation et revenus récurrents (`ret`)

| Code | Question | Niveau 0 | Niveau 1 | Niveau 2 | Niveau 3 | JNSP |
|---|---|---|---|---|---|---|
| `ret.maintenance_offer` | Proposez-vous une offre de maintenance ou d'accompagnement récurrent ? | Non | Au cas par cas, sans offre formalisée | Une offre formalisée, souscrite par peu de clients | Une offre formalisée, proposée systématiquement et souscrite par une part significative des clients | [É] |
| `ret.renewal` | Quelle part de vos contrats récurrents est renouvelée chaque année ? | Moins de 50 % | De 50 à 70 % | De 70 à 90 % | Plus de 90 % | [I] · **conditionnelle** |
| `ret.contract_review` | Revoyez-vous régulièrement le contenu et le prix de vos contrats récurrents ? | Jamais | Rarement | Une fois par an | Une fois par an, avec un bilan présenté au client | [É] · **conditionnelle** |
| `ret.upsell` | Proposez-vous de nouvelles prestations à vos clients existants ? | Non, seulement s'ils le demandent | Occasionnellement | Régulièrement, de façon informelle | Un suivi organisé (points réguliers, plan de compte) | [É] |
| `ret.satisfaction` | Mesurez-vous la satisfaction de vos clients ? | Non | Par des retours informels | Par un questionnaire en fin de projet | Par une mesure régulière, suivie d'actions | [É] |

## Dimension 6 — Stratégie et développement (`str`)

| Code | Question | Niveau 0 | Niveau 1 | Niveau 2 | Niveau 3 | JNSP |
|---|---|---|---|---|---|---|
| `str.objectives` | Avez-vous des objectifs chiffrés pour les 12 prochains mois ? | Non | Un objectif de CA global, en tête | Des objectifs écrits (CA, marge, récurrent) | Des objectifs écrits, déclinés en actions et suivis chaque mois | [É] |
| `str.vision` | Avez-vous une vision de votre agence à trois ans ? | Non | Une idée générale | Une vision écrite (taille, offres, positionnement) | Une vision écrite, partagée avec l'équipe et traduite en étapes | [É] |
| `str.review` | Prenez-vous du recul régulièrement sur la stratégie de l'agence ? | Jamais | Quand un problème survient | Une fois par an | Chaque trimestre, avec un bilan des indicateurs | [É] |
| `str.capacity_plan` | Anticipez-vous vos besoins en recrutement ou en sous-traitance ? | Non, nous réagissons dans l'urgence | Partiellement | Oui, en fonction du carnet de commandes | Un plan de capacité relié aux objectifs et au pipeline | [É] |
| `str.market_watch` | Suivez-vous les évolutions de votre marché (IA, outils, concurrence) ? | Non | De façon passive | Une veille régulière | Une veille organisée, qui fait évoluer nos offres | [É] |

---

## Conditions d'affichage

| Question | Affichée si | Sinon |
|---|---|---|
| `ret.renewal`, `ret.contract_review` | `ret.maintenance_offer` est au niveau 1 ou plus | **non applicables** : exclues du calcul ; la dimension garde 3 questions applicables et reste calculable |
| `org.roles` | l'équipe compte au moins 2 personnes (taille de l'équipe du profil), ou la taille n'est pas renseignée | **non applicable** pour une agence d'une seule personne |

Une réponse à une question devenue non applicable (par exemple si l'on revient changer `ret.maintenance_offer` en niveau 0) est conservée mais **ignorée** dans le calcul.

## Préremplissage depuis le profil

Il s'agit toujours d'une **suggestion modifiable**, signalée « Issu de votre profil ».

| Question | Règle |
|---|---|
| `fin.hourly_cost` | Si le coût horaire est **calculé** à partir des charges : niveau 2 suggéré. Si le coût horaire est **saisi manuellement** : niveau 1 suggéré. Sinon : pas de suggestion |
| `ret.maintenance_offer` | Activé au **lot 4** (offres récurrentes) : niveau 1 au minimum si une offre récurrente active existe |

## Équilibre des politiques « Je ne sais pas »

- **27 questions évaluables [É]** : elles portent sur une pratique ou une donnée que le dirigeant pilote lui-même (ne pas savoir revient à l'absence de pratique).
- **3 questions inconnues [I]** :
  - `acq.lead_sources` : la répartition des contacts peut ne jamais avoir été mesurée ;
  - `org.owner_dependency` : la situation peut ne jamais s'être présentée ;
  - `ret.renewal` : le taux de renouvellement peut ne pas encore être mesurable (offre récente).

## Exemples de lecture

- **Lumen (démo)** : un jeu de réponses fictif est prérempli et terminé à la création de la démo. Il est cohérent avec le cas DIAG-1 de 03 §6.3 (score 58/100, forces Fidélisation et Organisation, axes Stratégie et Rentabilité), de sorte que la vue d'ensemble affiche un score dès l'arrivée. Lumen y répond « Je ne sais pas » à `org.owner_dependency` (question inconnue), ce qui donne 9 points sur 4 réponses exploitables en Organisation (75/100) et une fiabilité globale de 29/30 (élevée). Cela illustre aussi la politique « Je ne sais pas » dans la démo.
- **Agence d'une seule personne, sans offre de maintenance** : 27 questions applicables (sans `org.roles`, `ret.renewal` ni `ret.contract_review`).

---

## Points à valider

1. **Le texte** des 30 questions et des 120 réponses graduées.
2. **Les politiques « Je ne sais pas »** : 27 évaluables et 3 inconnues.
3. **Les conditions** : questions de renouvellement liées à l'offre de maintenance, question sur les rôles masquée pour une agence d'une seule personne.
4. **Le préremplissage** de `fin.hourly_cost` depuis le profil.
5. **Le diagnostic fictif prérempli** pour la démo Lumen, pour que le score s'affiche dès l'arrivée.
