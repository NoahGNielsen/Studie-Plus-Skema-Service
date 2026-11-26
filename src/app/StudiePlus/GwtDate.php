<?php

declare(strict_types=1);

namespace SkemaService\StudiePlus;

use DateTimeImmutable;
use DateTimeZone;
use SkemaService\Schedule\Lesson;

/**
 * Datofelterne i en UDate i Studie+'s GWT-RPC-kald (verificeret mod
 * browserens kald 26.11.2026; i request'et står "5|6|" foran):
 *   år-1900 | nulbaseret måned | dag | time | minut | sekund
 * Eksempel: 7. december 2026 → 126|11|7|0|0|0
 */
final class GwtDate
{
    /** @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int} */
    public static function toFields(DateTimeImmutable $date): array
    {
        $local = $date->setTimezone(new DateTimeZone(Lesson::TIMEZONE));

        return [
            (int) $local->format('Y') - 1900,
            (int) $local->format('n') - 1,
            (int) $local->format('j'),
            0,
            0,
            0,
        ];
    }

    public static function toRpcFragment(DateTimeImmutable $date): string
    {
        return implode('|', self::toFields($date));
    }
}
