<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Horloge injectable : stockage en UTC, décisions métier en heure de Paris.
 */
class Clock
{
    public function __construct(private readonly string $businessTimezone = 'Europe/Paris')
    {
    }

    public function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function nowLocal(): DateTimeImmutable
    {
        return $this->nowUtc()->setTimezone(new DateTimeZone($this->businessTimezone));
    }

    public function businessTimezone(): DateTimeZone
    {
        return new DateTimeZone($this->businessTimezone);
    }
}
