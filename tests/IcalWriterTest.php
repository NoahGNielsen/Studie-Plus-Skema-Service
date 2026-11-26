<?php

declare(strict_types=1);

use SkemaService\Ical\FeedEvent;
use SkemaService\Ical\IcalWriter;
use SkemaService\Schedule\HtmlToText;
use SkemaService\Schedule\Lesson;

function feedEvent(Lesson $lesson, int $sequence = 0, string $uid = 'uid-1@test'): FeedEvent
{
    return new FeedEvent($uid, $sequence, new DateTimeImmutable('2026-12-01 12:00:00', new DateTimeZone('UTC')), $lesson);
}

function utcLesson(string $startUtc, string $endUtc, string $title = 'Matematik'): Lesson
{
    $utc = new DateTimeZone('UTC');

    return new Lesson('src-1', $title, new DateTimeImmutable($startUtc, $utc), new DateTimeImmutable($endUtc, $utc));
}

/** Folder linjer ud igen (RFC 5545 §3.1). */
function unfold(string $ics): string
{
    return str_replace("\r\n ", '', $ics);
}

test('ical: grundstruktur og CRLF-linjeskift', function (): void {
    $ics = (new IcalWriter())->render([feedEvent(lesson('a', '2026-12-07 08:10', '2026-12-07 09:40'))]);
    assertTrue(str_starts_with($ics, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"));
    assertTrue(str_ends_with($ics, "END:VCALENDAR\r\n"));
    assertSame(0, preg_match('/(?<!\r)\n/', $ics), 'alle linjer skal slutte med CRLF');
    foreach (['PRODID:', 'METHOD:PUBLISH', 'BEGIN:VTIMEZONE', 'TZID:Europe/Copenhagen', 'BEGIN:VEVENT', 'UID:uid-1@test', 'DTSTAMP:20261201T120000Z', 'SEQUENCE:0', 'CLASS:PUBLIC', 'END:VEVENT'] as $needle) {
        assertContains($needle, $ics);
    }
    assertSame(substr_count($ics, 'BEGIN:VEVENT'), substr_count($ics, 'END:VEVENT'));
});

test('ical: vintertid (CET) skrives som lokal tid', function (): void {
    $ics = (new IcalWriter())->render([feedEvent(utcLesson('2026-12-07 07:10', '2026-12-07 08:40'))]);
    assertContains('DTSTART;TZID=Europe/Copenhagen:20261207T081000', $ics);
    assertContains('DTEND;TZID=Europe/Copenhagen:20261207T094000', $ics);
});

test('ical: sommertid (CEST) skrives som lokal tid', function (): void {
    $ics = (new IcalWriter())->render([feedEvent(utcLesson('2026-06-08 06:10', '2026-06-08 07:40'))]);
    assertContains('DTSTART;TZID=Europe/Copenhagen:20260608T081000', $ics);
});

test('ical: dagen for skift til sommertid', function (): void {
    // 29. marts 2026: urene stilles frem kl. 02:00 → 03:00. 08:00 UTC = 10:00 lokal.
    $ics = (new IcalWriter())->render([feedEvent(utcLesson('2026-03-29 08:00', '2026-03-29 09:00'))]);
    assertContains('DTSTART;TZID=Europe/Copenhagen:20260329T100000', $ics);
    // Dagen før (vintertid): 08:00 UTC = 09:00 lokal.
    $ics2 = (new IcalWriter())->render([feedEvent(utcLesson('2026-03-28 08:00', '2026-03-28 09:00'))]);
    assertContains('DTSTART;TZID=Europe/Copenhagen:20260328T090000', $ics2);
});

test('ical: tekst escapes korrekt', function (): void {
    $ics = (new IcalWriter())->render([feedEvent(lesson('a', '2026-12-07 08:00', '2026-12-07 09:00', 'Fysik; kemi, bio\\x'))]);
    assertContains('SUMMARY:Fysik\\; kemi\\, bio\\\\x', $ics);
});

test('ical: beskrivelse indeholder lærer, lokale, note og lektier', function (): void {
    $tz = new DateTimeZone('Europe/Copenhagen');
    $l = new Lesson('a', 'Dansk', new DateTimeImmutable('2026-12-07 08:00', $tz), new DateTimeImmutable('2026-12-07 09:00', $tz),
        ['Lærer A', 'Lærer B'], ['A1.02'], 'Husk bog', 'Læs s. 10-20');
    $ics = unfold((new IcalWriter())->render([feedEvent($l)]));
    assertContains('DESCRIPTION:Lærere: Lærer A\\, Lærer B\\nLokale: A1.02\\n\\nNote:\\nHusk bog\\n\\nLektier:\\nLæs s. 10-20', $ics);
    assertContains('LOCATION:A1.02', $ics);
});

test('ical: lange linjer foldes til højst 75 oktetter uden at splitte UTF-8', function (): void {
    $note = str_repeat('Æblegrød med fløde og ål — ', 20);
    $ics = (new IcalWriter())->render([feedEvent(lesson('a', '2026-12-07 08:00', '2026-12-07 09:00', 'Dansk', false, $note))]);
    foreach (explode("\r\n", rtrim($ics, "\r\n")) as $line) {
        assertTrue(strlen($line) <= 75, 'linje for lang: ' . strlen($line));
        assertTrue(preg_match('//u', $line) === 1, 'linje er ikke gyldig UTF-8');
    }
    assertContains(IcalWriter::escape(trim($note)), unfold($ics));
});

test('ical: aflyst lektion får STATUS:CANCELLED', function (): void {
    $ics = (new IcalWriter())->render([feedEvent(lesson('a', '2026-12-07 08:00', '2026-12-07 09:00', 'Matematik', true), 2)]);
    assertContains('STATUS:CANCELLED', $ics);
    assertContains('SUMMARY:Aflyst: Matematik', $ics);
    assertContains('SEQUENCE:2', $ics);
});

test('ical: samme input giver identisk output', function (): void {
    $events = [feedEvent(lesson('b', '2026-12-08 08:00', '2026-12-08 09:00'), 0, 'b@t'), feedEvent(lesson('a', '2026-12-07 08:00', '2026-12-07 09:00'), 0, 'a@t')];
    assertSame((new IcalWriter())->render($events), (new IcalWriter())->render(array_reverse($events)));
});

test('ical: tom kalender er stadig gyldig', function (): void {
    $ics = (new IcalWriter())->render([]);
    assertContains('BEGIN:VCALENDAR', $ics);
    assertContains('END:VCALENDAR', $ics);
    assertNotContains('BEGIN:VEVENT', $ics);
});

test('ical: HTML-noter ender som ren tekst uden tags', function (): void {
    $note = HtmlToText::convert('<b>Vigtigt</b><script>alert(1)</script> &lt;img src=x onerror=alert(1)&gt;');
    $ics = (new IcalWriter())->render([feedEvent(lesson('a', '2026-12-07 08:00', '2026-12-07 09:00', 'Dansk', false, $note))]);
    assertNotContains('<', $ics);
    assertNotContains('alert', $ics);
    assertContains('Vigtigt', $ics);
});
