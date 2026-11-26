<?php

declare(strict_types=1);

namespace SkemaService;

use PDOException;
use Throwable;

/**
 * Minimal logger. Kaldere må kun give ufarlige værdier (bruger-id,
 * fejlkoder, antal). Undtagelser logges uden beskeder fra PDO, da disse
 * kan indeholde databasebrugernavn/-vært.
 */
final class Logger
{
    public function __construct(private readonly ?string $file = null)
    {
    }

    /** @param array<string, scalar|null> $context */
    public function info(string $event, array $context = []): void
    {
        $this->write('INFO', $event, $context);
    }

    /** @param array<string, scalar|null> $context */
    public function warning(string $event, array $context = []): void
    {
        $this->write('WARN', $event, $context);
    }

    /** @param array<string, scalar|null> $context */
    public function exception(string $event, Throwable $e, array $context = []): void
    {
        $context['exception'] = $e::class;
        $context['at'] = basename($e->getFile()) . ':' . $e->getLine();
        if (!$e instanceof PDOException) {
            $context['message'] = self::redact($e->getMessage());
        }
        $this->write('ERROR', $event, $context);
    }

    /** Fjerner alt der ligner tokens, feed-stier eller cookies. */
    public static function redact(string $text): string
    {
        $text = preg_replace('#/feed/[^\s"\']+#', '/feed/[skjult]', $text) ?? '';
        $text = preg_replace('/[A-Za-z0-9_\-+\/=]{32,}/', '[skjult]', $text) ?? '';
        $text = preg_replace('/(cookie|pass(word)?|token|secret|user)\s*[:=]\s*\S+/i', '$1=[skjult]', $text) ?? '';

        return substr($text, 0, 300);
    }

    /** @param array<string, scalar|null> $context */
    private function write(string $level, string $event, array $context): void
    {
        $line = gmdate('Y-m-d\TH:i:s\Z') . " $level $event";
        foreach ($context as $key => $value) {
            $line .= ' ' . $key . '=' . str_replace(["\n", "\r", ' '], ['\n', '', '_'], (string) $value);
        }
        if ($this->file !== null && @file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX) !== false) {
            return;
        }
        error_log($line);
    }
}
