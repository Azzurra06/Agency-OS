<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Cron\Schedule;
use App\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

/**
 * Échéances en heure de Paris, y compris les jours de changement d'heure (docs/05 §4.2).
 */
final class ScheduleTest extends TestCase
{
    private static function local(string $utc): \DateTimeImmutable
    {
        return (new FrozenClock($utc))->nowLocal();
    }

    public function testDailyFromQuarterPastTwoInWinter(): void
    {
        $schedule = Schedule::dailyFrom('02:15');
        // Hiver (UTC+1) : 01:00 UTC = 02:00 Paris → pas encore due ; 01:15 UTC = 02:15 Paris → due.
        self::assertNull($schedule->runKey(self::local('2026-11-12 01:00:00')));
        self::assertSame('2026-11-12', $schedule->runKey(self::local('2026-11-12 01:15:00')));
        self::assertSame('2026-11-12', $schedule->runKey(self::local('2026-11-12 22:45:00')));
    }

    public function testDailyInSummer(): void
    {
        // Été (UTC+2) : 00:15 UTC = 02:15 Paris.
        self::assertSame('2026-07-01', Schedule::dailyFrom('02:15')->runKey(self::local('2026-07-01 00:15:00')));
        self::assertNull(Schedule::dailyFrom('02:15')->runKey(self::local('2026-07-01 00:00:00')));
    }

    public function testSpringForwardDayStillRunsOnce(): void
    {
        // 28/03/2027 : 02:00 → 03:00 à Paris. 02:15 n'existe pas ; à 01:00 UTC il est 03:00 à Paris → due.
        $schedule = Schedule::dailyFrom('02:15');
        self::assertNull($schedule->runKey(self::local('2027-03-28 00:45:00')));  // 01:45 Paris
        self::assertSame('2027-03-28', $schedule->runKey(self::local('2027-03-28 01:00:00'))); // 03:00 Paris
    }

    public function testFallBackDayKeepsSingleKey(): void
    {
        // 25/10/2026 : 02:15 Paris existe deux fois (00:15 UTC puis 01:15 UTC) → même clé, donc un seul passage.
        $schedule = Schedule::dailyFrom('02:15');
        self::assertSame('2026-10-25', $schedule->runKey(self::local('2026-10-25 00:15:00')));
        self::assertSame('2026-10-25', $schedule->runKey(self::local('2026-10-25 01:15:00')));
    }

    public function testHourlyAndQuarterHourKeys(): void
    {
        $local = self::local('2026-11-12 09:37:00'); // 10:37 Paris
        self::assertSame('2026-11-12T10', Schedule::hourly()->runKey($local));
        self::assertSame('2026-11-12T10:30', Schedule::everyMinutes(15)->runKey($local));
    }
}
