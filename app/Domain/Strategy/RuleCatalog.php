<?php

declare(strict_types=1);

namespace App\Domain\Strategy;

use App\Support\Format;

/**
 * Contenu des règles (docs/03 §7.2) : titre, explication paramétrée par les valeurs de la preuve, impact,
 * difficulté, modules concernés et 2 à 5 actions concrètes. Le texte est produit à l'affichage : aucun montant
 * n'est stocké en clair en base.
 */
final class RuleCatalog
{
    private const DIMENSION_ACTIONS = [
        'D1' => ['Écrire votre client idéal (taille, secteur, besoin) et le partager à l\'équipe', 'Regrouper vos prestations en 2 ou 3 offres packagées avec un prix affiché', 'Formuler en une phrase pourquoi un client vous choisit', 'Mettre en avant 3 réalisations alignées sur cette cible'],
        'D2' => ['Lister vos sources de clients des 12 derniers mois', 'Formaliser les étapes de votre processus commercial', 'Suivre le taux de transformation de vos devis', 'Planifier une action de visibilité par mois'],
        'D3' => ['Calculer votre coût horaire complet dans le profil', 'Suivre le temps réellement passé sur chaque projet', 'Faire un bilan de rentabilité à la fin de chaque projet', 'Mettre en place un tableau de bord financier mensuel'],
        'D4' => ['Documenter votre processus de production étape par étape', 'Choisir un outil de gestion de projet commun', 'Définir une règle pour les demandes hors périmètre', 'Réduire la dépendance au dirigeant sur les tâches courantes'],
        'D5' => ['Créer ou revoir votre offre de maintenance', 'Proposer la maintenance à chaque fin de projet', 'Revoir le contenu et le prix des contrats une fois par an', 'Mesurer la satisfaction de vos clients'],
        'D6' => ['Fixer 3 objectifs chiffrés pour les 12 prochains mois', 'Écrire votre vision de l\'agence à trois ans', 'Bloquer une demi-journée par trimestre pour la stratégie', 'Anticiper vos besoins en recrutement ou sous-traitance'],
    ];

