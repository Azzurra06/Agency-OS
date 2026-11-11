<?php

declare(strict_types=1);

/*
 * Questionnaire de diagnostic — VERSION 1 (docs/08-questionnaire-v1.md).
 *
 * IMMUABLE une fois publiée : codes, réponses, conditions, politiques « Je ne sais pas » et scoring ne changent plus.
 * Seules les corrections typographiques sans changement de sens sont admises. Toute modification de fond crée v2.php.
 * Un test vérifie l'empreinte de ce fichier (tests/Unit/DiagnosticScorerTest.php).
 *
 * unknown_policy : 'evaluable' (« Je ne sais pas » = niveau 0) ou 'unknown' (exclu du calcul, réduit la fiabilité).
 * condition : null | ['type' => 'answer_min', 'question' => code, 'level' => n] | ['type' => 'team_min', 'value' => n]
 * prefill : null | 'hourly_cost'
 */

$q = static fn (string $text, array $options, string $policy = 'evaluable', ?array $condition = null, ?string $prefill = null): array => [
    'text' => $text,
    'options' => ['n0' => $options[0], 'n1' => $options[1], 'n2' => $options[2], 'n3' => $options[3]],
    'unknown_policy' => $policy,
    'condition' => $condition,
    'prefill' => $prefill,
];

