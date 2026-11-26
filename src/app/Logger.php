<?php

declare(strict_types=1);

namespace SkemaService;

use PDOException;
use Throwable;

/**
 * Driftslog. Kaldere må kun give ufarlige værdier (bruger-id, fejlkoder,
 * antal, tider, faste stier). Aldrig brugernavne, adgangskoder, cookies,
 * feed-tokens eller svar-bodies. Undtagelser logges uden beskeder fra PDO,
 * da disse kan indeholde databasebrugernavn/-vært.
 *
 * Linjeformat:  <tid> <niveau> <kanal>#<request-id> <hændelse> nøgle=værdi ...
 * Alle linjer fra samme request/cron-kørsel deler request-id.
 */
final class Logger
{
    private const LEVELS = ['DEBUG' => 10, 'INFO' => 20, 'WARN' => 30, 'ERROR' => 40];

    private const FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];

    private readonly int $minLevel;
    private readonly string $prefix;

    /**
     * @param string $level laveste niveau der skrives: debug, info, warn eller error
     */
    public function __construct(private readonly ?string $file = null, string $level = 'debug')
    {
        $this->minLevel = self::LEVELS[self::normalizeLevel($level)];
        $channel = PHP_SAPI === 'cli' ? 'cli' : 'web';
        $this->prefix = $channel . '#' . bin2hex(random_bytes(4));
    }

    /** @param array<string, scalar|null> $context */
    public function debug(string $event, array $context = []): void
    {
        $this->write('DEBUG', $event, $context);
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
    public function error(string $event, array $context = []): void
    {
        $this->write('ERROR', $event, $context);
    }

    /** @param array<string, scalar|null> $context */
    public function exception(string $event, Throwable $e, array $context = []): void
    {
        $context += self::describe($e);
        $previous = $e->getPrevious();
        if ($previous !== null) {
            $context['previous'] = $previous::class;
            $context['previous_at'] = basename($previous->getFile()) . ':' . $previous->getLine();
        }
        $this->write('ERROR', $event, $context);
    }

    /**
     * Logger PHP-advarsler/-notices og fatale fejl (fx timeout, hukommelse).
     * PHP's egen fejlhåndtering kører stadig bagefter.
     */
    public function capturePhpErrors(): void
    {
        set_error_handler(function (int $type, string $message, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $type) !== 0) {
                $this->write('WARN', 'php_' . self::errorName($type), [
                    'message' => self::redact($message),
                    'at' => basename($file) . ':' . $line,
                ]);
            }

            return false;
        });
        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], self::FATAL_TYPES, true)) {
                $this->write('ERROR', 'php_fatal', [
                    'type' => self::errorName($error['type']),
                    'message' => self::redact($error['message']),
                    'at' => basename($error['file']) . ':' . $error['line'],
                    'memory_peak_kb' => intdiv(memory_get_peak_usage(true), 1024),
                ]);
            }
        });
    }

    /** Fjerner alt der ligner tokens, feed-stier eller cookies. */
    public static function redact(string $text): string
    {
        $text = preg_replace('#/feed/[^\s"\']+#', '/feed/[skjult]', $text) ?? '';
        $text = preg_replace('/;jsessionid=[^\s\/?#]*/i', ';jsessionid=[skjult]', $text) ?? '';
        $text = preg_replace('/[A-Za-z0-9_\-+\/=]{32,}/', '[skjult]', $text) ?? '';
        $text = preg_replace('/(cookie|pass(word)?|token|secret|user)\s*[:=]\s*\S+/i', '$1=[skjult]', $text) ?? '';

        return substr($text, 0, 300);
    }

    /** @return array<string, scalar|null> */
    private static function describe(Throwable $e): array
    {
        $context = [
            'exception' => $e::class,
            'at' => basename($e->getFile()) . ':' . $e->getLine(),
        ];
        if ($e instanceof PDOException) {
            // Kun SQLSTATE/driverkode (fx 1045 adgang nægtet, 1049 ukendt database, 2002 vært utilgængelig) — aldrig beskeden.
            $context['sqlstate'] = (string) $e->getCode();
            $context['driver_code'] = isset($e->errorInfo[1]) ? (string) $e->errorInfo[1] : null;
        } else {
            $context['message'] = self::redact($e->getMessage());
            if ($e->getCode() !== 0) {
                $context['code'] = (string) $e->getCode();
            }
        }
        // Øverste kaldsled (kun fil:linje, aldrig argumenter).
        $trace = [];
        foreach (array_slice($e->getTrace(), 0, 5) as $frame) {
            if (isset($frame['file'])) {
                $trace[] = basename($frame['file']) . ':' . ($frame['line'] ?? 0);
            }
        }
        if ($trace !== []) {
            $context['trace'] = implode('<', $trace);
        }

        return $context;
    }

    private static function normalizeLevel(string $level): string
    {
        $level = strtoupper(trim($level));
        $level = $level === 'WARNING' ? 'WARN' : $level;

        return isset(self::LEVELS[$level]) ? $level : 'DEBUG';
    }

    private static function errorName(int $type): string
    {
        return match ($type) {
            E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'warning',
            E_NOTICE, E_USER_NOTICE => 'notice',
            E_DEPRECATED, E_USER_DEPRECATED => 'deprecated',
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => 'error',
            E_PARSE => 'parse_error',
            default => 'error_' . $type,
        };
    }

    /** @param array<string, scalar|null> $context */
    private function write(string $level, string $event, array $context): void
    {
        if (self::LEVELS[$level] < $this->minLevel) {
            return;
        }
        $line = gmdate('Y-m-d\TH:i:s\Z') . " $level {$this->prefix} $event";
        foreach ($context as $key => $value) {
            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $line .= ' ' . $key . '=' . str_replace(["\n", "\r", ' '], ['\n', '', '_'], $value);
        }
        if ($this->file !== null && @file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX) !== false) {
            return;
        }
        error_log($line);
    }
}
