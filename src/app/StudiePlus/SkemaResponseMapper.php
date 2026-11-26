<?php

declare(strict_types=1);

namespace SkemaService\StudiePlus;

use DateTimeImmutable;
use DateTimeZone;
use SkemaService\Schedule\HtmlToText;
use SkemaService\Schedule\Lesson;
use SkemaService\Sync\SourceUnavailable;

/**
 * Oversætter svaret fra SkemaService.hentEgnePersSkemaData til Lesson-objekter.
 *
 * Layoutet er udledt af et rigtigt svar (26.11.2026, anonymiseret i
 * tests/fixtures/skema-response.txt). Hele svaret skal kunne læses præcis
 * til ende; ellers er formatet ændret, og der kastes SourceUnavailable.
 *
 * Svaret indeholder også fraværstyper og fraværsregistreringer. De skal
 * læses for at komme forbi dem, men de bruges og gemmes aldrig.
 */
final class SkemaResponseMapper
{
    private const PERS_SKEMA_DATA = 'dk.uddata.model.skema.PersSkemaData/2623342155';
    private const BEGIVENHED = 'dk.uddata.model.skema.SkemaBegivenhed/531356383';
    private const AKTIVITET = 'dk.uddata.model.skema.SkemaBegivenhed$AktiviteterISkema/325282588';
    private const LOKALE = 'dk.uddata.model.skema.SkemaBegivenhed$LokalerISkema/28120404';
    private const MEDARBEJDER = 'dk.uddata.model.skema.SkemaBegivenhed$MedarbejderISkema/527938099';
    private const STATUS = 'dk.uddata.model.skema.SkemaBegivenhed$Status/3512799574';
    private const NOTE = 'dk.uddata.model.skemanoter.SkemaNote2/3654740673';
    private const UDATE = 'dk.uddata.gwt.comm.shared.UDate/2314285719';

    /** Feltrækkefølge pr. klasse (i = tal, s = streng, o = objekt). */
    private const LAYOUTS = [
        self::PERS_SKEMA_DATA => 'o,o,o,o,o,o,o,o,i,i,i,i,i,i,i,i,i,o,o,o,o,o',
        self::BEGIVENHED => 'i,i,i,i,o,s,i,i,i,i,o,i,o,i,o,o,i,i,i,s,i,i,o,i,o,i,s,o,i,i,i,i,o,o,o,o,s,o,o,o,i,i',
        self::AKTIVITET => 'i,i,s,s,i',
        self::LOKALE => 'i,s,i',
        self::MEDARBEJDER => 'i,s,i,o',
        self::NOTE => 'i,i,s,i,i,s,s,s,s,o,s,o,o,o,i,i,s',
        self::UDATE => 's,i,i,i,i,i,i',
        // Fravær og elevdata: læses kun for at komme forbi dem.
        'dk.uddata.model.skema.Aarstyp/211660552' => 'o,o,i,o,s,o',
        'dk.uddata.model.skema.Frareg/1291651799' => 'i,i,i,o',
        'dk.uddata.model.skema.Fraver/3718962541' => 'o,i,i,o,o,i,s,s,i,o',
        'dk.uddata.model.skema.Fravk/3596276343' => 's,s,s,o,o',
        'dk.uddata.model.bruger.Skemaelev/1918340907' => 'o,o,o,s,o,s,o,s,s',
    ];

    private const ENUMS = [
        self::STATUS,
        'dk.uddata.model.skema.Aarstyp$AarsagsType/1185577946',
        'dk.uddata.model.skema.Aarstyp$AmuKode/1890370738',
        'dk.uddata.model.skema.Aarstyp$Status/2076508582',
        'dk.uddata.model.skema.Frareg$Status/15407941',
        'dk.uddata.model.skema.Fraver$Status/768439696',
        'dk.uddata.model.skema.Fravk$FravkStatus/1669850998',
        'dk.uddata.model.skema.SkemaTools$FravaStatus/3966334960',
        'dk.uddata.model.skema.SkemaTools$RegModel/3858839897',
        'dk.uddata.model.skema.SkemaTools$RegStatus/2451966260',
    ];

    // PersSkemaData
    private const F_DAYS = 20;   // HashMap<UDate, ArrayList<SkemaBegivenhed>>
    private const F_NOTES = 21;  // HashMap<Integer begivenheds-id, SkemaNote2>

    // SkemaBegivenhed
    private const F_ACTIVITIES = 4;
    private const F_TEXT = 5;
    private const F_SUBJECT = 19;
    private const F_ROOMS = 22;
    private const F_TEACHERS = 24;
    private const F_ID = 34;
    private const F_END = 37;
    private const F_START = 38;
    private const F_STATUS = 39;

    // SkemaNote2
    private const F_HOMEWORK_HTML = 5;
    private const F_HOMEWORK_TEXT = 6;
    private const F_NOTE_HTML = 7;
    private const F_NOTE_TEXT = 8;

