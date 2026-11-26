<?php

declare(strict_types=1);

test('feed: brugere ser kun deres eget skema', function (): void {
    [, , $sync, , $feed] = syncFixture();
    $a = $sync->enroll('elev-a', 'fake-pass-a');
    $b = $sync->enroll('elev-b', 'fake-pass-b');

    $icsA = (string) $feed->render($a['token']);
    $icsB = (string) $feed->render($b['token']);
    assertContains('SUMMARY:Matematik', $icsA);
    assertContains('SUMMARY:Dansk', $icsA);
    assertNotContains('Biologi', $icsA);
    assertContains('SUMMARY:Biologi', $icsB);
    assertNotContains('Matematik', $icsB);
});

test('feed: ugyldigt og ukendt token giver ingen kalender', function (): void {
    [, , $sync, , $feed] = syncFixture();
    $sync->enroll('elev-a', 'fake-pass-a');
    assertSame(null, $feed->render(''));
    assertSame(null, $feed->render('ikke-et-token'));
    assertSame(null, $feed->render(testCrypto()->newFeedToken()));
});

test('feed: tilbagekaldt (roteret) token virker ikke længere', function (): void {
    [, , $sync, , $feed] = syncFixture();
    $a = $sync->enroll('elev-a', 'fake-pass-a');
    $new = $sync->rotateToken($a['userId']);
    assertSame(null, $feed->render($a['token']));
    assertContains('SUMMARY:Matematik', (string) $feed->render($new));
});

test('feed: slettet bruger har ingen kalender og ingen data', function (): void {
    [$store, , $sync, , $feed] = syncFixture();
    $a = $sync->enroll('elev-a', 'fake-pass-a');
    $store->deleteUser($a['userId']);
    assertSame(null, $feed->render($a['token']));
    assertSame([], $store->eventsForUser($a['userId']));
});

test('feed: UID\'er i feeden er stabile mellem hentninger', function (): void {
    [, , $sync, $clock, $feed] = syncFixture();
    $a = $sync->enroll('elev-a', 'fake-pass-a');
    preg_match_all('/^UID:(.+)$/m', (string) $feed->render($a['token']), $first);
    $clock->advance('+1 hour');
    $sync->syncUser($a['userId']);
    preg_match_all('/^UID:(.+)$/m', (string) $feed->render($a['token']), $second);
    assertSame(2, count($first[1]));
    assertSame($first[1], $second[1]);
});

test('feed: inaktive brugere slettes automatisk', function (): void {
    [$store, , $sync, , $feed] = syncFixture();
    $a = $sync->enroll('elev-a', 'fake-pass-a');
    $feed->render($a['token'], new DateTimeImmutable('2026-12-07'));
    assertSame(0, $store->deleteInactiveUsers((new DateTimeImmutable('2027-01-01'))->modify('-90 days')));
    assertSame(1, $store->deleteInactiveUsers((new DateTimeImmutable('2027-06-01'))->modify('-90 days')));
    assertSame(null, $store->findUserById($a['userId']));
});
