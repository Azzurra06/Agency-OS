<?php

declare(strict_types=1);

namespace App\Domain\Diagnostic;

use App\Support\Decimal;

/**
 * Résultat d'un diagnostic : scores par dimension, score global (ou null si diagnostic partiel), fiabilité,
 * forces et axes d'amélioration (docs/03-formules-et-scoring.md §6.3).
 */
final class DiagnosticResult
{
    /**
     * @param array<string, DimensionScore> $dimensions
     * @param list<string> $missing questions applicables sans réponse
     * @param list<string> $unknownExcluded réponses « Je ne sais pas » inconnues (exclues)
     */
    public function __construct(
        public readonly string $version,
        public readonly array $dimensions,
        public readonly ?Decimal $global,
        public readonly Decimal $globalReliability,
        public readonly array $missing,
        public readonly array $unknownExcluded,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->missing === [];
    }

    /** « complete » si les 6 dimensions sont calculables, sinon « partial » (Diagnostic partiel). */
    public function completeness(): string
    {
        return $this->global === null ? 'partial' : 'complete';
    }

    /** @return list<string> */
    public function unscorableDimensions(): array
    {
        return array_values(array_map(static fn (DimensionScore $d): string => $d->code, array_filter($this->dimensions, static fn (DimensionScore $d): bool => !$d->scorable)));
    }

    /**
     * Les 2 meilleures dimensions calculables avec un score ≥ 60 (égalité : ordre des dimensions).
     *
     * @return list<string>
     */
    public function strengths(): array
    {
        $eligible = array_filter($this->dimensions, static fn (DimensionScore $d): bool => $d->scorable && $d->score !== null && $d->score->compare(Decimal::of('60')) >= 0);

        return $this->top($eligible, -1);
    }

    /**
     * Les 2 dimensions calculables les plus faibles avec un score < 60.
     *
     * @return list<string>
     */
    public function weaknesses(): array
    {
        $eligible = array_filter($this->dimensions, static fn (DimensionScore $d): bool => $d->scorable && $d->score !== null && $d->score->compare(Decimal::of('60')) < 0);

        return $this->top($eligible, 1);
    }

    public static function level(?Decimal $score): ?string
    {
        if ($score === null) {
            return null;
        }

        return match (true) {
            $score->compare(Decimal::of('80')) >= 0 => 'Avancé',
            $score->compare(Decimal::of('60')) >= 0 => 'Structuré',
            $score->compare(Decimal::of('40')) >= 0 => 'En construction',
            default => 'Fragile',
        };
    }

    public static function reliabilityLabel(Decimal $reliability): string
    {
        return match (true) {
            $reliability->compare(Decimal::of('0.90')) >= 0 => 'Fiabilité élevée',
            $reliability->compare(Decimal::of('0.75')) >= 0 => 'Fiabilité moyenne',
            default => 'Fiabilité faible',
        };
    }

    /**
     * @param array<string, DimensionScore> $dimensions
     * @return list<string>
     */
    private function top(array $dimensions, int $direction): array
    {
        $order = array_flip(array_keys($this->dimensions));
        $list = array_values($dimensions);
        usort($list, static function (DimensionScore $a, DimensionScore $b) use ($direction, $order): int {
            $cmp = ($a->score ?? Decimal::zero())->compare($b->score ?? Decimal::zero()) * $direction;

            return $cmp !== 0 ? $cmp : $order[$a->code] <=> $order[$b->code];
        });

        return array_map(static fn (DimensionScore $d): string => $d->code, array_slice($list, 0, 2));
    }
}
