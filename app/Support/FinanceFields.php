<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Définition unique des champs du profil financier pour l'interface (libellés, unités, aides, bulles).
 * « term » renvoie à une fiche du glossaire (App\Support\Glossary) ; « tip » reste réservé aux aides propres à un champ.
 * Utilisée par l'onboarding, les paramètres et les messages de complétude.
 */
final class FinanceFields
{
    /**
     * @return array<string, array{label: string, unit: string, help: string, term?: string, note?: string, tip?: array{0: string, 1: string, 2?: string}}>
     */
    public static function amounts(): array
    {
        return [
            'revenue' => ['label' => 'Chiffre d\'affaires HT', 'unit' => '€', 'help' => 'Sur la période de référence.', 'term' => 'chiffre-affaires'],
            'fixed_costs' => ['label' => 'Charges fixes (hors personnel)', 'unit' => '€', 'help' => 'Loyer, logiciels, assurances, comptable…',
                'term' => 'charges-fixes', 'note' => 'N\'incluez pas les salaires : ils ont leur propre champ.'],
            'personnel_costs' => ['label' => 'Coût des salariés (chargé)', 'unit' => '€', 'help' => 'Salaire brut + charges patronales. 0 si aucun salarié.',
                'term' => 'cout-employeur', 'note' => 'Saisissez le coût total pour l\'agence, pas le net.'],
            'variable_costs_attributable' => ['label' => 'Frais attribuables aux projets', 'unit' => '€', 'help' => 'Total de la période : sous-traitance, licences, thèmes…',
                'term' => 'frais-attribuables', 'note' => 'Ici, indiquez leur total sur la période ; dans le simulateur de prix, elles se saisissent projet par projet.'],
            'variable_costs_general' => ['label' => 'Charges variables générales', 'unit' => '€', 'help' => 'Frais bancaires, commissions de paiement…',
                'term' => 'charges-variables-generales'],
            'owner_comp_total_cost' => ['label' => 'Rémunération du dirigeant (coût complet annuel)', 'unit' => '€', 'help' => 'Cotisations comprises. 0 si aucune.',
                'term' => 'remuneration-dirigeant'],
            'owner_comp_included_amount' => ['label' => 'dont déjà inclus dans les charges ci-dessus', 'unit' => '€', 'help' => 'Pour éviter de la compter deux fois.',
                'tip' => ['Part déjà incluse', 'Si vous êtes salarié de votre agence (président de SAS par exemple) et déjà compté dans le coût des salariés, indiquez ce montant : seule la différence sera ajoutée, pour ne pas compter votre rémunération deux fois.', 'coût complet 50 000 €, dont 40 000 € déjà dans « Coût des salariés » → seuls 10 000 € sont ajoutés.']],
            'revenue_target' => ['label' => 'Objectif de chiffre d\'affaires annuel', 'unit' => '€', 'help' => 'Pour l\'exercice visé.', 'term' => 'objectif-ca'],
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
