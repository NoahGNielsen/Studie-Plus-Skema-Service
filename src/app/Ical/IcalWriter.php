<?php

declare(strict_types=1);

namespace SkemaService\Ical;

use DateTimeImmutable;
use DateTimeZone;
use SkemaService\Schedule\Lesson;

/**
 * Genererer en iCalendar-feed (RFC 5545) til abonnement.
 *
 * - Tider skrives som lokal tid med TZID=Europe/Copenhagen og en
 *   medfølgende VTIMEZONE, så sommer-/vintertid håndteres af klienten.
 * - Aflyste lektioner får STATUS:CANCELLED; fjernede lektioner udelades
 *   (abonnenter fjerner dem ved næste opdatering).
 * - Ændrede lektioner får højere SEQUENCE og ny LAST-MODIFIED.
 */
final class IcalWriter
{
    private const CRLF = "\r\n";

    public function __construct(
        private readonly string $calendarName = 'Skema (Studie+ Skema Service)',
        private readonly string $prodId = '-//Studie Plus Skema Service//Skema//DA',
    ) {
    }

    /** @param list<FeedEvent> $events */
    public function render(array $events): string
    {
        usort($events, static fn (FeedEvent $a, FeedEvent $b): int => [$a->lesson->start, $a->uid] <=> [$b->lesson->start, $b->uid]);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . self::escape($this->prodId),
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'NAME:' . self::escape($this->calendarName),
            'X-WR-CALNAME:' . self::escape($this->calendarName),
            'X-WR-TIMEZONE:' . Lesson::TIMEZONE,
            'REFRESH-INTERVAL;VALUE=DURATION:PT15M',
            'X-PUBLISHED-TTL:PT15M',
            ...self::vtimezone(),
        ];

        foreach ($events as $event) {
            array_push($lines, ...$this->event($event));
        }
        $lines[] = 'END:VCALENDAR';

        return implode(self::CRLF, array_map([self::class, 'fold'], $lines)) . self::CRLF;
    }

    /** @return list<string> */
    private function event(FeedEvent $event): array
    {
        $lesson = $event->lesson;
        $stamp = self::utc($event->lastModified);
        $summary = $lesson->cancelled ? 'Aflyst: ' . $lesson->title : $lesson->title;

        $lines = [
            'BEGIN:VEVENT',
            'UID:' . self::escape($event->uid),
            'DTSTAMP:' . $stamp,
            'LAST-MODIFIED:' . $stamp,
            'SEQUENCE:' . max(0, $event->sequence),
            'DTSTART;TZID=' . Lesson::TIMEZONE . ':' . self::local($lesson->start),
            'DTEND;TZID=' . Lesson::TIMEZONE . ':' . self::local($lesson->end),
            'SUMMARY:' . self::escape($summary),
        ];
        if ($lesson->rooms !== []) {
            $lines[] = 'LOCATION:' . self::escape(implode(', ', $lesson->rooms));
        }
        $description = self::description($lesson);
        if ($description !== '') {
            $lines[] = 'DESCRIPTION:' . self::escape($description);
        }
        $lines[] = 'CLASS:PUBLIC';
        $lines[] = 'STATUS:' . ($lesson->cancelled ? 'CANCELLED' : 'CONFIRMED');
        $lines[] = 'TRANSP:' . ($lesson->cancelled ? 'TRANSPARENT' : 'OPAQUE');
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    public static function description(Lesson $lesson): string
    {
        $parts = [];
        if ($lesson->cancelled) {
            $parts[] = 'Lektionen er aflyst.';
        }
        if ($lesson->teachers !== []) {
            $parts[] = (count($lesson->teachers) > 1 ? 'Lærere: ' : 'Lærer: ') . implode(', ', $lesson->teachers);
        }
        if ($lesson->rooms !== []) {
            $parts[] = (count($lesson->rooms) > 1 ? 'Lokaler: ' : 'Lokale: ') . implode(', ', $lesson->rooms);
        }
        if ($lesson->note !== '') {
            $parts[] = "\nNote:\n" . $lesson->note;
        }
        if ($lesson->homework !== '') {
            $parts[] = "\nLektier:\n" . $lesson->homework;
        }

        return trim(implode("\n", $parts));
    }

    /** Escaping af TEXT-værdier (RFC 5545 afsnit 3.3.11). */
    public static function escape(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text) ?? '';

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
    }

    /** Folder linjer til højst 75 oktetter uden at splitte UTF-8-tegn. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            $chars = str_split($line);
        }
        $out = '';
        $current = '';
        $limit = 75;
        foreach ($chars as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $out .= $current . self::CRLF . ' ';
                $current = '';
                $limit = 74; // det foranstillede mellemrum tæller med
            }
            $current .= $char;
        }

        return $out . $current;
    }

    private static function local(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone(Lesson::TIMEZONE))->format('Ymd\THis');
    }

    private static function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /** @return list<string> */
    private static function vtimezone(): array
    {
        return [
            'BEGIN:VTIMEZONE',
            'TZID:Europe/Copenhagen',
            'X-LIC-LOCATION:Europe/Copenhagen',
            'BEGIN:DAYLIGHT',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0200',
            'TZNAME:CEST',
            'DTSTART:19700329T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100',
            'TZNAME:CET',
            'DTSTART:19701025T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
            'END:STANDARD',
            'END:VTIMEZONE',
        ];
    }
}
