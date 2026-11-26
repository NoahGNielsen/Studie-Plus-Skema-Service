<?php

declare(strict_types=1);

namespace SkemaService\StudiePlus;

use SkemaService\Credentials;
use SkemaService\Schedule\DateRange;
use SkemaService\Sync\ScheduleSource;
use SkemaService\Sync\SourceUnavailable;

/**
 * Henter skema fra Studie+ via GWT-RPC (SkemaService.hentEgnePersSkemaData).
 *
 * AFVENTER VERIFIKATION: Request-opbygningen (permutation, policy-hash,
 * typesignaturer) og mapningen af SkemaBegivenhed-felter bygges færdig
 * ud fra et anonymiseret, rigtigt request/svar. Indtil da kaster
 * fetch() SourceUnavailable('gwt_not_verified'), så servicen aldrig
 * publicerer gættede data. Er 'enabled' false, kontaktes Studie+ slet ikke.
 */
final class StudiePlusScheduleSource implements ScheduleSource
{
    public function __construct(
        private readonly bool $enabled,
        private readonly string $baseUrl,
        private readonly string $instnr,
    ) {
    }

    /** @param array<string, mixed> $settings */
    public static function fromSettings(array $settings): self
    {
        return new self(
            (bool) ($settings['enabled'] ?? false),
            (string) ($settings['base_url'] ?? 'https://all.studieplus.dk'),
            (string) ($settings['instnr'] ?? '621401'),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function fetch(Credentials $credentials, DateRange $range): array
    {
        if (!$this->enabled) {
            throw new SourceUnavailable('studieplus_disabled');
        }

        $client = new StudiePlusClient($this->baseUrl, $this->instnr);
        try {
            $client->login($credentials);
            $body = $this->requestSchedule($client, $range);

            return SkemaResponseMapper::map(GwtResponseReader::parse($body));
        } finally {
            $client->close();
        }
    }

    private function requestSchedule(StudiePlusClient $client, DateRange $range): string
    {
        // Datoerne kodes med GwtDate::toRpcFragment($range->firstDay / ->lastDay).
        // Resten af request-body og headers (X-GWT-Permutation, policy-hash,
        // UDate-typesignatur) bygges ud fra det anonymiserede eksempel.
        throw new SourceUnavailable('gwt_not_verified');
    }
}
