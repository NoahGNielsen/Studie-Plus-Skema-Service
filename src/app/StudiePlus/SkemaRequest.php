<?php

declare(strict_types=1);

namespace SkemaService\StudiePlus;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Bygger GWT-RPC-kaldet SkemaService.hentEgnePersSkemaData(UDate fra, UDate til).
 *
 * Formatet er kopieret fra browserens eget kald (26.11.2026):
 *   7|0|6|<module base>|<policy>|dk.uddata.services.interfaces.SkemaService|
 *   hentEgnePersSkemaData|dk.uddata.gwt.comm.shared.UDate/2314285719|UDate:|
 *   1|2|3|4|2|5|5|5|6|<fra>|5|6|<til>|
 * hvor en dato er  år-1900|måned (0-baseret)|dag|0|0|0  (GwtDate).
 * Browseren beder om mandag til søndag i samme uge, og svaret indeholder
 * begivenheder fra og med fra-datoen.
 */
final class SkemaRequest
{
    public const SERVICE_PATH = '/skema/skema/skemaservice';
    public const MODULE_PATH = '/skema/skema/';
    public const DEFAULT_PERMUTATION = '59FCE8AE0F360F593819F2DD865EEEBA';
    public const DEFAULT_POLICY = '5BE4D5F01665D46AC05E7085B8ECDF64';

    public static function body(string $moduleBase, string $policy, DateTimeImmutable $from, DateTimeImmutable $to): string
    {
        foreach ([$moduleBase, $policy] as $value) {
            // | og \ har særlig betydning i GWT-requests og forekommer aldrig i disse værdier.
            if ($value === '' || strpbrk($value, "|\\\r\n") !== false) {
                throw new InvalidArgumentException('Ugyldig værdi i GWT-request.');
            }
        }
        if (preg_match('/^[0-9A-F]{32}$/', $policy) !== 1) {
            throw new InvalidArgumentException('GWT-policy skal være 32 hex-tegn.');
        }

        return implode('|', [
            '7', '0', '6',
            $moduleBase,
            $policy,
            'dk.uddata.services.interfaces.SkemaService',
            'hentEgnePersSkemaData',
            'dk.uddata.gwt.comm.shared.UDate/2314285719',
            'UDate:',
            '1', '2', '3', '4', '2', '5', '5',
            '5', '6', GwtDate::toRpcFragment($from),
            '5', '6', GwtDate::toRpcFragment($to),
        ]) . '|';
    }
}
