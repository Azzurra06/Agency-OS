<?php

declare(strict_types=1);

namespace App\Domain\Strategy;

use App\Support\Decimal;

/**
 * Résultat d'une règle déclenchée (« brouillon » de recommandation avant réconciliation avec la base).
 */
final class Finding
{
    /**
     * @param array{sources: list<array<string, mixed>>, values: array<string, string|int|null>, thresholds: array<string, string>, subject?: string, missing?: list<string>, stale?: list<string>} $evidence
     */
    public function __construct(
        public readonly string $ruleCode,
        public readonly string $subjectKey,
        public readonly string $severity,
        public readonly ?string $dimension,
        public readonly int $priority,
        public readonly array $evidence,
        public readonly ?Decimal $impactAmount,
        public readonly string $dataQuality,
    ) {
    }
}
