<?php

declare(strict_types=1);

namespace SkemaService\Ical;

use DateTimeImmutable;
use SkemaService\Schedule\Lesson;

/** En lektion med de metadata kalenderabonnementer kræver. */
final class FeedEvent
{
    public function __construct(
        public readonly string $uid,
        public readonly int $sequence,
        public readonly DateTimeImmutable $lastModified,
        public readonly Lesson $lesson,
    ) {
    }
}
