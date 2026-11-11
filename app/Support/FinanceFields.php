<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Définition unique des champs du profil financier pour l'interface (libellés, unités, aides, bulles).
 * Utilisée par l'onboarding, les paramètres et les messages de complétude.
 */
final class FinanceFields
{
    /**
     * @return array<string, array{label: string, unit: string, help: string, tip?: array{0: string, 1: string}}>
     */
    public static function amounts(): array
    {
        return [
            'revenue' => ['label' => 'Chiffre d\'affaires HT', 'unit' => '€', 'help' => 'Sur la période de référence.'],
            'fixed_costs' => ['label' => 'Charges fixes (hors personnel)', 'unit' => '€', 'help' => 'Loyer, logiciels, assurances, comptable…',
                'tip' => ['Charges fixes', 'Dépenses qui ne varient pas avec l\'activité. N\'incluez pas les salaires : ils ont leur propre champ.']],
            'personnel_costs' => ['label' => 'Coût des salariés (chargé)', 'unit' => '€', 'help' => 'Salaire brut + charges patronales. 0 si aucun salarié.',
                'tip' => ['Coût employeur chargé', 'Ordre de grandeur en France : environ salaire brut × 1,45. Saisissez le coût total pour l\'agence, pas le net.']],
            'variable_costs_attributable' => ['label' => 'Frais attribuables aux projets', 'unit' => '€', 'help' => 'Total de la période : sous-traitance, licences, thèmes…',
                'tip' => ['Frais attribuables', 'Dépenses liées à un projet précis. Dans le simulateur de prix, elles se saisissent projet par projet.']],
            'variable_costs_general' => ['label' => 'Charges variables générales', 'unit' => '€', 'help' => 'Frais bancaires, commissions de paiement…',
                'tip' => ['Charges variables générales', 'Charges proportionnelles au chiffre d\'affaires mais non attribuables à un projet. Elles deviennent un pourcentage appliqué à chaque prix.']],
            'owner_comp_total_cost' => ['label' => 'Rémunération du dirigeant (coût complet annuel)', 'unit' => '€', 'help' => 'Cotisations comprises. 0 si aucune.',
                'tip' => ['Rémunération du dirigeant', 'Le coût complet sur un an, cotisations sociales comprises. Sans elle, le coût horaire et les prix recommandés seraient sous-estimés.']],
            'owner_comp_included_amount' => ['label' => 'dont déjà inclus dans les charges ci-dessus', 'unit' => '€', 'help' => 'Pour éviter de la compter deux fois.',
                'tip' => ['Part déjà incluse', 'Si le dirigeant est salarié et déjà compté dans le coût des salariés, indiquez ce montant : seule la différence sera ajoutée.']],
            'revenue_target' => ['label' => 'Objectif de chiffre d\'affaires annuel', 'unit' => '€', 'help' => 'Pour l\'exercice visé.'],
        ];
    }

    /** Libellés courts pour la complétude et le journal. */
    public static function label(string $field): string
    {
        $labels = [
            'period' => 'Période de référence', 'period_type' => 'Type de période', 'period_start' => 'Début de période', 'period_end' => 'Fin de période',
            'production_fte' => 'ETP de production', 'annual_hours_per_fte' => 'Heures annuelles par ETP', 'billable_rate' => 'Taux de facturabilité',
            'variable_costs' => 'Charges variables', 'hourly_cost_override' => 'Coût horaire personnalisé', 'revenue_target_year' => 'Année de l\'objectif',
            'recurring_share_declared' => 'Part récurrente déclarée', 'services' => 'Une prestation avec son prix', 'identity' => 'Identité de l\'agence',
            'no_billable_hours' => 'Heures facturables (au moins 1 ETP de production)',
        ];
        $amounts = self::amounts();

        return $labels[$field] ?? ($amounts[$field]['label'] ?? $field);
    }
}
