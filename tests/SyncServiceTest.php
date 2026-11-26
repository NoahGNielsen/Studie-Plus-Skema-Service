<?php

declare(strict_types=1);

use SkemaService\Feed\FeedService;
use SkemaService\Logger;
use SkemaService\Store\Store;
use SkemaService\Sync\AuthenticationFailed;
use SkemaService\Sync\SourceUnavailable;
use SkemaService\Sync\SyncService;

final class TestClock
{
    public DateTimeImmutable $now;

    public function __construct(string $utc = '2026-12-07 09:00')
    {
        $this->now = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}

/** @return array{InMemoryStore, FakeScheduleSource, SyncService, TestClock, FeedService} */
function syncFixture(): array
{
    $store = new InMemoryStore();
    $source = new FakeScheduleSource();
    $source->passwords = ['elev-a' => 'fake-pass-a', 'elev-b' => 'fake-pass-b'];
    $source->lessons = [
        'elev-a' => [
            lesson('a1', '2026-12-07 08:10', '2026-12-07 09:40', 'Matematik'),
            lesson('a2', '2026-12-08 10:00', '2026-12-08 11:30', 'Dansk'),
        ],
        'elev-b' => [lesson('b1', '2026-12-07 12:00', '2026-12-07 13:30', 'Biologi')],
    ];
    $clock = new TestClock();
    $crypto = testCrypto();
    $logger = new Logger(sys_get_temp_dir() . '/sps-test.log');
    $sync = new SyncService($store, $source, $crypto, fn (): DateTimeImmutable => $clock->now, $logger);

    return [$store, $source, $sync, $clock, new FeedService($store, $crypto)];
}

test('sync: oprettelse gemmer kun krypterede loginoplysninger', function (): void {
    [$store, , $sync] = syncFixture();
    $result = $sync->enroll('elev-a', 'fake-pass-a');
    $user = $store->findUserById($result['userId']);
    assertNotContains('fake-pass-a', $user['password_enc']);
    assertNotContains('elev-a', $user['username_enc'] . $user['lookup_hash']);
    assertNotContains((string) $result['token'], $user['token_hash']);
    assertSame(2, count($store->eventsForUser($result['userId'])));
});

test('sync: forkert adgangskode gemmer intet', function (): void {
    [$store, , $sync] = syncFixture();
    assertThrows(AuthenticationFailed::class, fn () => $sync->enroll('elev-a', 'forkert'));
    assertSame([], $store->users);
});

test('sync: Studie+ nede ved oprettelse gemmer intet', function (): void {
    [$store, $source, $sync] = syncFixture();
    $source->nextError = FakeScheduleSource::unavailable();
    assertThrows(SourceUnavailable::class, fn () => $sync->enroll('elev-a', 'fake-pass-a'));
    assertSame([], $store->users);
});

test('sync: henter 14 dage tilbage og 120 frem', function (): void {
    [, $source, $sync] = syncFixture();
    $sync->enroll('elev-a', 'fake-pass-a');
    assertSame('2026-11-23', $source->lastRange->firstDay->format('Y-m-d'));
    assertSame('2027-04-06', $source->lastRange->lastDay->format('Y-m-d'));
});

test('sync: UID\'er og SEQUENCE er stabile ved uændret skema', function (): void {
    [$store, , $sync, $clock] = syncFixture();
    $id = $sync->enroll('elev-a', 'fake-pass-a')['userId'];
    $before = $store->eventsForUser($id);
    $clock->advance('+15 minutes');
    assertSame(SyncService::OK, $sync->syncUser($id));
    assertSame($before, $store->eventsForUser($id));
});

test('sync: ændret lektion får højere SEQUENCE og ny LAST-MODIFIED', function (): void {
    [$store, $source, $sync, $clock] = syncFixture();
    $id = $sync->enroll('elev-a', 'fake-pass-a')['userId'];
    $old = array_column($store->eventsForUser($id), null, 'uid');

    $source->lessons['elev-a'][0] = lesson('a1', '2026-12-07 08:10', '2026-12-07 09:40', 'Matematik', true);
    $clock->advance('+15 minutes');
    $sync->syncUser($id);
    $new = array_column($store->eventsForUser($id), null, 'uid');

    assertSame(array_keys($old), array_keys($new), 'samme UID\'er');
    $changed = testCrypto()->eventUid($id, 'a1');
    $same = testCrypto()->eventUid($id, 'a2');
    assertSame(1, $new[$changed]['sequence']);
    assertSame('2026-12-07 09:15:00', $new[$changed]['last_modified']);
    assertSame(0, $new[$same]['sequence']);
    assertSame($old[$same]['last_modified'], $new[$same]['last_modified']);
});

test('sync: fjernet lektion forsvinder fra feeden', function (): void {
    [$store, $source, $sync] = syncFixture();
    $id = $sync->enroll('elev-a', 'fake-pass-a')['userId'];
    array_pop($source->lessons['elev-a']);
    $sync->syncUser($id);
    assertSame(1, count($store->eventsForUser($id)));
});

test('sync: fejl i hentning bevarer senest gyldige data', function (): void {
    [$store, $source, $sync] = syncFixture();
    $id = $sync->enroll('elev-a', 'fake-pass-a')['userId'];
    $before = $store->eventsForUser($id);

    $source->nextError = FakeScheduleSource::unavailable();
    assertSame(SyncService::FAILED, $sync->syncUser($id));
    assertSame($before, $store->eventsForUser($id));
    assertSame(1, $store->findUserById($id)['failure_count']);
    assertSame('http_503', $store->findUserById($id)['last_error']);

    $source->nextError = new RuntimeException('uventet');
    assertSame(SyncService::FAILED, $sync->syncUser($id));
    assertSame($before, $store->eventsForUser($id));
});

test('sync: pludseligt tomt svar erstatter først data efter 3 bekræftelser', function (): void {
    [$store, $source, $sync] = syncFixture();
    $id = $sync->enroll('elev-a', 'fake-pass-a')['userId'];
    $before = $store->eventsForUser($id);
    $source->lessons['elev-a'] = [];

    assertSame(SyncService::KEPT_PREVIOUS, $sync->syncUser($id));
    assertSame(SyncService::KEPT_PREVIOUS, $sync->syncUser($id));
    assertSame($before, $store->eventsForUser($id));
    assertSame(SyncService::OK, $sync->syncUser($id));
    assertSame([], $store->eventsForUser($id));
});

test('sync: afvist login stopper automatiske forsøg', function (): void {
    [$store, $source, $sync] = syncFixture();
    $id = $sync->enroll('elev-a', 'fake-pass-a')['userId'];
    $before = $store->eventsForUser($id);
    $source->passwords['elev-a'] = 'skiftet';

    assertSame(SyncService::AUTH_FAILED, $sync->syncUser($id));
    assertSame(Store::STATUS_NEEDS_REAUTH, $store->findUserById($id)['status']);
    assertSame($before, $store->eventsForUser($id));

    $calls = $source->calls;
    assertSame(SyncService::SKIPPED, $sync->syncUser($id));
    assertSame($calls, $source->calls, 'Studie+ må ikke kontaktes igen');
    assertSame([], $store->syncCandidates());
});

test('sync: ingen samtidige hentninger for samme bruger', function (): void {
    [$store, $source, $sync] = syncFixture();
    $id = $sync->enroll('elev-a', 'fake-pass-a')['userId'];
    $calls = $source->calls;
    $store->acquireUserLock($id);
    assertSame(SyncService::LOCKED, $sync->syncUser($id));
    assertSame($calls, $source->calls);
    $store->releaseUserLock($id);
    assertSame(SyncService::OK, $sync->syncUser($id));
});

test('sync: lektioner uden for intervallet og dubletter filtreres', function (): void {
    [$store, $source, $sync] = syncFixture();
    $source->lessons['elev-a'][] = lesson('gammel', '2026-10-01 08:00', '2026-10-01 09:00');
    $source->lessons['elev-a'][] = lesson('a1', '2026-12-07 08:10', '2026-12-07 09:40', 'Dublet');
    $id = $sync->enroll('elev-a', 'fake-pass-a')['userId'];
    assertSame(2, count($store->eventsForUser($id)));
});

test('sync: ny adgangskode med "behold link" bevarer feed-token', function (): void {
    [$store, $source, $sync, , $feed] = syncFixture();
    $first = $sync->enroll('elev-a', 'fake-pass-a');
    $source->passwords['elev-a'] = 'fake-pass-ny';
    $sync->syncUser($first['userId']); // bliver needs_reauth

    $second = $sync->enroll('elev-a', 'fake-pass-ny', true);
    assertSame(null, $second['token']);
    assertSame($first['userId'], $second['userId']);
    assertSame(Store::STATUS_ACTIVE, $store->findUserById($first['userId'])['status']);
    assertTrue($feed->render($first['token']) !== null, 'gammelt link virker stadig');
});

test('sync: genoprettelse uden "behold link" ugyldiggør det gamle link', function (): void {
    [, , $sync, , $feed] = syncFixture();
    $first = $sync->enroll('elev-a', 'fake-pass-a');
    $second = $sync->enroll('elev-a', 'fake-pass-a');
    assertSame(null, $feed->render($first['token']));
    assertTrue($feed->render($second['token']) !== null);
});

test('sync: kontrol af gemte loginoplysninger', function (): void {
    [, , $sync] = syncFixture();
    $id = $sync->enroll('elev-a', 'fake-pass-a')['userId'];
    assertSame($id, $sync->verifyStoredCredentials('ELEV-A', 'fake-pass-a'));
    assertSame(null, $sync->verifyStoredCredentials('elev-a', 'forkert'));
    assertSame(null, $sync->verifyStoredCredentials('ukendt', 'fake-pass-a'));
});
