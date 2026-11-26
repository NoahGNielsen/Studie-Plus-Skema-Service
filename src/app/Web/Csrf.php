<?php

declare(strict_types=1);

namespace SkemaService\Web;

/**
 * CSRF-beskyttelse: synkroniseret token i sessionen + tjek af Origin-header.
 * Sessionscookien har desuden SameSite=Strict.
 */
final class Csrf
{
    private const KEY = 'csrf';

    public static function token(): string
    {
        if (!isset($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::KEY];
    }

    /** @param array<string, mixed> $post @param array<string, mixed> $server */
    public static function isValid(array $post, array $server, string $baseUrl): bool
    {
        $expected = $_SESSION[self::KEY] ?? null;
        $given = $post['csrf'] ?? null;
        if (!is_string($expected) || !is_string($given) || !hash_equals($expected, $given)) {
            return false;
        }
        $origin = $server['HTTP_ORIGIN'] ?? null;
        if (is_string($origin) && $origin !== '' && $origin !== 'null') {
            return rtrim($origin, '/') === self::origin($baseUrl);
        }

        return true;
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }
}