return [
    'version' => 'v1',
    'dimensions' => [
        'pos' => [
            'label' => 'Positionnement et offres',
            'description' => 'La clarté de votre cible, de votre spécialisation et de vos offres.',
            'questions' => [
                'pos.target' => $q('Votre clientèle cible est-elle clairement définie ?', [
                    'Nous travaillons pour tout type de client',
                    'Nous en avons une idée générale, sans l\'avoir formalisée',
                    'Elle est écrite (taille, secteur, besoin) mais guide peu nos choix',
                    'Elle est écrite et guide notre prospection, nos offres et les projets que nous refusons',
                ]),
                'pos.specialisation' => $q('Votre agence est-elle spécialisée ?', [
                    'Généraliste, tout type de projet',
                    'Une dominante technique (par exemple WordPress), sans spécialisation métier',
                    'Spécialisée sur une expertise ou un secteur',
                    'Spécialisée et reconnue comme telle (références, contenus, recommandations)',
                ]),
                'pos.packaged_offers' => $q('Vos offres sont-elles packagées ?', [
                    'Tout est sur devis, au cas par cas',
                    'Quelques prestations ont un prix indicatif',
                    'Nos prestations principales ont un contenu, un prix et un délai définis',
                    'Une gamme cohérente, avec options et montée en gamme',
                ]),
                'pos.differentiation' => $q('Pouvez-vous dire en une phrase pourquoi un client vous choisirait plutôt qu\'un concurrent ?', [
                    'Non',
                    'Oui, mais l\'argument est générique (qualité, réactivité…)',
                    'Oui, un argument spécifique, mais peu mis en avant',
                    'Oui, un argument spécifique, prouvé et présent dans toute notre communication',
                ]),
                'pos.portfolio_fit' => $q('Vos réalisations présentées correspondent-elles à la cible et aux offres que vous voulez développer ?', [
                    'Pas de portfolio à jour',
                    'Portfolio à jour mais hétérogène',
                    'Majoritairement aligné',
                    'Aligné, avec des études de cas chiffrées',
                ]),
            ],
        ],
        'acq' => [
            'label' => 'Acquisition et développement commercial',
            'description' => 'La régularité et la prévisibilité de votre développement commercial.',
            'questions' => [
                'acq.lead_sources' => $q('D\'où viennent vos nouveaux clients ?', [
                    'Presque uniquement du bouche-à-oreille, sans action de notre part',
                    'Surtout du bouche-à-oreille, avec quelques actions ponctuelles',
                    'De plusieurs canaux actifs (réseau, contenus, partenaires…)',
                    'De plusieurs canaux actifs et suivis, dont aucun ne dépasse la moitié des contacts',
                ], 'unknown'),
                'acq.sales_process' => $q('Votre processus commercial est-il formalisé ?', [
                    'Chaque demande est traitée au cas par cas',
                    'Nous avons des étapes habituelles, non écrites',
                    'Les étapes sont écrites (qualification, découverte, proposition, relance)',
                    'Les étapes sont écrites, outillées (CRM, modèles) et appliquées par toute l\'équipe',
                ]),
                'acq.conversion' => $q('Connaissez-vous votre taux de transformation des devis ?', [
                    'Non',
                    'Un ordre de grandeur approximatif',
                    'Oui, calculé ponctuellement',
                    'Oui, suivi régulièrement et utilisé pour piloter',
                ]),
                'acq.pipeline' => $q('Savez-vous quel chiffre d\'affaires est probable dans les 3 prochains mois ?', [
                    'Non, nous le découvrons au fil de l\'eau',
                    'Une vague estimation',
                    'Une liste des opportunités en cours, avec leurs montants',
                    'Un pipeline pondéré par probabilité, mis à jour chaque semaine',
                ]),
                'acq.visibility' => $q('Menez-vous des actions régulières de visibilité ?', [
                    'Aucune',
                    'Ponctuelles, sans régularité',
                    'Régulières sur au moins un canal (contenus, réseau, événements)',
                    'Un plan de visibilité suivi, avec mesure des résultats',
                ]),
            ],
        ],
        'fin' => [
            'label' => 'Rentabilité et pilotage financier',
            'description' => 'La façon dont vous fixez vos prix et suivez votre rentabilité.',
            'questions' => [
                'fin.pricing_method' => $q('Comment fixez-vous le prix de vos prestations ?', [
                    'Au feeling ou en fonction des concurrents',
                    'À partir du temps estimé, sans coût horaire calculé',
                    'À partir du temps et d\'un coût horaire calculé',
                    'Coût complet, marge cible et suivi de la rentabilité réelle',
                ]),
                'fin.hourly_cost' => $q('Connaissez-vous votre coût horaire de revient ?', [
                    'Non',
                    'Un ordre de grandeur estimé',
                    'Oui, calculé à partir de nos charges et de notre temps facturable',
                    'Oui, calculé et mis à jour au moins une fois par an',
                ], 'evaluable', null, 'hourly_cost'),
                'fin.time_tracking' => $q('Suivez-vous le temps réellement passé sur les projets ?', [
                    'Non',
                    'Occasionnellement',
                    'Sur la plupart des projets',
                    'Systématiquement, et nous le comparons au temps vendu',
                ]),
                'fin.project_profitability' => $q('Connaissez-vous la rentabilité de chaque projet une fois terminé ?', [
                    'Non',
                    'Une impression générale',
                    'Calculée pour les projets importants',
                    'Calculée pour chaque projet, avec analyse des écarts',
                ]),
                'fin.steering' => $q('Disposez-vous d\'un tableau de bord financier ?', [
                    'Non, seulement le bilan annuel',
                    'Un suivi de trésorerie uniquement',
                    'Un tableau de bord mensuel (CA, charges, marge)',
                    'Un tableau de bord mensuel, avec prévisionnel et objectifs',
                ]),
            ],
        ],
        'org' => [
            'label' => 'Organisation et processus internes',
            'description' => 'La solidité de votre organisation de production et de votre équipe.',
            'questions' => [
                'org.production_process' => $q('Votre processus de production est-il documenté ?', [
                    'Non',
                    'Des habitudes partagées oralement',
                    'Les étapes des projets principaux sont documentées',
                    'Un processus documenté, avec modèles et listes de contrôle utilisés systématiquement',
                ]),
                'org.project_tools' => $q('Utilisez-vous un outil de gestion de projet ?', [
                    'Non (emails, mémoire)',
                    'Un outil, utilisé de façon irrégulière',
                    'Un outil utilisé sur tous les projets',
                    'Un outil utilisé partout, partagé avec les clients ou relié au suivi du temps',
                ]),
                'org.scope_changes' => $q('Comment gérez-vous les demandes hors périmètre ?', [
                    'Nous les réalisons sans les facturer',
                    'Au cas par cas, souvent offertes',
                    'Signalées au client et souvent facturées',
                    'Une règle écrite au contrat : avenant ou devis systématique',
                ]),
                'org.owner_dependency' => $q('L\'agence peut-elle fonctionner deux semaines sans le dirigeant ?', [
                    'Non, tout passe par lui',
                    'Difficilement : beaucoup de décisions restent bloquées',
                    'Oui pour la production, pas pour le commercial',
                    'Oui : les rôles et les délégations sont clairs',
                ], 'unknown'),
                'org.roles' => $q('Les rôles et responsabilités sont-ils clairs dans l\'équipe ?', [
                    'Non définis',
                    'Implicites',
                    'Définis pour les rôles clés',
                    'Définis, partagés et revus régulièrement',
                ], 'evaluable', ['type' => 'team_min', 'value' => 2]),
            ],
        ],
        'ret' => [
            'label' => 'Fidélisation et revenus récurrents',
            'description' => 'Votre capacité à garder vos clients et à construire des revenus récurrents.',
            'questions' => [
                'ret.maintenance_offer' => $q('Proposez-vous une offre de maintenance ou d\'accompagnement récurrent ?', [
                    'Non',
                    'Au cas par cas, sans offre formalisée',
                    'Une offre formalisée, souscrite par peu de clients',
                    'Une offre formalisée, proposée systématiquement et souscrite par une part significative des clients',
                ]),
                'ret.renewal' => $q('Quelle part de vos contrats récurrents est renouvelée chaque année ?', [
                    'Moins de 50 %',
                    'De 50 à 70 %',
                    'De 70 à 90 %',
                    'Plus de 90 %',
                ], 'unknown', ['type' => 'answer_min', 'question' => 'ret.maintenance_offer', 'level' => 1]),
                'ret.contract_review' => $q('Revoyez-vous régulièrement le contenu et le prix de vos contrats récurrents ?', [
                    'Jamais',
                    'Rarement',
                    'Une fois par an',
                    'Une fois par an, avec un bilan présenté au client',
                ], 'evaluable', ['type' => 'answer_min', 'question' => 'ret.maintenance_offer', 'level' => 1]),
                'ret.upsell' => $q('Proposez-vous de nouvelles prestations à vos clients existants ?', [
                    'Non, seulement s\'ils le demandent',
                    'Occasionnellement',
                    'Régulièrement, de façon informelle',
                    'Un suivi organisé (points réguliers, plan de compte)',
                ]),
                'ret.satisfaction' => $q('Mesurez-vous la satisfaction de vos clients ?', [
                    'Non',
                    'Par des retours informels',
                    'Par un questionnaire en fin de projet',
                    'Par une mesure régulière, suivie d\'actions',
                ]),
            ],
        ],
        'str' => [
            'label' => 'Stratégie et développement',
            'description' => 'Votre capacité à fixer un cap et à piloter votre développement.',
            'questions' => [
                'str.objectives' => $q('Avez-vous des objectifs chiffrés pour les 12 prochains mois ?', [
                    'Non',
                    'Un objectif de CA global, en tête',
                    'Des objectifs écrits (CA, marge, récurrent)',
                    'Des objectifs écrits, déclinés en actions et suivis chaque mois',
                ]),
                'str.vision' => $q('Avez-vous une vision de votre agence à trois ans ?', [
                    'Non',
                    'Une idée générale',
                    'Une vision écrite (taille, offres, positionnement)',
                    'Une vision écrite, partagée avec l\'équipe et traduite en étapes',
                ]),
                'str.review' => $q('Prenez-vous du recul régulièrement sur la stratégie de l\'agence ?', [
                    'Jamais',
                    'Quand un problème survient',
                    'Une fois par an',
                    'Chaque trimestre, avec un bilan des indicateurs',
                ]),
                'str.capacity_plan' => $q('Anticipez-vous vos besoins en recrutement ou en sous-traitance ?', [
                    'Non, nous réagissons dans l\'urgence',
                    'Partiellement',
                    'Oui, en fonction du carnet de commandes',
                    'Un plan de capacité relié aux objectifs et au pipeline',
                ]),
                'str.market_watch' => $q('Suivez-vous les évolutions de votre marché (IA, outils, concurrence) ?', [
                    'Non',
                    'De façon passive',
                    'Une veille régulière',
                    'Une veille organisée, qui fait évoluer nos offres',
                ]),
            ],
        ],
    ],
];
