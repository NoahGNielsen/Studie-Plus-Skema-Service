<?php

declare(strict_types=1);

use SkemaService\StudiePlus\GwtDate;
use SkemaService\StudiePlus\GwtResponseReader;
use SkemaService\Sync\SourceUnavailable;

test('gwt-dato: 7. december 2026 → 126|11|7', function (): void {
    $d = new DateTimeImmutable('2026-12-07', new DateTimeZone('Europe/Copenhagen'));
    assertSame([126, 11, 7, 0, 0, 0], GwtDate::toFields($d));
});

test('gwt-dato: 31. januar 2027 → 127|0|31', function (): void {
    $d = new DateTimeImmutable('2027-01-31', new DateTimeZone('Europe/Copenhagen'));
    assertSame('127|0|31|0|0|0', GwtDate::toRpcFragment($d));
});

test('gwt-dato: bruger dansk dato, ikke UTC', function (): void {
    // 23:30 UTC d. 6. dec = 00:30 dansk tid d. 7. dec
    $d = new DateTimeImmutable('2026-12-06 23:30', new DateTimeZone('UTC'));
    assertSame([126, 11, 7, 0, 0, 0], GwtDate::toFields($d));
});

test('gwt-læser: læser tokens bagfra med strengtabel', function (): void {
    $r = GwtResponseReader::parse('//OK[0,2,1,5,["Hej","Verden"],0,7]');
    assertSame(7, $r->version);
    assertSame(5, $r->readInt());
    assertSame('Hej', $r->readString());
    assertSame('Verden', $r->readString());
    assertSame(null, $r->readString());
    assertSame(0, $r->remaining());
});

test('gwt-læser: håndterer ].concat([ i store svar', function (): void {
    $r = GwtResponseReader::parse('//OK[1,2].concat([3,["x"],0,7])');
    assertSame(3, $r->readInt());
    assertSame(2, $r->readInt());
    assertSame(1, $r->readInt());
});

test('gwt-læser: JavaScript \\x-escapes i strenge', function (): void {
    $r = GwtResponseReader::parse('//OK[1,["a\x3Cb"],0,7]');
    assertSame('a<b', $r->readString());
});

test('gwt-læser: long kodet som base64-streng', function (): void {
    $r = GwtResponseReader::parse('//OK["BA",[],0,7]');
    assertSame(64, $r->readLong());
});

test('gwt-læser: //EX giver fejl, ikke tomt resultat', function (): void {
    $e = assertThrows(SourceUnavailable::class, fn () => GwtResponseReader::parse('//EX[2,["x"],0,7]'));
    assertSame('gwt_remote_exception', $e->errorCode);
});

test('gwt-læser: HTML-fejlside afvises', function (): void {
    $e = assertThrows(SourceUnavailable::class, fn () => GwtResponseReader::parse('<html><title>Studie+ Login</title></html>'));
    assertSame('gwt_unexpected_response', $e->errorCode);
});

test('gwt-læser: afkortet svar afvises', function (): void {
    $e = assertThrows(SourceUnavailable::class, fn () => GwtResponseReader::parse('//OK[1,2,["a"'));
    assertSame('gwt_parse_error', $e->errorCode);
});

test('gwt-læser: læsning ud over enden giver fejl', function (): void {
    $r = GwtResponseReader::parse('//OK[[],0,7]');
    assertThrows(SourceUnavailable::class, fn () => $r->readInt());
});

test('gwt-læser: ugyldig strengreference giver fejl', function (): void {
    $r = GwtResponseReader::parse('//OK[9,["a"],0,7]');
    assertThrows(SourceUnavailable::class, fn () => $r->readString());
});
