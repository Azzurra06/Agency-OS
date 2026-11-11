<?php

declare(strict_types=1);

namespace App\Domain\Strategy;

use App\Support\Decimal;
use DateTimeImmutable;

/**
 * Faits consolidés d'une organisation, seule entrée du moteur de règles (déterministe, sans accès à la base).
 * Ne contient que des données réelles ou FIGÉES : les brouillons (simulations, scénarios) n'y figurent jamais.
 *
 * @phpstan-type ServiceFact array{public_id: string, name: string, price: Decimal|null, volume: int|null,
 *   simulation: array{public_id: string, version: int, finalized_at: string, floor: Decimal, recommended: Decimal, variable_rate: Decimal, drift: bool}|null}
 * @phpstan-type OfferFact array{public_id: string, name: string, contracts: int, monthly_result: Decimal|null, margin: Decimal|null}
 * @phpstan-type DiagnosticFact array{public_id: string, completed_at: string, completeness: string, reliability: Decimal,
 *   dimensions: array<string, array{label: string, scorable: bool, score: Decimal|null}>}
 */
final class Facts
{
    /**
     * @param list<string> $hourlyCostAssumptions
     * @param list<ServiceFact> $services services ACTIFS du catalogue
     * @param list<OfferFact> $offers formules ACTIVES
     * @param DiagnosticFact|null $diagnostic dernier diagnostic TERMINÉ
     */
    public function __construct(
        public readonly DateTimeImmutable $today,
        public readonly ?Decimal $estimatedResult,
        public readonly ?Decimal $hourlyCost,
        public readonly array $hourlyCostAssumptions,
        public readonly ?Decimal $revenueAnnualized,
        public readonly ?Decimal $revenueTarget,
        public readonly ?Decimal $declaredShare,
        public readonly int $profileCompleteness,
        public readonly ?string $profileUpdatedAt,
        public readonly ?string $periodEnd,
        public readonly ?string $periodLabel,
        public readonly array $services,
        public readonly array $offers,
        public readonly ?Decimal $coverage,
        public readonly ?Decimal $coverageGapPoints,
        public readonly Decimal $recurringHours,
        public readonly ?Decimal $monthlyCapacity,
        public readonly ?string $contractsUpdatedAt,
        public readonly ?array $diagnostic,
    ) {
    }
}
