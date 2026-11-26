<?php

declare(strict_types=1);

namespace SkemaService\Schedule;

/**
 * Konverterer HTML-noter/lektier fra Studie+ til ren tekst.
 *
 * Resultatet indeholder ingen HTML-tags — heller ikke tags der var
 * entity-kodede (fx "&lt;script&gt;"), da nogle kalenderapps viser
 * HTML i beskrivelsen. Tilbageværende '<' og '>' erstattes med ‹ ›.
 * Kun http(s)-links bevares, som synlig tekst.
 */
final class HtmlToText
{
    public static function convert(string $html, int $maxLength = 4000): string
    {
        $s = mb_scrub($html, 'UTF-8');

        // Links: behold teksten og evt. en http(s)-adresse. javascript:, data: osv. kasseres.
        $s = preg_replace_callback(
            '#<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a\s*>#is',
            static function (array $m): string {
                $href = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $text = trim(self::stripMarkup($m[3]));
                if (preg_match('#^https?://[^\s<>"]+$#i', $href) !== 1) {
                    return $text;
                }
                if ($text === '' || $text === $href) {
                    return ' ' . $href . ' ';
                }

                return $text . ' (' . $href . ')';
            },
            $s,
        ) ?? '';

        // Strukturelle tags bliver til linjeskift/punkter.
        $s = preg_replace('#<br\s*/?>#i', "\n", $s) ?? '';
        $s = preg_replace('#<li\b[^>]*>#i', "\n• ", $s) ?? '';
        $s = preg_replace('#</?(p|div|tr|h[1-6]|ul|ol|table|blockquote|section|article)\b[^>]*>#i', "\n", $s) ?? '';

        $s = self::stripMarkup($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Entity-kodet markup er nu "rigtig" markup — fjern den igen og neutralisér rester.
        $s = self::stripMarkup($s);
        $s = str_replace(['<', '>'], ['‹', '›'], $s);

        // Whitespace og kontroltegn.
        $s = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $s);
        $s = preg_replace('/[^\P{C}\n]/u', '', $s) ?? '';
        $s = preg_replace('/[ \t]+/u', ' ', $s) ?? '';
        $s = preg_replace('/ *\n */u', "\n", $s) ?? '';
        $s = preg_replace('/\n{3,}/u', "\n\n", $s) ?? '';
        $s = trim($s);

        if (mb_strlen($s, 'UTF-8') > $maxLength) {
            $s = rtrim(mb_substr($s, 0, $maxLength - 1, 'UTF-8')) . '…';
        }

        return $s;
    }

    /** Fjerner farlige elementer inkl. indhold, kommentarer og alle tags (men ikke løse '<'). */
    private static function stripMarkup(string $s): string
    {
        $s = preg_replace('#<(script|style|head|title|iframe|object|embed|noscript|template|svg|math)\b[^>]*>.*?</\1\s*>#is', ' ', $s) ?? '';
        $s = preg_replace('#<(script|style|iframe|object|embed|noscript|template|svg|math)\b[^>]*>.*$#is', ' ', $s) ?? ''; // uafsluttet
        $s = preg_replace('#<!--.*?(-->|$)#s', ' ', $s) ?? '';
        $s = preg_replace('#<![^>]*>#', ' ', $s) ?? '';

        return preg_replace('#</?[a-zA-Z][^>]*>#', '', $s) ?? '';
    }
}
