<?php

declare(strict_types=1);

namespace App\Cron;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Échéance d'une tâche, évaluée en HEURE LOCALE (Europe/Paris) — docs/05-architecture-detaillee.md §4.
 *
 * runKey() renvoie la clé d'exécution de la période en cours si la tâche est due, sinon null.
 * Une tâche quotidienne « dès 02:15 » est due à tout passage où l'heure locale est ≥ 02:15 :
 *  - dernier dimanche de mars : 02:15 n'existe pas, l'exécution part au passage suivant (03:00) ;
 *  - dernier dimanche d'octobre : 02:15 existe deux fois, la clé du jour empêche le doublon.
 */
final class Schedule
{
    private function __construct(private readonly string $type, private readonly int $value)
    {
    }

    public static function everyMinutes(int $minutes): self
    {
        if ($minutes < 15 || 60 % $minutes !== 0) {
            throw new InvalidArgumentException('Intervalle invalide (multiple de 15 minutes divisant 60).');
        }

        return new self('minutes', $minutes);
    }

    public static function hourly(): self
    {
        return new self('hourly', 0);
    }

    public static function dailyFrom(string $time): self
    {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            throw new InvalidArgumentException('Heure invalide (HH:MM).');
        }

        return new self('daily', (int) $m[1] * 60 + (int) $m[2]);
    }

    public function runKey(DateTimeImmutable $localNow): ?string
    {
        return match ($this->type) {
            'minutes' => $localNow->format('Y-m-d\TH:') . str_pad((string) (intdiv((int) $localNow->format('i'), $this->value) * $this->value), 2, '0', STR_PAD_LEFT),
            'hourly' => $localNow->format('Y-m-d\TH'),
            'daily' => ((int) $localNow->format('G') * 60 + (int) $localNow->format('i')) >= $this->value ? $localNow->format('Y-m-d') : null,
            default => null,
        };
    }
}
