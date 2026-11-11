<?php

declare(strict_types=1);

namespace App\Domain\Diagnostic;

use InvalidArgumentException;

/**
 * Version immuable du questionnaire (chargée depuis Questionnaires/vN.php par QuestionnaireRegistry).
 */
final class Questionnaire
{
    public const OPTIONS = ['n0', 'n1', 'n2', 'n3'];
    public const UNKNOWN = 'unknown';

    /**
     * @param array<string, array{label: string, description: string, questions: array<string, array{text: string, options: array<string, string>, unknown_policy: string, condition: array<string, mixed>|null, prefill: string|null}>}> $dimensions
     */
    public function __construct(public readonly string $version, public readonly array $dimensions)
    {
        foreach ($dimensions as $code => $dimension) {
            foreach ($dimension['questions'] as $questionCode => $question) {
                if (!str_starts_with($questionCode, $code . '.') || array_keys($question['options']) !== self::OPTIONS
                    || !in_array($question['unknown_policy'], ['evaluable', 'unknown'], true)) {
                    throw new InvalidArgumentException(sprintf('Question mal définie : %s', $questionCode));
                }
            }
        }
    }

    /** @return list<string> */
    public function dimensionCodes(): array
    {
        return array_keys($this->dimensions);
    }

    /**
     * @return array{text: string, options: array<string, string>, unknown_policy: string, condition: array<string, mixed>|null, prefill: string|null}|null
     */
    public function question(string $code): ?array
    {
        $dimension = strstr($code, '.', true);
        if ($dimension === false) {
            return null;
        }

        return $this->dimensions[$dimension]['questions'][$code] ?? null;
    }

    public function isValidAnswer(string $questionCode, string $option): bool
    {
        return $this->question($questionCode) !== null && ($option === self::UNKNOWN || in_array($option, self::OPTIONS, true));
    }

    public function questionCount(): int
    {
        return array_sum(array_map(static fn (array $d): int => count($d['questions']), $this->dimensions));
    }
}
