<?php

declare(strict_types=1);

namespace SkemaService\Schedule;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Det datointerval der hentes og publiceres: fra $daysBack dage før i dag
 * (kl. 00:00) til og med $daysForward dage efter i dag, i dansk tid.
 */
final class DateRange
{
    public const DEFAULT_DAYS_BACK = 14;
    public const DEFAULT_DAYS_FORWARD = 120;

    private function __construct(
        public readonly DateTimeImmutable $firstDay,
        public readonly DateTimeImmutable $lastDay,
    ) {
    }

    public static function around(
        DateTimeImmutable $now,
        int $daysBack = self::DEFAULT_DAYS_BACK,
        int $daysForward = self::DEFAULT_DAYS_FORWARD,
    ): self {
        $today = $now->setTimezone(new DateTimeZone(Lesson::TIMEZONE))->setTime(0, 0);

        return new self($today->modify("-$daysBack days"), $today->modify("+$daysForward days"));
    }

    /** En lektion er med, hvis den overlapper intervallet. */
    public function overlaps(Lesson $lesson): bool
    {
        return $lesson->end > $this->firstDay && $lesson->start < $this->lastDay->modify('+1 day');
    }
}
