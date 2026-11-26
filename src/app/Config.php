<?php

declare(strict_types=1);

namespace SkemaService;

use RuntimeException;

/**
 * Indlæser serverkonfiguration fra studie-plus-skema-service-secrets.php,
 * som skal ligge UDEN FOR webroot (standard: mappen over webroot).
 *
 * Filens indhold må aldrig vises, logges eller indgå i fejlbeskeder.
 */
final class Config
{
    public const SECRETS_FILENAME = 'studie-plus-skema-service-secrets.php';

    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function load(?string $path = null): self
    {
        $path ??= dirname(SPS_WEB_ROOT) . '/' . self::SECRETS_FILENAME;
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new RuntimeException('Konfigurationsfilen blev ikke fundet eller kan ikke læses.');
        }

        // Nægt at bruge en hemmelighedsfil, der ligger i webroot.
        // (realpath('') giver den aktuelle mappe, så tom DOCUMENT_ROOT i CLI springes over.)
        $rawDocRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        $docRoot = $rawDocRoot !== '' ? realpath($rawDocRoot) : false;
        if ($docRoot !== false && str_starts_with($real, rtrim($docRoot, '/\\') . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Konfigurationsfilen ligger i webroot. Flyt den til mappen over webroot.');
        }

        $values = (static fn (string $f): mixed => require $f)($real);
        if (!is_array($values)) {
            throw new RuntimeException('Konfigurationsfilen skal returnere et array.');
        }

        return self::fromArray($values);
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        foreach (['db', 'master_key', 'base_url'] as $required) {
            if (!array_key_exists($required, $values)) {
                throw new RuntimeException("Konfigurationsfeltet '$required' mangler.");
            }
        }

        return new self($values);
    }

    public function get(string $dottedKey, mixed $default = null): mixed
    {
        $current = $this->values;
        foreach (explode('.', $dottedKey) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return $default;
            }
            $current = $current[$part];
        }

        return $current;
    }

    public function masterKey(): string
    {
        $key = base64_decode((string) $this->values['master_key'], true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_KDF_KEYBYTES) {
            throw new RuntimeException('master_key skal være 32 tilfældige bytes, base64-kodet.');
        }

        return $key;
    }

    public function baseUrl(): string
    {
        return rtrim((string) $this->values['base_url'], '/');
    }

    public function isProduction(): bool
    {
        return (bool) ($this->values['production'] ?? true);
    }

    /** Skjul hemmeligheder ved var_dump/print_r. */
    public function __debugInfo(): array
    {
        return ['values' => '[skjult]'];
    }
}