    /**
     * @param array<string, mixed> $evidence
     * @return array{title: string, explanation: string, impact_text: string, difficulty: string, modules: list<string>, actions: list<string>}
     */
    public static function describe(string $code, array $evidence, ?string $impactAmount): array
    {
        /** @var array<string, string|int|null> $v */
        $v = is_array($evidence['values'] ?? null) ? $evidence['values'] : [];
        $s = static fn (string $key): string => isset($v[$key]) ? (string) $v[$key] : '';
        $money = static fn (string $key): string => isset($v[$key]) ? Format::money((string) $v[$key]) : '—';
        $pct = static fn (string $key, int $dec = 1): string => isset($v[$key]) ? Format::percent((string) $v[$key], $dec) : '—';
        $impact = $impactAmount === null ? null : Format::money($impactAmount);

        if (str_starts_with($code, 'DIM_LOW_')) {
            $dx = substr($code, 8);

            return [
                'title' => 'Renforcer la dimension « ' . $s('dimension') . ' »',
                'explanation' => 'Votre dernier diagnostic donne ' . Format::number($s('score'), 0) . '/100 à cette dimension, sous le seuil de 40. C\'est l\'un des leviers les plus directs pour structurer l\'agence.',
                'impact_text' => 'Qualitatif : progression de la maturité sur cette dimension.',
                'difficulty' => 'medium',
                'modules' => ['diagnostic', 'strategy'],
                'actions' => self::DIMENSION_ACTIONS[$dx] ?? [],
            ];
        }

        return match ($code) {
            'RESULT_NEGATIVE' => [
                'title' => 'Votre résultat estimé est négatif',
                'explanation' => 'Sur la période de référence, vos charges dépassent votre chiffre d\'affaires : le résultat estimé est de ' . $money('estimated_result') . '. Les prix et la charge de structure sont les deux premiers leviers.',
                'impact_text' => 'Déficit estimé : ' . $money('estimated_result') . ' sur la période.',
                'difficulty' => 'high',
                'modules' => ['pricing', 'strategy'],
                'actions' => ['Vérifier les charges déclarées dans le profil', 'Simuler le prix de vos prestations principales', 'Identifier les charges fixes réductibles', 'Augmenter la part de revenus récurrents'],
            ],
            'PRICE_BELOW_FLOOR' => [
                'title' => '« ' . $s('service') . ' » est vendu sous son prix plancher',
                'explanation' => 'Au prix actuel de ' . $money('current_price') . ', chaque vente fait perdre environ ' . $money('loss_per_sale') . ' : le prix plancher de la simulation figée est de ' . $money('floor_price') . ' et le prix recommandé de ' . $money('recommended_price') . '.',
                'impact_text' => $impact === null ? 'Perte par vente : ' . $money('loss_per_sale') . ' (volume annuel non renseigné : impact annuel non chiffré).' : 'Perte annuelle estimée : ' . $impact . ' (perte par vente × ' . $s('annual_volume') . ' ventes par an).',
                'difficulty' => 'medium',
                'modules' => ['pricing'],
                'actions' => ['Relever le prix catalogue au moins au prix plancher', 'Réduire le temps passé (périmètre, gabarits, réutilisation)', 'Facturer à part les options et les frais directs', 'Annoncer la nouvelle grille aux prochains devis'],
            ],
            'PRICE_UNDER' => [
                'title' => '« ' . $s('service') . ' » est sous-tarifé',
                'explanation' => 'Le prix actuel (' . $money('current_price') . ') est ' . Format::percent(ltrim($s('price_gap_rate'), '-')) . ' sous le prix recommandé de la simulation figée (' . $money('recommended_price') . ') : votre marge cible n\'est pas atteinte.',
                'impact_text' => $impact === null ? 'Volume annuel non renseigné : manque à gagner non chiffré.' : 'Manque à gagner : ' . $impact . ' par an ((prix recommandé − prix actuel) × ' . $s('annual_volume') . ' ventes).',
                'difficulty' => 'low',
                'modules' => ['pricing'],
                'actions' => ['Aligner progressivement le prix catalogue sur le prix recommandé', 'Appliquer le nouveau prix aux prochains devis', 'Mettre en avant la valeur livrée dans la proposition commerciale', 'Revoir la simulation si le temps estimé a changé'],
            ],
            'PRICING_DRIFT' => [
                'title' => 'Simulation « ' . $s('service') . ' » à revalider',
                'explanation' => 'Le coût horaire ou le taux de charges variables de votre profil s\'écarte de plus de 5 % des valeurs figées dans la simulation active.',
                'impact_text' => 'Aucun impact chiffré : la référence de prix est peut-être obsolète.',
                'difficulty' => 'low',
                'modules' => ['pricing'],
                'actions' => ['Ouvrir la simulation et créer une nouvelle version', 'Vérifier le prix recommandé avec les hypothèses actuelles', 'Finaliser la nouvelle version'],
            ],
            'MAINT_UNPROFITABLE' => [
                'title' => 'La formule « ' . $s('offer') . ' » est déficitaire',
                'explanation' => 'Chaque contrat rapporte ' . $money('monthly_result') . ' par mois une fois le temps, les coûts externes et les charges variables couverts.',
                'impact_text' => $impact === null ? 'Aucun contrat signé : pas de perte actuelle.' : 'Perte : ' . $impact . ' par mois (' . $s('contracts') . ' contrats).',
                'difficulty' => 'medium',
                'modules' => ['recurring'],
                'actions' => ['Relever le prix de la formule au moins au prix plancher mensuel', 'Réduire le temps inclus (automatisation des mises à jour, sauvegardes)', 'Proposer une formule supérieure aux clients actuels', 'Appliquer le nouveau tarif aux renouvellements'],
            ],
            'MAINT_LOW_MARGIN' => [
                'title' => 'Marge faible sur la formule « ' . $s('offer') . ' »',
                'explanation' => 'La marge sur prix de vente est de ' . $pct('margin_rate') . ' par contrat, sous le seuil de 20 %.',
                'impact_text' => 'Qualitatif : rentabilité du récurrent.',
                'difficulty' => 'low',
                'modules' => ['recurring'],
                'actions' => ['Revoir le prix de la formule', 'Limiter le temps inclus ou facturer le dépassement', 'Revoir les coûts externes (licences, outils)'],
            ],
            'RECURRING_SHARE_LOW' => [
                'title' => 'Part de revenus récurrents faible',
                'explanation' => 'Vous déclarez ' . $pct('declared_share') . ' de chiffre d\'affaires récurrent, sous le seuil de 20 % : votre activité dépend fortement des nouveaux projets.',
                'impact_text' => 'Qualitatif : stabilité et prévisibilité du chiffre d\'affaires.',
                'difficulty' => 'medium',
                'modules' => ['recurring', 'strategy'],
                'actions' => ['Créer une offre de maintenance ou d\'accompagnement', 'La proposer systématiquement en fin de projet', 'Simuler un scénario de croissance du récurrent'],
            ],
            'NO_RECURRING_OFFER' => [
                'title' => 'Aucune offre récurrente',
                'explanation' => 'Vous n\'avez aucune formule récurrente active (maintenance, hébergement, SEO). C\'est souvent le moyen le plus simple de lisser le chiffre d\'affaires.',
                'impact_text' => 'Qualitatif : stabilité du chiffre d\'affaires.',
                'difficulty' => 'medium',
                'modules' => ['recurring'],
                'actions' => ['Définir le contenu d\'une formule de maintenance', 'Calculer son prix plancher dans le module Revenus récurrents', 'La proposer à vos clients récents'],
            ],
            'RECURRING_DATA_GAP' => [
                'title' => 'Part récurrente déclarée à vérifier',
                'explanation' => 'Les contrats signés couvrent ' . $pct('coverage') . ' du chiffre d\'affaires, contre ' . $pct('declared_share') . ' déclarés : ' . Format::number($s('gap_points'), 1) . ' points d\'écart. Des contrats manquent peut-être, ou la part déclarée est obsolète.',
                'impact_text' => 'Aucun impact chiffré : fiabilité des données.',
                'difficulty' => 'low',
                'modules' => ['recurring'],
                'actions' => ['Vérifier le nombre de contrats signés de chaque formule', 'Mettre à jour la part déclarée si elle est obsolète'],
            ],
            'NO_HOURLY_COST' => [
                'title' => 'Coût horaire non calculable',
                'explanation' => 'Sans coût horaire, impossible de vérifier que vos prix couvrent vos coûts. Il manque des données dans votre profil financier (charges, rémunération, temps de production).',
                'impact_text' => 'Aucun impact chiffré : données à compléter.',
                'difficulty' => 'low',
                'modules' => ['pricing', 'recurring'],
                'actions' => ['Compléter les charges et la rémunération dans le profil', 'Indiquer le nombre de personnes qui produisent', 'Ou saisir un coût horaire forcé'],
            ],
            'HOURLY_ASSUMPTIONS' => [
                'title' => 'Coût horaire calculé avec des hypothèses par défaut',
                'explanation' => 'Votre coût horaire (' . $money('hourly_cost') . '/h) utilise des valeurs par défaut (heures annuelles ou taux de facturabilité). Vos vrais chiffres le rendraient plus fiable.',
                'impact_text' => 'Aucun impact chiffré : fiabilité du coût horaire.',
                'difficulty' => 'low',
                'modules' => ['pricing'],
                'actions' => ['Mesurer votre taux de facturabilité réel sur un trimestre', 'Remplacer les hypothèses dans le profil'],
            ],
            'TARGET_GAP' => [
                'title' => 'Objectif de chiffre d\'affaires très ambitieux',
                'explanation' => 'Votre objectif (' . $money('revenue_target') . ') demande ' . $pct('growth_needed') . ' de croissance par rapport au CA annualisé (' . $money('revenue_annualized') . '), au-delà de 30 %.',
                'impact_text' => 'Écart à combler : ' . ($impact ?? '—') . ' (déclaré).',
                'difficulty' => 'high',
                'modules' => ['strategy'],
                'actions' => ['Découper l\'objectif par trimestre et par offre', 'Vérifier la capacité de production nécessaire', 'Identifier les leviers prix, volume et récurrent'],
            ],
            'CAPACITY_RECURRING_HIGH' => [
                'title' => 'Le récurrent occupe une grande part de la capacité',
                'explanation' => 'Les contrats signés demandent ' . Format::hours($s('recurring_hours'), 1) . ' par mois, soit ' . $pct('capacity_use') . ' de la capacité de production (seuil : 30 %).',
                'impact_text' => 'Qualitatif : disponibilité pour les projets.',
                'difficulty' => 'medium',
                'modules' => ['recurring'],
                'actions' => ['Automatiser les tâches de maintenance répétitives', 'Vérifier le temps réellement passé par contrat', 'Prévoir un renfort si le récurrent continue de croître'],
            ],
            'DIAG_LOW_RELIABILITY' => [
                'title' => 'Diagnostic peu fiable ou partiel',
                'explanation' => 'Votre dernier diagnostic compte trop de réponses inconnues ou de questions sans réponse (fiabilité ' . $pct('reliability', 0) . '). Les recommandations liées aux dimensions concernées sont limitées.',
                'impact_text' => 'Aucun impact chiffré : fiabilité des données.',
                'difficulty' => 'low',
                'modules' => ['diagnostic'],
                'actions' => ['Compléter les réponses manquantes', 'Refaire le diagnostic avec l\'équipe'],
            ],
            'PROFILE_INCOMPLETE' => [
                'title' => 'Profil financier incomplet',
                'explanation' => 'Votre profil est complété à ' . $s('completeness') . ' %. Plusieurs recommandations ne peuvent pas être évaluées sans ces données.',
                'impact_text' => 'Aucun impact chiffré : données à compléter.',
                'difficulty' => 'low',
                'modules' => ['strategy'],
                'actions' => ['Compléter le profil financier', 'Ajouter vos prestations et leurs prix'],
            ],
            'NO_DIAGNOSTIC' => [
                'title' => 'Faites le diagnostic de maturité',
                'explanation' => 'Le diagnostic (10 minutes) identifie vos dimensions les plus fragiles et affine la priorité des recommandations.',
                'impact_text' => 'Aucun impact chiffré.',
                'difficulty' => 'low',
                'modules' => ['diagnostic'],
                'actions' => ['Répondre au questionnaire de diagnostic'],
            ],
            'NO_PRICING' => [
                'title' => 'Prestations sans prix de référence',
                'explanation' => 'Aucune simulation finalisée pour : ' . $s('services') . '. Sans référence figée, impossible de vérifier que ces prix couvrent vos coûts.',
                'impact_text' => 'Aucun impact chiffré.',
                'difficulty' => 'low',
                'modules' => ['pricing'],
                'actions' => ['Créer une simulation pour chaque prestation active', 'Finaliser la simulation de référence'],
            ],
            default => ['title' => $code, 'explanation' => '', 'impact_text' => '', 'difficulty' => 'low', 'modules' => [], 'actions' => []],
        };
    }

