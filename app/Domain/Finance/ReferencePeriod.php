<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Support\Decimal;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Période de référence des montants déclarés (docs/03-formules-et-scoring.md §1).
 *
 * Durée D en mois : nombre de mois calendaires entiers entre le début et le lendemain de la fin ;
 * sinon jours ÷ 30,4375 arrondi à 0,1. Annualisation : X × 12 ÷ D (multiplier avant de diviser).
 */
final class ReferencePeriod
{
    public const CLOSED_FISCAL_YEAR = 'closed_fiscal_year';
    public const TRAILING_12_MONTHS = 'trailing_12_months';

    public readonly Decimal $months;

    public function __construct(
        public readonly string $type,
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
    ) {
        if (!in_array($type, [self::CLOSED_FISCAL_YEAR, self::TRAILING_12_MONTHS], true)) {
            throw new InvalidArgumentException('Type de période invalide.');
        }
        if ($start >= $end) {
            throw new InvalidArgumentException('La date de début doit précéder la date de fin.');
        }
        $this->months = self::computeMonths($start, $end);
        if ($this->months->compare(Decimal::of('1')) < 0 || $this->months->compare(Decimal::of('24')) > 0) {
            throw new InvalidArgumentException('La période doit durer entre 1 et 24 mois.');
        }
    }

    public static function fromStrings(string $type, string $start, string $end): self
    {
        $utc = new DateTimeZone('UTC');

        return new self($type, new DateTimeImmutable($start, $utc), new DateTimeImmutable($end, $utc));
    }

    /** Montant ramené à 12 mois. */
    public function annualize(Decimal $amount): Decimal
    {
        return $amount->mul(Decimal::of('12'))->div($this->months);
    }

    public function isTwelveMonths(): bool
    {
        return $this->months->equals(Decimal::of('12'));
    }

    /** Libellé affiché partout où un chiffre dépend de la période. */
    public function label(): string
    {
        $kind = $this->type === self::CLOSED_FISCAL_YEAR ? 'Exercice clos' : '12 derniers mois';
        $months = rtrim(rtrim($this->months->toFixed(1), '0'), '.');

        return sprintf('%s · %s → %s · %s mois', $kind, $this->start->format('d/m/Y'), $this->end->format('d/m/Y'), str_replace('.', ',', $months));
    }

    private static function computeMonths(DateTimeImmutable $start, DateTimeImmutable $end): Decimal
    {
        $afterEnd = $end->modify('+1 day');
        $diff = $start->diff($afterEnd);
        if ($diff->d === 0 && $diff->invert === 0) {
            return Decimal::of($diff->y * 12 + $diff->m);
        }
        $days = (int) $diff->days;

        return Decimal::of($days)->div(Decimal::of('30.4375'))->round(1);
    }
}
