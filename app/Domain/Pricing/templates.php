<?php

declare(strict_types=1);

/*
 * Modèles de temps INDICATIFS (docs/03 §4.4), version 1. Proposés seulement sur demande
 * (« Préremplir avec un modèle indicatif »), chaque ligne restant modifiable et supprimable.
 * Ordre de grandeur pour démarrer, à adapter à votre méthode : ce n'est pas une référence de marché.
 */

return [
    'version' => '1',
    'categories' => [
        'showcase' => [
            ['Cadrage et recueil des besoins', '4'], ['Maquettes', '8'], ['Intégration et développement', '20'],
            ['Contenus et référencement de base', '4'], ['Recette et mise en ligne', '4'],
        ],
        'ecommerce' => [
            ['Cadrage et recueil des besoins', '8'], ['Maquettes', '16'], ['Intégration et développement', '48'],
            ['Catalogue, paiement et livraison', '16'], ['Recette et mise en ligne', '8'],
        ],
        'redesign' => [
            ['Audit de l\'existant', '6'], ['Maquettes', '10'], ['Intégration et reprise des contenus', '24'],
            ['Redirections et recette', '6'],
        ],
        'seo' => [
            ['Audit technique et sémantique', '6'], ['Recommandations et priorisation', '4'], ['Optimisations', '6'],
        ],
        'custom' => [
            ['Cadrage', '4'], ['Production', '16'], ['Recette', '4'],
        ],
        'other' => [
            ['Cadrage', '2'], ['Production', '8'], ['Recette', '2'],
        ],
    ],
];
