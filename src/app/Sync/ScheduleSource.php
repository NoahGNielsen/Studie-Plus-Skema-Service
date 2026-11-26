<?php

declare(strict_types=1);

namespace SkemaService\Sync;

use SkemaService\Credentials;
use SkemaService\Schedule\DateRange;
use SkemaService\Schedule\Lesson;

/** En kilde til skemadata (i produktion Studie+, i tests en fake). */
interface ScheduleSource
{
    /**
     * @return list<Lesson>
     *
     * @throws AuthenticationFailed hvis loginoplysningerne afvises
     * @throws SourceUnavailable    ved alle andre fejl (netværk, format, vedligehold)
     */
    public function fetch(Credentials $credentials, DateRange $range): array;
}
