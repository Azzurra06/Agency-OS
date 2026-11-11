<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Glossaire des termes techniques : source unique des définitions affichées
 * dans la page « Glossaire » et dans les bulles d'information (ui_term()).
 *
 * Chaque définition est écrite pour quelqu'un qui ne connaît pas le terme :
 * une phrase simple (« en clair »), un exemple chiffré et, si utile, la formule exacte.
 */
final class Glossary
{
    /** Rubriques, dans l'ordre d'affichage. */
    public const CATEGORIES = [
        'finances' => 'Finances de l\'agence',
        'production' => 'Temps et production',
        'pricing' => 'Prix et marges',
        'recurrent' => 'Revenus récurrents',
        'pilotage' => 'Diagnostic et pilotage',
    ];

    /**
     * @return array<string, array{term: string, aka?: string, category: string, definition: string, example: string, formula?: string, see?: string}>
     */
    public static function terms(): array
    {
        return [
            // ---------- Finances ----------
            'chiffre-affaires' => [
                'term' => 'Chiffre d\'affaires (CA)',
                'aka' => 'CA HT',
                'category' => 'finances',
                'definition' => 'Tout ce que vos clients vous ont facturé sur une période, hors TVA. C\'est l\'argent qui entre, avant de payer quoi que ce soit.',
                'example' => '12 sites vendus 4 000 € HT + 6 000 € de maintenance = 54 000 € de CA.',
                'see' => 'profil',
            ],
            'periode-reference' => [
                'term' => 'Période de référence',
                'category' => 'finances',
                'definition' => 'La période sur laquelle portent vos chiffres : en général votre dernier exercice comptable clos, ou les 12 derniers mois.',
                'example' => 'Exercice du 1er janvier au 31 décembre 2025.',
                'see' => 'profil',
            ],
            'annualisation' => [
                'term' => 'Montant annualisé',
                'category' => 'finances',
                'definition' => 'Un montant ramené à une année complète, pour pouvoir comparer des périodes de durées différentes.',
                'example' => '45 000 € de CA sur 6 mois → 90 000 € annualisés.',
                'formula' => 'Montant annualisé = montant × 12 ÷ nombre de mois de la période',
                'see' => 'profil',
            ],
            'charges-fixes' => [
                'term' => 'Charges fixes',
                'category' => 'finances',
                'definition' => 'Les dépenses que vous payez même si vous ne vendez rien ce mois-ci. Les salaires sont comptés à part.',
                'example' => 'Loyer, abonnements logiciels, assurance, expert-comptable : 2 500 €/mois, soit 30 000 €/an.',
                'see' => 'profil',
            ],
            'cout-employeur' => [
                'term' => 'Coût employeur chargé',
                'aka' => 'Coût des salariés',
                'category' => 'finances',
                'definition' => 'Ce qu\'un salarié coûte réellement à l\'agence : son salaire brut plus les cotisations patronales. Ce n\'est pas son salaire net.',
                'example' => 'Salaire brut de 2 800 €/mois → environ 4 060 €/mois pour l\'agence (× 1,45 en ordre de grandeur en France).',
                'see' => 'profil',
            ],
            'frais-attribuables' => [
                'term' => 'Frais attribuables (frais directs)',
                'category' => 'finances',
                'definition' => 'Les dépenses liées à un projet précis : sans ce projet, vous ne les auriez pas payées.',
                'example' => 'Un thème premium à 70 € et 400 € de sous-traitance rédactionnelle pour le site d\'un client.',
                'see' => 'pricing',
            ],
            'charges-variables-generales' => [
                'term' => 'Charges variables générales',
                'category' => 'finances',
                'definition' => 'Des frais qui augmentent avec votre chiffre d\'affaires, sans être liés à un projet en particulier. Agency OS les applique en pourcentage de chaque prix.',
                'example' => 'Commissions de paiement et frais bancaires = 2 % du CA : sur une vente de 3 000 €, 60 € partent en frais.',
                'see' => 'pricing',
            ],
            'remuneration-dirigeant' => [
                'term' => 'Rémunération du dirigeant (coût complet)',
                'category' => 'finances',
                'definition' => 'Ce que vous vous versez sur un an, cotisations sociales comprises. Votre temps a un coût : l\'oublier fait paraître l\'agence plus rentable qu\'elle ne l\'est.',
                'example' => '3 000 € net/mois en gérant majoritaire de SARL ≈ 50 000 €/an en coût complet.',
                'see' => 'profil',
            ],
            'resultat-estime' => [
                'term' => 'Résultat estimé',
                'category' => 'finances',
                'definition' => 'Ce qui reste une fois toutes les dépenses payées, y compris votre propre rémunération. Positif : l\'agence gagne de l\'argent ; négatif : elle en perd.',
                'example' => 'CA 180 000 € − charges 155 600 € (dont 50 000 € pour le dirigeant) = 24 400 € de résultat estimé.',
                'formula' => 'Résultat estimé = CA annualisé − toutes les charges annualisées',
                'see' => 'profil',
            ],

            // ---------- Production ----------
            'etp' => [
                'term' => 'ETP (équivalent temps plein)',
                'category' => 'production',
                'definition' => 'Une façon de compter les personnes selon leur temps de travail : un temps plein vaut 1, un mi-temps vaut 0,5.',
                'example' => 'Vous à temps plein + un développeur à mi-temps = 1,5 ETP.',
                'see' => 'cout-horaire',
            ],
            'taux-facturabilite' => [
                'term' => 'Taux de facturabilité',
                'category' => 'production',
                'definition' => 'La part de votre temps de travail réellement vendue à des clients. Le reste part en prospection, devis, administratif, formation…',
                'example' => 'Sur 35 h par semaine, 23 h sur des projets clients → environ 65 % de facturabilité.',
                'see' => 'cout-horaire',
            ],
            'heures-facturables' => [
                'term' => 'Heures facturables',
                'category' => 'production',
                'definition' => 'Le nombre d\'heures par an que l\'agence peut vendre à ses clients.',
                'example' => '2 personnes × 1 607 h par an × 65 % = 2 089 heures facturables.',
                'formula' => 'Heures facturables = ETP de production × heures annuelles × taux de facturabilité',
                'see' => 'cout-horaire',
            ],
            'cout-horaire' => [
                'term' => 'Coût horaire complet',
                'aka' => 'Coût horaire de revient',
                'category' => 'production',
                'definition' => 'Ce que vous coûte une heure vendue, une fois tous les frais de l\'agence répartis (loyer, salaires, votre rémunération…). Si vous vendez vos heures en dessous, vous perdez de l\'argent.',
                'example' => '140 000 € de frais par an ÷ 2 089 heures facturables = 67 €/h. Facturer 60 €/h fait perdre 7 € par heure.',
                'formula' => 'Coût horaire = (charges fixes + salariés + rémunération du dirigeant) ÷ heures facturables',
                'see' => 'cout-horaire',
            ],
            'capacite-utilisee' => [
                'term' => 'Capacité utilisée',
                'category' => 'production',
                'definition' => 'La part de votre temps de production déjà occupée chaque mois par vos contrats récurrents. Elle montre combien de temps il vous reste pour d\'autres projets.',
                'example' => '30 h de maintenance par mois sur 174 h disponibles = 17 % de capacité utilisée.',
                'formula' => 'Capacité utilisée = heures mensuelles des contrats ÷ (heures facturables ÷ 12)',
                'see' => 'recurrent',
            ],

            // ---------- Pricing ----------
            'cout-de-revient' => [
                'term' => 'Coût de revient',
                'category' => 'pricing',
                'definition' => 'Ce que vous coûte réellement une prestation : le temps passé (au coût horaire complet) plus les frais propres au projet.',
                'example' => '40 h × 60 €/h + 300 € de frais = 2 700 € de coût de revient.',
                'see' => 'pricing',
            ],
            'prix-plancher' => [
                'term' => 'Prix plancher',
                'category' => 'pricing',
                'definition' => 'Le prix minimum pour ne pas perdre d\'argent : à ce prix, vous couvrez tous vos coûts mais ne gagnez rien.',
                'example' => 'Coût de revient 2 700 € et 2 % de frais sur le prix → prix plancher de 2 755 €.',
                'formula' => 'Prix plancher = coût de revient ÷ (1 − charges variables générales)',
                'see' => 'pricing',
            ],
            'prix-recommande' => [
                'term' => 'Prix recommandé',
                'category' => 'pricing',
                'definition' => 'Le prix qui couvre vos coûts ET vous laisse exactement la marge que vous visez.',
                'example' => 'Coût de revient 2 700 €, marge visée 30 %, 2 % de frais → prix recommandé de 3 971 €.',
                'formula' => 'Prix recommandé = coût de revient ÷ (1 − marge cible − charges variables générales)',
                'see' => 'pricing',
            ],
            'marge-prix-vente' => [
                'term' => 'Marge sur prix de vente',
                'aka' => 'Taux de marque',
                'category' => 'pricing',
                'definition' => 'La part du prix payé par le client qui vous reste en poche une fois tous les coûts couverts.',
                'example' => 'Vendu 1 000 €, coûté 700 € → 300 € gagnés, soit 30 % de marge sur prix de vente.',
                'formula' => 'Marge sur prix de vente = résultat ÷ prix de vente',
                'see' => 'pricing',
            ],
            'majoration-cout' => [
                'term' => 'Majoration sur coût',
                'aka' => 'Taux de marge, coefficient',
                'category' => 'pricing',
                'definition' => 'De combien vous augmentez votre coût pour fixer le prix. Souvent confondue avec la marge sur prix de vente : ce n\'est pas la même chose.',
                'example' => 'Coût 700 € majoré de 30 % → prix 910 €, mais votre marge sur prix de vente n\'est que de 23 %.',
                'formula' => 'Majoration sur coût = résultat ÷ coût de revient',
                'see' => 'pricing',
            ],
            'marge-cible' => [
                'term' => 'Marge cible',
                'category' => 'pricing',
                'definition' => 'La marge sur prix de vente que vous souhaitez atteindre sur une prestation. C\'est votre objectif, pas un résultat.',
                'example' => 'Viser 30 % : sur un site vendu 4 000 €, 1 200 € doivent rester une fois tout payé.',
                'see' => 'pricing',
            ],
            'provision-aleas' => [
                'term' => 'Provision pour aléas',
                'category' => 'pricing',
                'definition' => 'Un pourcentage de temps ajouté à votre estimation pour absorber les imprévus : allers-retours, corrections, client indécis…',
                'example' => '40 h estimées + 10 % d\'aléas = 44 h comptées dans le prix.',
                'see' => 'pricing',
            ],
            'manque-a-gagner' => [
                'term' => 'Manque à gagner',
                'category' => 'pricing',
                'definition' => 'L\'argent que vous laissez sur la table chaque année en vendant en dessous du prix recommandé.',
                'example' => 'Prix recommandé 3 971 €, prix pratiqué 3 200 €, 8 ventes par an → environ 6 165 € de manque à gagner.',
                'formula' => 'Manque à gagner = (prix recommandé − prix pratiqué) × ventes par an',
                'see' => 'pricing',
            ],
            'simulation-finalisee' => [
                'term' => 'Brouillon et simulation finalisée',
                'category' => 'pricing',
                'definition' => 'Un brouillon sert à tester des hypothèses, sans conséquence. Une fois finalisée, la simulation est figée et devient le prix de référence de la prestation.',
                'example' => 'Vous testez 3 scénarios de prix en brouillon, puis finalisez celui à 3 900 € : c\'est lui que la stratégie utilisera.',
                'see' => 'pricing',
            ],

            // ---------- Récurrent ----------
            'revenus-recurrents' => [
                'term' => 'Revenus récurrents',
                'category' => 'recurrent',
                'definition' => 'L\'argent qui revient chaque mois ou chaque année grâce à des contrats (maintenance, hébergement, abonnement), sans devoir revendre à chaque fois. Il rend l\'agence plus stable et plus prévisible.',
                'example' => '15 clients en maintenance à 90 €/mois : 1 350 € encaissés chaque mois, même sans nouveau projet.',
                'see' => 'recurrent',
            ],
            'mrr' => [
                'term' => 'MRR (revenu récurrent mensuel)',
                'aka' => 'Monthly Recurring Revenue',
                'category' => 'recurrent',
                'definition' => 'Le total de ce que vos contrats récurrents rapportent chaque mois. Agency OS ne compte que les contrats signés ; un contrat annuel est ramené au mois.',
                'example' => '10 contrats à 80 €/mois + 2 contrats annuels à 1 200 € (= 100 €/mois) → MRR de 1 000 €.',
                'formula' => 'MRR = somme des prix mensuels × nombre de contrats signés',
                'see' => 'recurrent',
            ],
            'arr' => [
                'term' => 'ARR (revenu récurrent annuel)',
                'aka' => 'Annual Recurring Revenue',
                'category' => 'recurrent',
                'definition' => 'Le MRR ramené sur un an : ce que vos contrats récurrents rapporteraient en douze mois s\'ils restaient tous actifs.',
                'example' => 'MRR de 1 000 € → ARR de 12 000 €.',
                'formula' => 'ARR = MRR × 12',
                'see' => 'recurrent',
            ],
            'prix-mensuel-normalise' => [
                'term' => 'Prix mensuel normalisé',
                'category' => 'recurrent',
                'definition' => 'Le prix d\'une formule ramené au mois, pour pouvoir additionner des formules mensuelles et annuelles.',
                'example' => 'Une formule à 1 200 €/an compte pour 100 €/mois.',
                'see' => 'recurrent',
            ],
            'couverture-ca' => [
                'term' => 'Couverture du CA',
                'category' => 'recurrent',
                'definition' => 'La part de votre chiffre d\'affaires annuel déjà assurée par vos contrats récurrents signés. Plus elle est haute, moins vous dépendez des nouvelles ventes.',
                'example' => 'ARR 12 000 € pour un CA de 120 000 € → 10 % du CA couvert.',
                'formula' => 'Couverture = ARR contractualisé ÷ CA annualisé',
                'see' => 'recurrent',
            ],
            'part-recurrente' => [
                'term' => 'Part récurrente déclarée',
                'category' => 'recurrent',
                'definition' => 'Votre propre estimation de la part du CA qui vient de contrats récurrents. Elle peut différer de la couverture calculée si tous vos contrats ne sont pas saisis.',
                'example' => 'Vous estimez qu\'environ 15 % de votre CA vient de la maintenance.',
                'see' => 'recurrent',
            ],
            'churn' => [
                'term' => 'Taux de résiliation (churn)',
                'category' => 'recurrent',
                'definition' => 'La part de vos clients récurrents qui arrêtent leur contrat chaque mois. Un petit pourcentage mensuel pèse lourd sur un an.',
                'example' => '3 % par mois : sur 100 clients, environ 3 partent chaque mois, soit près d\'un tiers en un an.',
                'see' => 'recurrent',
            ],
            'contrat-signe' => [
                'term' => 'Réel (contractualisé) et prévisionnel (simulé)',
                'category' => 'recurrent',
                'definition' => 'Le réel ne compte que les contrats effectivement signés. Le prévisionnel est une projection « et si… ? » : il n\'est jamais ajouté au réel.',
                'example' => 'Vous avez 10 contrats signés (réel) et imaginez en signer 2 de plus par mois (prévisionnel).',
                'see' => 'recurrent',
            ],

            // ---------- Pilotage ----------
            'score-maturite' => [
                'term' => 'Score de maturité',
                'category' => 'pilotage',
                'definition' => 'Une note sur 100 issue du diagnostic, qui indique à quel point l\'agence est structurée sur six sujets : positionnement, acquisition, rentabilité, organisation, fidélisation, stratégie.',
                'example' => '62/100 = « Structuré » : de bonnes bases, quelques points à consolider.',
                'see' => 'diagnostic',
            ],
            'fiabilite' => [
                'term' => 'Fiabilité et diagnostic partiel',
                'category' => 'pilotage',
                'definition' => 'La fiabilité indique la part des questions auxquelles vous avez pu vraiment répondre. Si trop d\'informations manquent, le diagnostic est partiel : certaines dimensions ne sont pas notées.',
                'example' => '27 réponses exploitables sur 30 questions → 90 % de fiabilité.',
                'see' => 'diagnostic',
            ],
            'objectif-ca' => [
                'term' => 'Objectif de CA',
                'category' => 'pilotage',
                'definition' => 'Le chiffre d\'affaires que vous visez pour l\'année. Il est comparé à votre CA actuel pour mesurer le chemin restant ; ce n\'est pas une prévision.',
                'example' => 'Objectif 200 000 €, CA actuel 160 000 € → 40 000 € à trouver (+25 %).',
                'see' => 'recommandations',
            ],
            'priorite' => [
                'term' => 'Priorité d\'une recommandation',
                'category' => 'pilotage',
                'definition' => 'Une note de 0 à 100 qui classe les recommandations : plus elle est haute, plus le sujet mérite votre attention en premier.',
                'example' => 'Un prix sous le prix plancher (critique, 90) passe avant une part récurrente faible (moyenne, 45).',
                'see' => 'recommandations',
            ],
        ];
    }

    /**
     * @return array{term: string, aka?: string, category: string, definition: string, example: string, formula?: string, see?: string}
     */
    public static function get(string $slug): array
    {
        $terms = self::terms();
        if (!isset($terms[$slug])) {
            throw new \InvalidArgumentException('Terme inconnu du glossaire : ' . $slug);
        }

        return $terms[$slug];
    }

    /**
     * Termes regroupés par rubrique, triés alphabétiquement dans chaque rubrique.
     *
     * @return array<string, array<string, array{term: string, aka?: string, category: string, definition: string, example: string, formula?: string, see?: string}>>
     */
    public static function byCategory(): array
    {
        $terms = self::terms();
        uasort($terms, static fn (array $a, array $b): int => strcmp(self::sortKey($a['term']), self::sortKey($b['term'])));
        $grouped = array_fill_keys(array_keys(self::CATEGORIES), []);
        foreach ($terms as $slug => $term) {
            $grouped[$term['category']][$slug] = $term;
        }

        return $grouped;
    }

    private static function sortKey(string $term): string
    {
        $map = ['É' => 'E', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'ç' => 'c', 'ô' => 'o', 'î' => 'i'];

        return mb_strtolower(strtr($term, $map));
    }
}
