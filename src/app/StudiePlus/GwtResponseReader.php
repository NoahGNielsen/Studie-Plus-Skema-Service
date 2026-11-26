<?php

declare(strict_types=1);

namespace SkemaService\StudiePlus;

use JsonException;
use SkemaService\Sync\SourceUnavailable;

/**
 * Generisk læser af GWT-RPC-svar ("//OK[...]").
 *
 * Svaret er et JavaScript-array: [token..., [strengtabel], flags, version].
 * Tokens læses BAGFRA (som GWT-klienten gør). Strenge refereres med
 * 1-baseret indeks i strengtabellen (0 = null).
 *
 * Denne klasse kender intet til Studie+'s typer; den typespecifikke
 * fortolkning sker i en separat mapper, så formatantagelser er samlet ét sted.
 */
final class GwtResponseReader
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * @param list<mixed>  $tokens
     * @param list<string> $strings
     */
    private function __construct(
        private readonly array $tokens,
        private readonly array $strings,
        public readonly int $version,
        public readonly int $flags,
        private int $index,
    ) {
    }

    public static function parse(string $body): self
    {
        if (strlen($body) > self::MAX_BYTES) {
            throw new SourceUnavailable('gwt_response_too_large');
        }
        $body = ltrim($body, "\xEF\xBB\xBF \t\r\n");
        if (str_starts_with($body, '//EX')) {
            throw new SourceUnavailable('gwt_remote_exception');
        }
        if (!str_starts_with($body, '//OK')) {
            throw new SourceUnavailable('gwt_unexpected_response');
        }

        $json = substr($body, 4);
        // Store svar deles af GWT som [..].concat([..]).
        $json = str_replace('].concat([', ',', $json);
        // GWT bruger JavaScript-escapes (\xHH), som JSON ikke kender.
        $json = preg_replace_callback(
            '/(?<!\\\\)((?:\\\\\\\\)*)\\\\x([0-9a-fA-F]{2})/',
            static fn (array $m): string => $m[1] . '\\u00' . $m[2],
            $json,
        ) ?? '';

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new SourceUnavailable('gwt_parse_error');
        }

        if (!is_array($data) || !array_is_list($data) || count($data) < 3) {
            throw new SourceUnavailable('gwt_parse_error');
        }
        $version = array_pop($data);
        $flags = array_pop($data);
        $strings = array_pop($data);
        if (!is_int($version) || !is_int($flags) || !is_array($strings)) {
            throw new SourceUnavailable('gwt_parse_error');
        }
        foreach ($strings as $s) {
            if (!is_string($s)) {
                throw new SourceUnavailable('gwt_parse_error');
            }
        }

        return new self($data, array_values($strings), $version, $flags, count($data));
    }

    public function remaining(): int
    {
        return $this->index;
    }

    public function readToken(): mixed
    {
        if ($this->index <= 0) {
            throw new SourceUnavailable('gwt_unexpected_end');
        }

        return $this->tokens[--$this->index];
    }

    public function readInt(): int
    {
        $token = $this->readToken();
        if (is_int($token)) {
            return $token;
        }
        if (is_float($token) && floor($token) === $token) {
            return (int) $token;
        }
        throw new SourceUnavailable('gwt_type_mismatch');
    }

    public function readBoolean(): bool
    {
        return $this->readInt() !== 0;
    }

    public function readDouble(): float
    {
        $token = $this->readToken();
        if (!is_int($token) && !is_float($token)) {
            throw new SourceUnavailable('gwt_type_mismatch');
        }

        return (float) $token;
    }

    /** GWT serialiserer long som base64-lignende streng (A-Z a-z 0-9 $ _). */
    public function readLong(): int
    {
        $token = $this->readToken();
        if (is_int($token)) {
            return $token;
        }
        if (!is_string($token) || preg_match('/^[A-Za-z0-9$_]{1,12}$/', $token) !== 1) {
            throw new SourceUnavailable('gwt_type_mismatch');
        }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789$_';
        $value = 0;
        foreach (str_split($token) as $char) {
            $value = ($value << 6) | strpos($alphabet, $char);
        }

        return $value;
    }

    public function readString(): ?string
    {
        $ref = $this->readInt();
        if ($ref === 0) {
            return null;
        }
        if ($ref < 0 || !array_key_exists($ref - 1, $this->strings)) {
            throw new SourceUnavailable('gwt_bad_string_ref');
        }

        return $this->strings[$ref - 1];
    }

    /** @return list<string> */
    public function strings(): array
    {
        return $this->strings;
    }
}