    /** Unité de l'impact chiffré affiché sur la carte. */
    public static function impactUnit(string $code): string
    {
        return match ($code) {
            'PRICE_UNDER', 'PRICE_BELOW_FLOOR' => '/ an',
            'MAINT_UNPROFITABLE' => '/ mois',
            default => '',
        };
    }

    /** Libellé lisible d'une valeur ou d'un seuil de la preuve (« Voir le détail du calcul »). */
    public static function valueLabel(string $key): string
    {
        return [
            'service' => 'Prestation', 'offer' => 'Formule', 'current_price' => 'Prix actuel (€)', 'floor_price' => 'Prix plancher (€)',
            'recommended_price' => 'Prix recommandé (€)', 'price_gap_rate' => 'Écart au prix recommandé (%)', 'annual_volume' => 'Ventes par an',
            'loss_per_sale' => 'Perte par vente (€)', 'monthly_result' => 'Résultat mensuel par contrat (€)', 'contracts' => 'Contrats signés',
            'margin_rate' => 'Marge sur prix de vente (%)', 'declared_share' => 'Part récurrente déclarée (%)', 'coverage' => 'Couverture par les contrats (%)',
            'gap_points' => 'Écart (points)', 'hourly_cost' => 'Coût horaire (€/h)', 'assumptions' => 'Hypothèses', 'revenue_target' => 'Objectif de CA (€)',
            'revenue_annualized' => 'CA annualisé (€)', 'growth_needed' => 'Croissance nécessaire (%)', 'recurring_hours' => 'Heures de récurrent par mois',
            'monthly_capacity' => 'Capacité mensuelle (h)', 'capacity_use' => 'Capacité utilisée (%)', 'dimension' => 'Dimension', 'score' => 'Score (/100)',
            'reliability' => 'Fiabilité (%)', 'unscorable' => 'Dimensions non calculables', 'completeness' => 'Complétude du profil (%)',
            'estimated_result' => 'Résultat estimé (€)', 'services' => 'Prestations', 'count' => 'Nombre',
            'price_gap_medium' => 'Seuil « moyenne » (%)', 'price_gap_high' => 'Seuil « haute » (%)', 'drift_rate' => 'Dérive maximale (%)',
            'monthly_result_min' => 'Résultat minimal (€)', 'margin_min' => 'Marge minimale (%)', 'share_min' => 'Part minimale (%)',
            'gap_max_points' => 'Écart maximal (points)', 'growth_max' => 'Croissance maximale (%)', 'capacity_max' => 'Capacité maximale (%)',
            'score_min' => 'Score minimal', 'score_high' => 'Seuil « haute »', 'reliability_min' => 'Fiabilité minimale (%)', 'completeness_min' => 'Complétude minimale (%)',
            'result_min' => 'Résultat minimal (€)',
        ][$key] ?? $key;
    }

    /**
     * Libellé d'une source pour la ligne « Repose sur ».
     *
     * @param array<string, mixed> $source
     */
    public static function sourceLabel(array $source): string
    {
        $date = is_string($source['as_of'] ?? null) ? Format::date($source['as_of']) : null;

        return match ($source['type'] ?? null) {
            'financial_profile' => 'Profil financier' . (is_string($source['period'] ?? null) ? ', ' . $source['period'] : '') . ' (déclaré)',
            'pricing_simulation' => 'Simulation « ' . (is_string($source['label'] ?? null) ? $source['label'] : '') . ' » v' . (is_int($source['version'] ?? null) ? $source['version'] : '') . ($date === null ? '' : ', figée le ' . $date),
            'catalog' => 'Prix catalogue de « ' . (is_string($source['label'] ?? null) ? $source['label'] : '') . ' » (déclaré)',
            'recurring_contracts' => 'Contrats récurrents signés' . ($date === null ? '' : ', mis à jour le ' . $date),
            'diagnostic' => 'Diagnostic du ' . ($date ?? '—') . (($source['status'] ?? null) === 'partial' ? ' (partiel)' : ''),
            default => 'Données de l\'agence',
        };
    }
}
