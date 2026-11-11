<?php

declare(strict_types=1);

namespace App\Domain\Diagnostic;

use InvalidArgumentException;

/**
 * Accès aux versions du questionnaire. Un diagnostic est toujours interprété avec SA version d'origine.
 */
final class QuestionnaireRegistry
{
    public const CURRENT = 'v1';

    /** @var array<string, Questionnaire> */
    private static array $cache = [];

    public static function current(): Questionnaire
    {
        return self::get(self::CURRENT);
    }

    public static function get(string $version): Questionnaire
    {
        if (!preg_match('/^v\d{1,3}$/', $version)) {
            throw new InvalidArgumentException('Version de questionnaire invalide.');
        }
        if (!isset(self::$cache[$version])) {
            $file = self::path($version);
            if (!is_file($file)) {
                throw new InvalidArgumentException('Version de questionnaire inconnue.');
            }
            /** @var array{version: string, dimensions: array<string, array{label: string, description: string, questions: array<string, array{text: string, options: array<string, string>, unknown_policy: string, condition: array<string, mixed>|null, prefill: string|null}>}>} $definition */
            $definition = require $file;
            self::$cache[$version] = new Questionnaire($definition['version'], $definition['dimensions']);
        }

        return self::$cache[$version];
    }

    public static function path(string $version): string
    {
        return __DIR__ . '/Questionnaires/' . $version . '.php';
    }
}
