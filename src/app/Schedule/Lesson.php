<?php

declare(strict_types=1);

namespace SkemaService\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Én lektion — kun de felter kalenderen har brug for.
 * Fraværsdata og andre oplysninger fra Studie+ må ikke tilføjes her.
 *
 * Tekstfelter (note, homework) er allerede konverteret til ren tekst.
 */
final class Lesson
{
    public const TIMEZONE = 'Europe/Copenhagen';
    private const LOCAL_FORMAT = 'Y-m-d\TH:i:s';
    private const MAX_FIELD = 200;
    private const MAX_TEXT = 4000;

    public readonly string $title;
    public readonly DateTimeImmutable $start;
    public readonly DateTimeImmutable $end;
    /** @var list<string> */
    public readonly array $teachers;
    /** @var list<string> */
    public readonly array $rooms;
    public readonly string $note;
    public readonly string $homework;

    /**
     * @param list<string> $teachers
     * @param list<string> $rooms
     */
    public function __construct(
        public readonly string $sourceId,
        string $title,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        array $teachers = [],
        array $rooms = [],
        string $note = '',
        string $homework = '',
        public readonly bool $cancelled = false,
    ) {
        if ($sourceId === '') {
            throw new InvalidArgumentException('Lektion mangler kilde-id.');
        }
        if ($end <= $start) {
            throw new InvalidArgumentException('Lektionens sluttid skal ligge efter starttiden.');
        }
        $tz = new DateTimeZone(self::TIMEZONE);
        $this->start = $start->setTimezone($tz);
        $this->end = $end->setTimezone($tz);
        $cleanTitle = self::clean($title, self::MAX_FIELD);
        $this->title = $cleanTitle !== '' ? $cleanTitle : 'Lektion';
        $this->teachers = self::cleanList($teachers);
        $this->rooms = self::cleanList($rooms);
        $this->note = self::clean($note, self::MAX_TEXT, true);
        $this->homework = self::clean($homework, self::MAX_TEXT, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sourceId' => $this->sourceId,
            'title' => $this->title,
            'start' => $this->start->format(self::LOCAL_FORMAT),
            'end' => $this->end->format(self::LOCAL_FORMAT),
            'teachers' => $this->teachers,
            'rooms' => $this->rooms,
            'note' => $this->note,
            'homework' => $this->homework,
            'cancelled' => $this->cancelled,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $tz = new DateTimeZone(self::TIMEZONE);

        return new self(
            (string) $data['sourceId'],
            (string) $data['title'],
            new DateTimeImmutable((string) $data['start'], $tz),
            new DateTimeImmutable((string) $data['end'], $tz),
            array_values(array_map('strval', (array) ($data['teachers'] ?? []))),
            array_values(array_map('strval', (array) ($data['rooms'] ?? []))),
            (string) ($data['note'] ?? ''),
            (string) ($data['homework'] ?? ''),
            (bool) ($data['cancelled'] ?? false),
        );
    }

    /** Samme lektion, markeret som aflyst. */
    public function asCancelled(): self
    {
        return self::fromArray(['cancelled' => true] + $this->toArray());
    }

    /** Ændres når en kalender-relevant egenskab ændres (bruges til SEQUENCE). */
    public function contentHash(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function clean(string $value, int $max, bool $multiline = false): string
    {
        $value = mb_scrub($value, 'UTF-8');
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        // Fjern kontroltegn (behold linjeskift i flerlinjefelter).
        $value = preg_replace($multiline ? '/[^\P{Cc}\n]/u' : '/\p{Cc}/u', $multiline ? '' : ' ', $value) ?? '';
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > $max) {
            $value = rtrim(mb_substr($value, 0, $max - 1, 'UTF-8')) . '…';
        }

        return $value;
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private static function cleanList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $clean = self::clean((string) $value, self::MAX_FIELD);
            if ($clean !== '' && !in_array($clean, $out, true)) {
                $out[] = $clean;
            }
        }

        return $out;
    }
}
