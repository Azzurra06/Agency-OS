<?php

declare(strict_types=1);

namespace App\Support;

use JsonException;

/**
 * Échappement contextuel des sorties (docs/04-securite.md §5).
 * Toute donnée affichée dans une vue passe par l'une de ces fonctions.
 */
final class Escaper
{
    /** Contenu HTML (texte entre balises). */
    public static function html(string|int|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** Valeur d'attribut HTML (toujours entre guillemets doubles dans les vues). */
    public static function attr(string|int|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Données injectées dans <script type="application/json"> : aucun caractère ne permet
     * de fermer la balise ou d'ouvrir un commentaire HTML.
     *
     * @param array<mixed>|scalar|null $data
     * @throws JsonException
     */
    public static function jsonScript(array|string|int|float|bool|null $data): string
    {
        return json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
