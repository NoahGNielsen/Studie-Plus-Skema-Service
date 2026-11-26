<?php

declare(strict_types=1);

use SkemaService\Crypto;
use SkemaService\Credentials;
use SkemaService\Logger;

test('krypto: kryptering og dekryptering', function (): void {
    $c = testCrypto();
    $enc = $c->encrypt('hemmelig-test-kode', 'ad');
    assertNotContains('hemmelig-test-kode', $enc);
    assertSame('hemmelig-test-kode', $c->decrypt($enc, 'ad'));
});

test('krypto: forkert associated data afvises (rækker kan ikke byttes)', function (): void {
    $c = testCrypto();
    $enc = $c->encrypt('x', 'sps|bruger-a|password');
    assertThrows(RuntimeException::class, fn () => $c->decrypt($enc, 'sps|bruger-b|password'));
});

test('krypto: anden nøgle kan ikke dekryptere', function (): void {
    $enc = testCrypto()->encrypt('x', 'ad');
    assertThrows(RuntimeException::class, fn () => (new Crypto(str_repeat("\x02", 32)))->decrypt($enc, 'ad'));
});

test('krypto: tilfældig nonce giver forskellig chiffertekst', function (): void {
    $c = testCrypto();
    assertTrue($c->encrypt('x', 'ad') !== $c->encrypt('x', 'ad'));
});

test('krypto: opslags-hash ignorerer store/små bogstaver og mellemrum', function (): void {
    $c = testCrypto();
    assertSame($c->lookupHash('Elev123'), $c->lookupHash('  elev123 '));
    assertTrue($c->lookupHash('elev123') !== $c->lookupHash('elev124'));
});

test('krypto: feed-token er 256 bit, URL-sikkert og unikt', function (): void {
    $c = testCrypto();
    $a = $c->newFeedToken();
    assertSame(43, strlen($a));
    assertTrue(Crypto::isWellFormedToken($a));
    assertTrue($a !== $c->newFeedToken());
    assertTrue(!Crypto::isWellFormedToken('../../etc/passwd'));
    assertTrue(!Crypto::isWellFormedToken(''));
});

test('krypto: token-hash er deterministisk og ikke tokenet selv', function (): void {
    $c = testCrypto();
    $t = $c->newFeedToken();
    assertSame($c->tokenHash($t), $c->tokenHash($t));
    assertNotContains($t, $c->tokenHash($t));
});

test('krypto: UID er stabil og forskellig pr. bruger', function (): void {
    $c = testCrypto();
    assertSame($c->eventUid(1, 'lektion-42'), $c->eventUid(1, 'lektion-42'));
    assertTrue($c->eventUid(1, 'lektion-42') !== $c->eventUid(2, 'lektion-42'));
    assertTrue(str_ends_with($c->eventUid(1, 'x'), '@studie-plus-skema-service'));
});

test('hemmeligheder: Credentials og Crypto lækker ikke via print_r', function (): void {
    $dump = print_r(new Credentials('elev', 'fake-password-123'), true) . print_r(testCrypto(), true);
    assertNotContains('fake-password-123', $dump);
    assertNotContains('elev', $dump);
});

test('log: redigering fjerner feed-stier og token-lignende strenge', function (): void {
    $token = testCrypto()->newFeedToken();
    $out = Logger::redact("GET /feed/$token.ics password=fake-pass cookie: abc");
    assertNotContains($token, $out);
    assertNotContains('fake-pass', $out);
    assertNotContains('abc', $out);
});
