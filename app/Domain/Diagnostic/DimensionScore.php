<?php

declare(strict_types=1);

namespace App\Domain\Diagnostic;

use App\Support\Decimal;

final class DimensionScore
{
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly int $applicable,
        public readonly int $scored,
        public readonly int $unknownExcluded,
        public readonly int $unknownEvaluable,
        public readonly int $levels,
        public readonly ?Decimal $score,
        public readonly bool $scorable,
        public readonly Decimal $reliability,
    ) {
    }
}
