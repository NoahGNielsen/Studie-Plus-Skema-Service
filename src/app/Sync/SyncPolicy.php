<?php

declare(strict_types=1);

namespace SkemaService\Sync;

use DateTimeImmutable;
use DateTimeZone;
use SkemaService\Schedule\Lesson;

/**
 * Opdateringsplan:
 *  - hvert 15. minut mandag–fredag kl. 06:30–16:00 (dansk tid)
 *  - én gang i timen på alle andre tidspunkter
 *
 * Cron kører hvert 15. minut; denne klasse afgør hvem der skal hentes.
 * Ved gentagne fejl bruges eksponentiel backoff (maks. 4 timer).
 */
final class SyncPolicy
{
    private const SLACK_SECONDS = 90; // cron starter ikke på sekundet
    private const MAX_BACKOFF_MINUTES = 240;

    public function intervalMinutes(DateTimeImmutable $now): int
    {
        $local = $now->setTimezone(new DateTimeZone(Lesson::TIMEZONE));
        $weekday = (int) $local->format('N'); // 1 = mandag
        $minuteOfDay = (int) $local->format('G') * 60 + (int) $local->format('i');

        $isSchoolHours = $weekday <= 5 && $minuteOfDay >= 6 * 60 + 30 && $minuteOfDay <= 16 * 60;

        return $isSchoolHours ? 15 : 60;
    }

    public function effectiveIntervalMinutes(DateTimeImmutable $now, int $failureCount): int
    {
        $interval = $this->intervalMinutes($now);
        if ($failureCount <= 0) {
            return $interval;
        }
        $backoff = min(self::MAX_BACKOFF_MINUTES, 15 * (2 ** min($failureCount, 5)));

        return max($interval, $backoff);
    }

    public function isDue(?DateTimeImmutable $lastAttempt, DateTimeImmutable $now, int $failureCount = 0): bool
    {
        if ($lastAttempt === null) {
            return true;
        }
        $elapsed = $now->getTimestamp() - $lastAttempt->getTimestamp();

        return $elapsed + self::SLACK_SECONDS >= $this->effectiveIntervalMinutes($now, $failureCount) * 60;
    }
}
