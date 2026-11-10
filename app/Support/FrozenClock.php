<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Horloge figée, pour les tests (changement d'heure, expirations…).
 */
final class FrozenClock extends Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $utcDateTime, string $businessTimezone = 'Europe/Paris')
    {
        parent::__construct($businessTimezone);
        $this->now = new DateTimeImmutable($utcDateTime, new DateTimeZone('UTC'));
    }

    public function nowUtc(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
