<?php

declare(strict_types=1);

use App\Support\Escaper;

/*
 * Raccourcis d'échappement utilisés dans les vues. Aucune donnée n'est affichée sans eux.
 */

if (!function_exists('e')) {
    function e(string|int|null $value): string
    {
        return Escaper::html($value);
    }
}

if (!function_exists('attr')) {
    function attr(string|int|null $value): string
    {
        return Escaper::attr($value);
    }
}

if (!function_exists('json_script')) {
    /**
     * @param array<mixed>|scalar|null $data
     */
    function json_script(array|string|int|float|bool|null $data): string
    {
        return Escaper::jsonScript($data);
    }
}
