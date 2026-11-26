<?php

declare(strict_types=1);

namespace SkemaService\StudiePlus;

use SkemaService\Credentials;
use SkemaService\Logger;
use SkemaService\Schedule\DateRange;
use SkemaService\Schedule\Lesson;
use SkemaService\Sync\ScheduleSource;
use SkemaService\Sync\SourceUnavailable;

/**
 * Henter skema fra Studie+ via GWT-RPC (SkemaService.hentEgnePersSkemaData).
 *
 * Intervallet hentes i bidder på chunk_days dage (standard 7, som browseren).
 * Permutation og policy-hash ændres, når Studie+ udgiver en ny version;
 * så svarer serveren med IncompatibleRemoteServiceException, fejlkoden
 * bliver gwt_incompatible_version, og værdierne skal opdateres i secrets-filen.
 * Er 'enabled' false, kontaktes Studie+ slet ikke.
 */
final class StudiePlusScheduleSource implements ScheduleSource
{
    private const MAX_CHUNK_DAYS = 140;
    private const PAUSE_BETWEEN_REQUESTS_US = 300_000;

    public function __construct(
        private readonly bool $enabled,
        private readonly string $baseUrl,
        private readonly string $instnr,
        private readonly string $permutation = SkemaRequest::DEFAULT_PERMUTATION,
        private readonly string $policy = SkemaRequest::DEFAULT_POLICY,
        private readonly int $chunkDays = 7,
        private readonly ?Logger $logger = null,
    ) {
        if ($this->chunkDays < 1 || $this->chunkDays > self::MAX_CHUNK_DAYS) {
            throw new \InvalidArgumentException('studieplus.chunk_days skal være mellem 1 og ' . self::MAX_CHUNK_DAYS . '.');
        }
        if (preg_match('/^[0-9A-F]{32}$/', $this->permutation) !== 1) {
            throw new \InvalidArgumentException('studieplus.permutation skal være 32 hex-tegn.');
        }
    }

    /** @param array<string, mixed> $settings */
    public static function fromSettings(array $settings, ?Logger $logger = null): self
    {
        return new self(
            (bool) ($settings['enabled'] ?? false),
            (string) ($settings['base_url'] ?? 'https://all.studieplus.dk'),
            (string) ($settings['instnr'] ?? '621401'),
            (string) ($settings['permutation'] ?? SkemaRequest::DEFAULT_PERMUTATION),
            (string) ($settings['policy'] ?? SkemaRequest::DEFAULT_POLICY),
            (int) ($settings['chunk_days'] ?? 7),
            $logger,
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function fetch(Credentials $credentials, DateRange $range): array
    {
        if (!$this->enabled) {
            $this->logger?->info('studieplus_disabled');
            throw new SourceUnavailable('studieplus_disabled');
        }

        return $this->fetchWith(new StudiePlusClient($this->baseUrl, $this->instnr, 30, $this->logger), $credentials, $range);
    }

    /**
     * Logger ind og henter hele intervallet. Bruges også af bin/verify-studieplus.php.
     *
     * @return list<Lesson>
     */
    public function fetchWith(StudiePlusClient $client, Credentials $credentials, DateRange $range): array
    {
        $started = microtime(true);
        $chunks = 0;
        $this->logger?->debug('studieplus_fetch_start', [
            'from' => $range->firstDay->format('Y-m-d'),
            'to' => $range->lastDay->format('Y-m-d'),
            'chunk_days' => $this->chunkDays,
        ]);
        try {
            $client->login($credentials);
            $this->logger?->debug('studieplus_login_done', ['ms' => self::ms($started)]);
            $lessons = [];
            // Til-datoen sendes som dagen efter sidste dag, så sidste dag med sikkerhed er med.
            $end = $range->lastDay->modify('+1 day');
            for ($from = $range->firstDay; $from < $end; $from = $to) {
                if ($from > $range->firstDay) {
                    usleep(self::PAUSE_BETWEEN_REQUESTS_US);
                }
                $to = min($from->modify("+{$this->chunkDays} days"), $end);
                $chunkStarted = microtime(true);
                $chunk = $this->requestChunk($client, $from, $to);
                $chunks++;
                $this->logger?->debug('studieplus_chunk', [
                    'from' => $from->format('Y-m-d'),
                    'to' => $to->format('Y-m-d'),
                    'lessons' => count($chunk),
                    'ms' => self::ms($chunkStarted),
                ]);
                foreach ($chunk as $lesson) {
                    $lessons[$lesson->sourceId] = $lesson;
                }
            }
            $this->logger?->info('studieplus_fetch_done', [
                'chunks' => $chunks,
                'lessons' => count($lessons),
                'cancelled' => count(array_filter($lessons, static fn (Lesson $l): bool => $l->cancelled)),
                'ms' => self::ms($started),
            ]);

            return array_values($lessons);
        } catch (SourceUnavailable $e) {
            $this->logger?->warning('studieplus_fetch_failed', ['code' => $e->errorCode, 'chunks_done' => $chunks, 'ms' => self::ms($started)]);
            throw $e;
        } catch (\Throwable $e) {
            if (!$e instanceof \SkemaService\Sync\AuthenticationFailed) {
                $this->logger?->exception('studieplus_fetch_error', $e, ['chunks_done' => $chunks, 'ms' => self::ms($started)]);
            }
            throw $e;
        } finally {
            $client->close();
        }
    }

    private static function ms(float $since): int
    {
        return (int) round((microtime(true) - $since) * 1000);
    }

    /** @return list<Lesson> */
    private function requestChunk(StudiePlusClient $client, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $base = $client->baseUrl();
        $response = $client->post(
            SkemaRequest::SERVICE_PATH,
            SkemaRequest::body($base . SkemaRequest::MODULE_PATH, $this->policy, $from, $to),
            [
                'Content-Type: text/x-gwt-rpc; charset=UTF-8',
                'X-GWT-Module-Base: ' . $base . SkemaRequest::MODULE_PATH,
                'X-GWT-Permutation: ' . $this->permutation,
                'Origin: ' . $base,
                'Referer: ' . $base . '/skema/',
            ],
            true, // GWT sender //EX med HTTP 500; læs body for at få den rigtige fejlkode
        );
        if (str_starts_with((string) parse_url($response['url'], PHP_URL_PATH), '/login')) {
            throw new SourceUnavailable('session_lost');
        }

        return SkemaResponseMapper::map(GwtResponseReader::parse($response['body']));
    }
}
