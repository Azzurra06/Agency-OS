<?php

declare(strict_types=1);

namespace App\Domain\Diagnostic;

use App\Support\Decimal;

/**
 * Calcul du diagnostic (docs/03-formules-et-scoring.md §6, décisions Q2) — fonctions pures, déterministes.
 *
 * Par dimension : A = questions applicables, Ue = « Je ne sais pas » inconnus, n = A − Ue,
 *   Sd = 100 × Σ niveaux ÷ (3 × n), fiabilité Fd = n ÷ A ;
 *   calculable si n ≥ 3 ET Fd ≥ 0,60.
 * Global : moyenne simple des 6 Sd (non arrondis), SEULEMENT si les 6 dimensions sont calculables.
 * Fiabilité globale : Σ n ÷ Σ A.
 */
final class DiagnosticScorer
{
    public const CALC_VERSION = '1.0';
    private const MIN_SCORED = 3;
    private const MIN_RELIABILITY = '0.60';

    /**
     * @param array<string, string> $answers question => n0..n3 | unknown
     * @param array{team_size?: int|null} $context
     */
    public function score(Questionnaire $questionnaire, array $answers, array $context = []): DiagnosticResult
    {
        $dimensions = [];
        $sumScored = 0;
        $sumApplicable = 0;
        $missing = [];
        $unknownExcluded = [];

        foreach ($questionnaire->dimensions as $code => $dimension) {
            $applicable = 0;
            $scored = 0;
            $levels = 0;
            $ue = 0;
            $up = 0;
            foreach ($dimension['questions'] as $questionCode => $question) {
                if (!$this->isApplicable($question['condition'], $answers, $context)) {
                    continue;
                }
                $applicable++;
                $answer = $answers[$questionCode] ?? null;
                if ($answer === null) {
                    $missing[] = $questionCode;
                    continue;
                }
                if ($answer === Questionnaire::UNKNOWN) {
                    if ($question['unknown_policy'] === 'evaluable') {
                        $up++;
                        $scored++; // niveau 0
                    } else {
                        $ue++;
                        $unknownExcluded[] = $questionCode;
                    }
                    continue;
                }
                $scored++;
                $levels += (int) substr($answer, 1);
            }
            $reliability = $applicable === 0 ? Decimal::zero() : Decimal::of($scored)->div(Decimal::of($applicable));
            $scorable = $scored >= self::MIN_SCORED && $reliability->compare(Decimal::of(self::MIN_RELIABILITY)) >= 0;
            $score = $scored === 0 ? null : Decimal::of(100 * $levels)->div(Decimal::of(3 * $scored));
            $dimensions[$code] = new DimensionScore($code, $dimension['label'], $applicable, $scored, $ue, $up, $levels, $scorable ? $score : null, $scorable, $reliability);
            $sumScored += $scored;
            $sumApplicable += $applicable;
        }

        $allScorable = array_reduce($dimensions, static fn (bool $carry, DimensionScore $d): bool => $carry && $d->scorable, true);
        $global = null;
        if ($allScorable) {
            $total = Decimal::zero();
            foreach ($dimensions as $d) {
                $total = $total->add($d->score ?? Decimal::zero());
            }
            $global = $total->div(Decimal::of(count($dimensions)));
        }
        $globalReliability = $sumApplicable === 0 ? Decimal::zero() : Decimal::of($sumScored)->div(Decimal::of($sumApplicable));

        return new DiagnosticResult($questionnaire->version, $dimensions, $global, $globalReliability, $missing, $unknownExcluded);
    }

    /**
     * Questions applicables (pour l'affichage du questionnaire et la complétude).
     *
     * @param array<string, string> $answers
     * @param array{team_size?: int|null} $context
     * @return list<string>
     */
    public function applicableQuestions(Questionnaire $questionnaire, array $answers, array $context = []): array
    {
        $codes = [];
        foreach ($questionnaire->dimensions as $dimension) {
            foreach ($dimension['questions'] as $code => $question) {
                if ($this->isApplicable($question['condition'], $answers, $context)) {
                    $codes[] = $code;
                }
            }
        }

        return $codes;
    }

    /**
     * @param array<string, mixed>|null $condition
     * @param array<string, string> $answers
     * @param array{team_size?: int|null} $context
     */
    private function isApplicable(?array $condition, array $answers, array $context): bool
    {
        if ($condition === null) {
            return true;
        }
        if ($condition['type'] === 'answer_min' && is_string($condition['question'] ?? null) && is_int($condition['level'] ?? null)) {
            $answer = $answers[$condition['question']] ?? null;

            return $answer !== null && $answer !== Questionnaire::UNKNOWN && (int) substr($answer, 1) >= $condition['level'];
        }
        if ($condition['type'] === 'team_min' && is_int($condition['value'] ?? null)) {
            $team = $context['team_size'] ?? null;

            return $team === null || $team >= $condition['value'];
        }

        return true;
    }
}
