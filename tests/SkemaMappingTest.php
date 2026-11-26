<?php

declare(strict_types=1);

use SkemaService\Schedule\Lesson;
use SkemaService\StudiePlus\GwtResponseReader;
use SkemaService\StudiePlus\SkemaRequest;
use SkemaService\StudiePlus\SkemaResponseMapper;
use SkemaService\Sync\SourceUnavailable;

/*
 * Tests mod et rigtigt svar fra Studie+ (uge 48, 2026), anonymiseret:
 * navne, initialer, lokaler, hold, noter og elevens id'er er erstattet.
 * Tal og struktur er bevaret.
 */

function skemaFixture(): string
{
    return (string) file_get_contents(__DIR__ . '/fixtures/skema-response.txt');
}

/** @return array<string, Lesson> */
function skemaLessons(?string $body = null): array
{
    $out = [];
    foreach (SkemaResponseMapper::map(GwtResponseReader::parse($body ?? skemaFixture())) as $lesson) {
        $out[$lesson->sourceId] = $lesson;
    }

    return $out;
}

test('skema-request: matcher browserens kald byte for byte', function (): void {
    $tz = new DateTimeZone('Europe/Copenhagen');
    $body = SkemaRequest::body(
        'https://all.studieplus.dk/skema/skema/',
        '5BE4D5F01665D46AC05E7085B8ECDF64',
        new DateTimeImmutable('2026-11-23', $tz),
        new DateTimeImmutable('2026-11-29', $tz),
    );
    assertSame(file_get_contents(__DIR__ . '/fixtures/skema-request.txt'), $body);
});

test('skema-request: afviser | i værdier', function (): void {
    $d = new DateTimeImmutable('2026-11-23');
    assertThrows(InvalidArgumentException::class, fn () => SkemaRequest::body('https://x|y/', SkemaRequest::DEFAULT_POLICY, $d, $d));
});

test('skema-svar: alle 33 lektioner i ugen læses', function (): void {
    $lessons = skemaLessons();
    assertSame(33, count($lessons));
    $days = array_count_values(array_map(static fn (Lesson $l) => $l->start->format('Y-m-d'), $lessons));
    ksort($days);
    assertSame(['2026-11-23' => 6, '2026-11-24' => 6, '2026-11-25' => 8, '2026-11-26' => 7, '2026-11-27' => 6], $days);
});

test('skema-svar: almindelig lektion med lærer, lokale og tid', function (): void {
    $l = skemaLessons()['2309078'];
    assertSame('Tek.fag DDU', $l->title);
    assertSame('2026-11-24T10:20:00', $l->start->format('Y-m-d\TH:i:s'));
    assertSame('2026-11-24T11:20:00', $l->end->format('Y-m-d\TH:i:s'));
    assertSame('Europe/Copenhagen', $l->start->getTimezone()->getName());
    assertSame(['LÆRER1'], $l->teachers);
    assertSame(['LOKALE1'], $l->rooms);
    assertSame('', $l->note);
    assertSame('', $l->homework);
    assertSame(false, $l->cancelled);
});

test('skema-svar: begivenhedstekst kommer med i titlen', function (): void {
    $lessons = skemaLessons();
    assertSame('Tek.fag DDU – VIRTUEL UNDERVISNING', $lessons['2291570']->title);
    assertSame('150 timer pulje – SOP SKRIVEDAG U/VEJLEDNING', $lessons['2316518']->title);
});

test('skema-svar: flere lokaler og lærere, og ingen lærer', function (): void {
    $lessons = skemaLessons();
    assertSame(['LOKALE3', 'LOKALE4', 'LOKALE1', 'LOKALE2'], $lessons['2316518']->rooms);
    assertSame([], $lessons['2316518']->teachers);
    assertSame(15, count($lessons['2316530']->teachers));
});

test('skema-svar: note og lektier fra SkemaNote2, konverteret til tekst', function (): void {
    $l = skemaLessons()['2341602'];
    assertSame('Idéhistorie', $l->title);
    assertSame('LEKTIE1: gør analysen klar til 1. time.', $l->homework);
    assertSame('NOTE3: færdiggør analysen og lav slides.', $l->note);
});

test('skema-svar: HTML-entities og linjeskift i noter', function (): void {
    $lessons = skemaLessons();
    assertSame("NOTE1: send en mødeinvitation inden timerne.\n\nKontakt LÆRER1 via Studie+.", $lessons['2291570']->note);
    assertContains("NOTE5: skrivedag med mulighed for hjemmearbejde.\n\nKontakt vejlederen på forhånd.", $lessons['2316518']->note);
    assertNotContains('<', $lessons['2316529']->note);
    assertNotContains('&quot;', $lessons['2316529']->note);
});

test('skema-svar: kun lektioner med en note får en note', function (): void {
    $withNote = array_keys(array_filter(skemaLessons(), static fn (Lesson $l) => $l->note !== ''));
    sort($withNote);
    assertSame(['2291570', '2316518', '2316529', '2341602', '2341603'], $withNote);
});

test('skema-svar: fravær og elevdata kommer aldrig med i lektionerne', function (): void {
    $all = json_encode(array_map(static fn (Lesson $l) => $l->toArray(), array_values(skemaLessons())), JSON_UNESCAPED_UNICODE);
    foreach (['fravær', 'Fravær', 'Sygdom', 'REG1', 'EFTERNAVN', 'FORNAVN', 'bruger1', 'BW-AUTO'] as $word) {
        assertNotContains($word, (string) $all);
    }
});

test('skema-svar: ukendt typesignatur afvises', function (): void {
    $body = str_replace('SkemaBegivenhed/531356383', 'SkemaBegivenhed/999', skemaFixture());
    $e = assertThrows(SourceUnavailable::class, fn () => skemaLessons($body));
    assertSame('gwt_unknown_type', $e->errorCode);
});

test('skema-svar: afkortet svar afvises', function (): void {
    // Fjern det første token (det sidste der læses): så mangler der data til sidst.
    $body = preg_replace('#^//OK\[\d+,#', '//OK[', skemaFixture());
    assertThrows(SourceUnavailable::class, fn () => skemaLessons($body));
});

test('skema-svar: ekstra data efter objektet afvises', function (): void {
    $body = preg_replace('#^//OK\[#', '//OK[0,', skemaFixture());
    $e = assertThrows(SourceUnavailable::class, fn () => skemaLessons($body));
    assertSame('gwt_trailing_data', $e->errorCode);
});

test('skema-svar: ukendt begivenhedsstatus (fx aflyst) giver fejl, ikke gæt', function (): void {
    // Statusenum'en optræder ét sted som "80,0" (type 80, ordinal 0) og ellers som tilbagereference.
    $body = skemaFixture();
    assertSame(1, substr_count($body, ',0,80,0,'));
    $body = str_replace(',0,80,0,', ',1,80,0,', $body);
    $e = assertThrows(SourceUnavailable::class, fn () => skemaLessons($body));
    assertSame('gwt_unknown_event_status', $e->errorCode);
});

test('skema-svar: IncompatibleRemoteServiceException giver egen fejlkode', function (): void {
    $e = assertThrows(SourceUnavailable::class, fn () => GwtResponseReader::parse(
        '//EX[2,1,["com.google.gwt.user.client.rpc.IncompatibleRemoteServiceException/3936916533","x"],0,7]'
    ));
    assertSame('gwt_incompatible_version', $e->errorCode);
});
