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
     */
    function ui_tip(string $id, string $label, string $title, string $body, array $lines = [], string $align = 'start'): string
    {
        $id = preg_replace('/[^a-z0-9-]/', '', $id) ?? 'tip';
        $extra = '';
        foreach ($lines as $line) {
            $extra .= '<span class="tip__line">' . Escaper::html($line) . '</span>';
        }

        return '<span class="tip tip--' . ($align === 'end' ? 'end' : 'start') . '">'
            . '<button class="info-btn" type="button" aria-expanded="false" aria-controls="' . $id . '">'
            . '<svg class="icon icon--sm" aria-hidden="true"><use href="/assets/icons/sprite.svg#i-info"></use></svg>'
            . '<span class="visually-hidden">À propos : ' . Escaper::html($label) . '</span></button>'
            . '<span class="tip__content" id="' . $id . '" role="note" hidden><strong>' . Escaper::html($title) . '</strong>'
            . Escaper::html($body) . $extra . '</span></span>';
    }
}

if (!function_exists('ui_csrf')) {
    function ui_csrf(string $token): string
    {
        return '<input type="hidden" name="_csrf" value="' . Escaper::attr($token) . '">';
    }
}
