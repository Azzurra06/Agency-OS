<?php

declare(strict_types=1);

use App\Support\Escaper;

/*
 * Fragments d'interface réutilisés par les vues (docs/07-ux-ui.md §3).
 * Chaque fonction ÉCHAPPE toutes ses entrées et renvoie du HTML sûr ; le préfixe « ui_ » est reconnu
 * par tools/check-forbidden.php comme sortie autorisée dans les vues.
 */

if (!function_exists('ui_icon')) {
    function ui_icon(string $name, string $size = ''): string
    {
        $name = preg_replace('/[^a-z0-9-]/', '', $name) ?? '';
        $class = 'icon' . ($size === 'sm' ? ' icon--sm' : '');

        return '<svg class="' . $class . '" aria-hidden="true"><use href="/assets/icons/sprite.svg#i-' . $name . '"></use></svg>';
    }
}

if (!function_exists('ui_nature')) {
    /**
     * Badge de nature d'une donnée : déclaré, hypothèse, simulé, calculé, figé, démo (icône + libellé, jamais la couleur seule).
     */
    function ui_nature(string $nature, ?string $label = null): string
    {
        $map = [
            'declared' => ['pencil', 'Déclaré'],
            'assumption' => ['lightbulb', 'Hypothèse'],
            'simulated' => ['chart-spline', 'Simulé'],
            'calculated' => ['square-function', 'Calculé'],
            'frozen' => ['lock', 'Figé'],
            'demo' => ['flag', 'Démo'],
        ];
        [$icon, $default] = $map[$nature] ?? $map['calculated'];
        $css = array_key_exists($nature, $map) ? $nature : 'calculated';

        return '<span class="nature nature--' . $css . '">'
            . '<svg class="icon" aria-hidden="true"><use href="/assets/icons/sprite.svg#i-' . $icon . '"></use></svg>'
            . Escaper::html($label ?? $default) . '</span>';
    }
}

if (!function_exists('ui_tip')) {
    /**
     * Bulle d'information cliquable (docs/07-ux-ui.md §3.1, InfoBubble).
     *
     * @param list<string> $lines lignes supplémentaires (listes « inclus / exclus », formule…)
     * @param string|null $example exemple concret, affiché à part (« Exemple : … »)
     * @param string|null $moreHref lien vers le glossaire (chemin interne uniquement)
     * @param string|null $formula formule exacte, affichée en dernier
     */
    function ui_tip(string $id, string $label, string $title, string $body, array $lines = [], string $align = 'start', ?string $example = null, ?string $moreHref = null, ?string $formula = null): string
    {
        $id = preg_replace('/[^a-z0-9-]/', '', $id) ?? 'tip';
        $extra = '';
        if ($example !== null && $example !== '') {
            $extra .= '<span class="tip__example"><em>Exemple :</em> ' . Escaper::html($example) . '</span>';
        }
        foreach ($lines as $line) {
            $extra .= '<span class="tip__line">' . Escaper::html($line) . '</span>';
        }
        if ($formula !== null && $formula !== '') {
            $extra .= '<span class="tip__formula">' . Escaper::html($formula) . '</span>';
        }
        if ($moreHref !== null && preg_match('~^/[a-z0-9/#-]*$~', $moreHref) === 1) {
            $extra .= '<a class="tip__more" href="' . Escaper::attr($moreHref) . '">Voir dans le glossaire</a>';
        }

        return '<span class="tip tip--' . ($align === 'end' ? 'end' : 'start') . '">'
            . '<button class="info-btn" type="button" aria-expanded="false" aria-controls="' . $id . '">'
            . '<svg class="icon icon--sm" aria-hidden="true"><use href="/assets/icons/sprite.svg#i-info"></use></svg>'
            . '<span class="visually-hidden">À propos : ' . Escaper::html($label) . '</span></button>'
            . '<span class="tip__content" id="' . $id . '" role="note" hidden><strong>' . Escaper::html($title) . '</strong>'
            . Escaper::html($body) . $extra . '</span></span>';
    }
}

if (!function_exists('ui_term')) {
    /**
     * Bulle d'information alimentée par le glossaire (App\Support\Glossary) : définition simple,
     * exemple chiffré, précision propre à l'écran ($note) et lien vers la fiche du glossaire.
     *
     * @param list<string> $lines lignes supplémentaires propres à l'écran (inclus / exclus…)
     * @param bool $formula afficher aussi la formule exacte du glossaire
     */
    function ui_term(string $slug, ?string $note = null, string $align = 'start', array $lines = [], string $idSuffix = '', bool $formula = false): string
    {
        $term = \App\Support\Glossary::get($slug);
        $body = $term['definition'] . ($note !== null && $note !== '' ? ' ' . $note : '');
        $formulaText = $formula ? ($term['formula'] ?? null) : null;

        return ui_tip('tip-' . $slug . $idSuffix, $term['term'], $term['term'], $body, $lines, $align, $term['example'], '/app/glossaire#' . $slug, $formulaText);
    }
}

if (!function_exists('ui_csrf')) {
    function ui_csrf(string $token): string
    {
        return '<input type="hidden" name="_csrf" value="' . Escaper::attr($token) . '">';
    }
}