    /** Kun "normal" er set indtil videre. Andre værdier (fx aflyst) er ikke verificeret. */
    private const STATUS_NORMAL = 0;

    /** @return list<Lesson> */
    public static function map(GwtResponseReader $reader): array
    {
        $root = (new GwtObjectReader($reader, self::LAYOUTS, self::ENUMS))->readObject();
        if ($reader->remaining() !== 0) {
            throw new SourceUnavailable('gwt_trailing_data');
        }
        $root = self::object($root, self::PERS_SKEMA_DATA);

        $notes = [];
        foreach (self::map_($root->fields[self::F_NOTES]) as [$key, $note]) {
            $notes[self::int($key)] = self::object($note, self::NOTE);
        }

        $lessons = [];
        foreach (self::map_($root->fields[self::F_DAYS]) as [, $events]) {
            foreach (self::list($events) as $event) {
                $lessons[] = self::lesson(self::object($event, self::BEGIVENHED), $notes);
            }
        }

        return $lessons;
    }

    /** @param array<int, GwtObject> $notes */
    private static function lesson(GwtObject $e, array $notes): Lesson
    {
        $f = $e->fields;
        $id = self::int($f[self::F_ID]);

        $status = $f[self::F_STATUS];
        if (!$status instanceof GwtEnum || $status->type !== self::STATUS) {
            throw new SourceUnavailable('gwt_type_mismatch');
        }
        if ($status->ordinal !== self::STATUS_NORMAL) {
            throw new SourceUnavailable('gwt_unknown_event_status');
        }

        $subject = trim((string) $f[self::F_SUBJECT]);
        $text = trim((string) $f[self::F_TEXT]);
        if ($subject === '') {
            foreach (self::list($f[self::F_ACTIVITIES]) as $activity) {
                $subject = trim((string) self::object($activity, self::AKTIVITET)->fields[3]);
                if ($subject !== '') {
                    break;
                }
            }
        }
        $title = $subject;
        if ($text !== '' && mb_strtolower($text) !== mb_strtolower($subject)) {
            $title = $subject !== '' ? "$subject – $text" : $text;
        }

        $rooms = array_map(
            static fn (mixed $r): string => (string) self::object($r, self::LOKALE)->fields[1],
            self::list($f[self::F_ROOMS]),
        );
        $teachers = array_map(
            static fn (mixed $t): string => (string) self::object($t, self::MEDARBEJDER)->fields[1],
            self::list($f[self::F_TEACHERS]),
        );

        $note = '';
        $homework = '';
        if (isset($notes[$id])) {
            $n = $notes[$id]->fields;
            $note = self::text($n[self::F_NOTE_HTML], $n[self::F_NOTE_TEXT]);
            $homework = self::text($n[self::F_HOMEWORK_HTML], $n[self::F_HOMEWORK_TEXT]);
        }

        try {
            return new Lesson(
                (string) $id,
                $title,
                self::date($f[self::F_START]),
                self::date($f[self::F_END]),
                $teachers,
                $rooms,
                $note,
                $homework,
            );
        } catch (\InvalidArgumentException) {
            throw new SourceUnavailable('gwt_invalid_lesson');
        }
    }

    /** HTML-udgaven foretrækkes (bevarer linjeskift); ellers tekstudgaven. */
    private static function text(mixed $html, mixed $plain): string
    {
        $html = (string) $html;

        return HtmlToText::convert($html !== '' ? $html : (string) $plain);
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        $f = self::object($value, self::UDATE)->fields;
        [, $year, $month, $day, $hour, $minute, $second] = $f;
        if ($month < 0 || $month > 11 || $day < 1 || $day > 31 || $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
            throw new SourceUnavailable('gwt_invalid_date');
        }
        $date = DateTimeImmutable::createFromFormat(
            '!Y-n-j G:i:s',
            sprintf('%d-%d-%d %d:%02d:%02d', $year + 1900, $month + 1, $day, $hour, $minute, $second),
            new DateTimeZone(Lesson::TIMEZONE),
        );
        if ($date === false || (int) $date->format('j') !== $day) {
            throw new SourceUnavailable('gwt_invalid_date');
        }

        return $date;
    }

    private static function object(mixed $value, string $type): GwtObject
    {
        if (!$value instanceof GwtObject || $value->type !== $type) {
            throw new SourceUnavailable('gwt_type_mismatch');
        }

        return $value;
    }

    private static function int(mixed $value): int
    {
        if (!is_int($value)) {
            throw new SourceUnavailable('gwt_type_mismatch');
        }

        return $value;
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new SourceUnavailable('gwt_type_mismatch');
        }

        return $value;
    }

    /** @return list<array{0: mixed, 1: mixed}> */
    private static function map_(mixed $value): array
    {
        $entries = self::list($value);
        foreach ($entries as $entry) {
            if (!is_array($entry) || count($entry) !== 2 || !array_key_exists(0, $entry) || !array_key_exists(1, $entry)) {
                throw new SourceUnavailable('gwt_type_mismatch');
            }
        }

        return $entries;
    }
}
