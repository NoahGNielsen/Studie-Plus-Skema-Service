<?php

declare(strict_types=1);

use SkemaService\Schedule\DateRange;
use SkemaService\Schedule\Lesson;
use SkemaService\Sync\SyncPolicy;

function cph(string $local): DateTimeImmutable
{
    return new DateTimeImmutable($local, new DateTimeZone('Europe/Copenhagen'));
}

test('interval: 14 dage tilbage og 120 dage frem', function (): void {
    $r = DateRange::around(cph('2026-12-07 10:00'));
    assertSame('2026-11-23 00:00', $r->firstDay->format('Y-m-d H:i'));
    assertSame('2027-04-06 00:00', $r->lastDay->format('Y-m-d H:i'));
});

test('interval: grænser for lektioner', function (): void {
    $r = DateRange::around(cph('2026-12-07 10:00'));
    assertTrue(!$r->overlaps(lesson('a', '2026-11-22 08:00', '2026-11-22 09:00')), 'før intervallet');
    assertTrue($r->overlaps(lesson('b', '2026-11-23 08:00', '2026-11-23 09:00')), 'første dag');
    assertTrue($r->overlaps(lesson('c', '2027-04-06 15:00', '2027-04-06 16:00')), 'sidste dag');
    assertTrue(!$r->overlaps(lesson('d', '2027-04-07 08:00', '2027-04-07 09:00')), 'efter intervallet');
});

test('interval: tidszone for "i dag" er dansk', function (): void {
    // 23:30 UTC søndag = 00:30 mandag i Danmark
    $r = DateRange::around(new DateTimeImmutable('2026-12-06 23:30', new DateTimeZone('UTC')));
    assertSame('2026-11-23', $r->firstDay->format('Y-m-d'));
});

test('lektion: sluttid før starttid afvises', function (): void {
    assertThrows(InvalidArgumentException::class, fn () => lesson('a', '2026-12-07 09:00', '2026-12-07 08:00'));
});

test('lektion: round-trip via array bevarer indhold og hash', function (): void {
    $l = lesson('a', '2026-07-01 08:00', '2026-07-01 09:30', 'Engelsk', true, 'Note');
    $copy = Lesson::fromArray($l->toArray());
    assertSame($l->contentHash(), $copy->contentHash());
    assertSame('2026-07-01T08:00:00', $copy->toArray()['start']);
});

test('lektion: tom titel bliver "Lektion"', function (): void {
    assertSame('Lektion', lesson('a', '2026-12-07 08:00', '2026-12-07 09:00', "  \x07 ")->title);
});

test('plan: hverdag 06:29 → hver time, 06:30 → hvert kvarter', function (): void {
    $p = new SyncPolicy();
    assertSame(60, $p->intervalMinutes(cph('2026-12-07 06:29')));
    assertSame(15, $p->intervalMinutes(cph('2026-12-07 06:30')));
});

test('plan: hverdag 16:00 → kvarter, 16:01 → time', function (): void {
    $p = new SyncPolicy();
    assertSame(15, $p->intervalMinutes(cph('2026-12-11 16:00')));
    assertSame(60, $p->intervalMinutes(cph('2026-12-11 16:01')));
});

test('plan: weekend → hver time', function (): void {
    $p = new SyncPolicy();
    assertSame(60, $p->intervalMinutes(cph('2026-12-05 10:00')));
    assertSame(60, $p->intervalMinutes(cph('2026-12-06 10:00')));
});

test('plan: sommertid regnes i dansk tid (UTC-input)', function (): void {
    $p = new SyncPolicy();
    // Fredag 12. juni 2026 04:30 UTC = 06:30 CEST
    assertSame(15, $p->intervalMinutes(new DateTimeImmutable('2026-06-12 04:30', new DateTimeZone('UTC'))));
    // Mandag 7. dec 2026 05:30 UTC = 06:30 CET
    assertSame(15, $p->intervalMinutes(new DateTimeImmutable('2026-12-07 05:30', new DateTimeZone('UTC'))));
    assertSame(60, $p->intervalMinutes(new DateTimeImmutable('2026-12-07 05:29', new DateTimeZone('UTC'))));
});

test('plan: forfalden-beregning med cron hvert 15. minut', function (): void {
    $p = new SyncPolicy();
    $now = cph('2026-12-07 10:00');
    assertTrue($p->isDue(null, $now), 'aldrig hentet');
    assertTrue(!$p->isDue($now->modify('-10 minutes'), $now), '10 min om dagen');
    assertTrue($p->isDue($now->modify('-15 minutes'), $now), '15 min om dagen');
    $night = cph('2026-12-07 22:00');
    assertTrue(!$p->isDue($night->modify('-30 minutes'), $night), '30 min om natten');
    assertTrue($p->isDue($night->modify('-60 minutes'), $night), '60 min om natten');
});

test('plan: backoff ved gentagne fejl', function (): void {
    $p = new SyncPolicy();
    $now = cph('2026-12-07 10:00');
    assertSame(60, $p->effectiveIntervalMinutes($now, 2));
    assertSame(240, $p->effectiveIntervalMinutes($now, 10));
    assertTrue(!$p->isDue($now->modify('-30 minutes'), $now, 2));
});
