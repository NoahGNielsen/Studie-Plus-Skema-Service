<?php

declare(strict_types=1);

use SkemaService\Schedule\HtmlToText;

test('html: script og style fjernes helt', function (): void {
    assertSame('Hej', HtmlToText::convert('<p>Hej</p><script>alert(1)</script><style>p{}</style>'));
});

test('html: uafsluttet script fjernes', function (): void {
    assertSame('Før', HtmlToText::convert('Før<script>alert(1)'));
});

test('html: linjeskift og afsnit bevares', function (): void {
    assertSame("Linje 1\nLinje 2\n\nAfsnit", HtmlToText::convert('<p>Linje 1<br>Linje 2</p><p>Afsnit</p>'));
});

test('html: lister bliver til punkter', function (): void {
    assertSame("• Et\n• To", HtmlToText::convert('<ul><li>Et</li><li>To</li></ul>'));
});

test('html: entities dekodes', function (): void {
    assertSame('Tom & Jerry "æøå"', HtmlToText::convert('Tom &amp; Jerry&nbsp;&quot;&aelig;&oslash;&aring;&quot;'));
});

test('html: entity-kodet markup neutraliseres', function (): void {
    $out = HtmlToText::convert('&lt;script&gt;alert(1)&lt;/script&gt;Tekst &lt;img src=x onerror=alert(1)&gt;');
    assertSame('Tekst', $out);
});

test('html: løse vinkeltegn bevares som harmløse tegn', function (): void {
    assertSame('Jeg ‹3 matematik og 2 › 1', HtmlToText::convert('Jeg &lt;3 matematik og 2 &gt; 1'));
});

test('html: javascript-links kasseres, teksten bevares', function (): void {
    assertSame('Klik her', HtmlToText::convert('<a href="javascript:alert(1)">Klik her</a>'));
});

test('html: http(s)-links bevares som tekst', function (): void {
    assertSame('Opgave (https://example.org/opg)', HtmlToText::convert('<a href="https://example.org/opg">Opgave</a>'));
});

test('html: kontroltegn fjernes', function (): void {
    assertSame('AB', HtmlToText::convert("A\x07\x00B"));
});

test('html: ugyldig UTF-8 giver gyldig UTF-8', function (): void {
    assertTrue(preg_match('//u', HtmlToText::convert("ok \xC3\x28 ok")) === 1);
});

test('html: længdebegrænsning', function (): void {
    $out = HtmlToText::convert(str_repeat('a', 5000), 100);
    assertSame(100, mb_strlen($out));
    assertTrue(str_ends_with($out, '…'));
});
