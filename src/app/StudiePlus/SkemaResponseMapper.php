<?php

declare(strict_types=1);

namespace SkemaService\StudiePlus;

use SkemaService\Schedule\Lesson;
use SkemaService\Sync\SourceUnavailable;

/**
 * Oversætter et GWT-svar (PersSkemaData → SkemaBegivenhed …) til Lesson-objekter.
 *
 * AFVENTER ANONYMISERET EKSEMPEL. Feltrækkefølgen i GWT-serialiseringen
 * er ikke dokumenteret, så mapningen skrives og testes mod et rigtigt
 * (anonymiseret) svar. Regler for den kommende implementering:
 *  - Læs kun titel, start/slut, lærere, lokaler, status (aflyst), note og lektier.
 *  - Fraværsdata og andre felter springes over og gemmes aldrig.
 *  - Noter/lektier sendes gennem Schedule\HtmlToText.
 *  - Ukendte typer eller uventet struktur giver SourceUnavailable — aldrig et tomt resultat.
 */
final class SkemaResponseMapper
{
    /** @return list<Lesson> */
    public static function map(GwtResponseReader $reader): array
    {
        throw new SourceUnavailable('gwt_mapping_not_verified');
    }
}
