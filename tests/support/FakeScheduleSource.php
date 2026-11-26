<?php

declare(strict_types=1);

use SkemaService\Credentials;
use SkemaService\Schedule\DateRange;
use SkemaService\Schedule\Lesson;
use SkemaService\Sync\AuthenticationFailed;
use SkemaService\Sync\ScheduleSource;
use SkemaService\Sync\SourceUnavailable;

/** Falsk skemakilde med mockdata pr. (falsk) brugernavn. Kontakter intet. */
final class FakeScheduleSource implements ScheduleSource
{
    /** @var array<string, list<Lesson>> brugernavn => lektioner */
    public array $lessons = [];
    /** @var array<string, string> brugernavn => korrekt (falsk) adgangskode */
    public array $passwords = [];
    public ?Throwable $nextError = null;
    public int $calls = 0;
    public ?DateRange $lastRange = null;

    public function fetch(Credentials $credentials, DateRange $range): array
    {
        $this->calls++;
        $this->lastRange = $range;
        if ($this->nextError !== null) {
            $e = $this->nextError;
            $this->nextError = null;
            throw $e;
        }
        if (($this->passwords[$credentials->username] ?? null) !== $credentials->password()) {
            throw new AuthenticationFailed('afvist');
        }

        return $this->lessons[$credentials->username] ?? [];
    }

    public static function unavailable(): SourceUnavailable
    {
        return new SourceUnavailable('http_503');
    }
}

function lesson(string $id, string $start, string $end, string $title = 'Matematik', bool $cancelled = false, string $note = ''): Lesson
{
    $tz = new DateTimeZone('Europe/Copenhagen');

    return new Lesson($id, $title, new DateTimeImmutable($start, $tz), new DateTimeImmutable($end, $tz), ['Lærer A'], ['Lokale 1'], $note, '', $cancelled);
}

function testCrypto(): SkemaService\Crypto
{
    return new SkemaService\Crypto(str_repeat("\x01", 32));
}
