<?php

declare(strict_types=1);

/*
 * Manuel kontrol af Studie+ med DINE EGNE oplysninger: login, skemahentning
 * og mapning. Køres via SSH:
 *   php <webroot>/app/bin/verify-studieplus.php [chunk_days]
 *
 * - chunk_days (standard 7) er antal dage pr. kald. Kør fx med 7 og med 140
 *   og sammenlign antallet; er det ens, kan studieplus.chunk_days sættes op.
 * - Adgangskoden indtastes skjult og gemmes ikke.
 * - Der udskrives kun antal, datoer og fejlkoder, aldrig skemaindhold,
 *   cookies eller loginoplysninger.
 * - Kan køres uden secrets-fil; bruger HANSENBERG (621401) og de kendte
 *   GWT-værdier som standard.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use SkemaService\Config;
use SkemaService\Credentials;
use SkemaService\Schedule\DateRange;
use SkemaService\StudiePlus\StudiePlusClient;
use SkemaService\StudiePlus\StudiePlusScheduleSource;
use SkemaService\Sync\AuthenticationFailed;
use SkemaService\Sync\SourceUnavailable;

$settings = [];
try {
    $settings = (array) Config::load()->get('studieplus', []);
} catch (Throwable) {
    // Ingen secrets-fil: brug standardværdier.
}
$settings['enabled'] = true;
if (isset($argv[1])) {
    $settings['chunk_days'] = (int) $argv[1];
}
$source = StudiePlusScheduleSource::fromSettings($settings);

fwrite(STDOUT, 'Studie+-brugernavn: ');
$username = trim((string) fgets(STDIN));
fwrite(STDOUT, 'Adgangskode (vises ikke): ');
$hasStty = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && !empty(shell_exec('command -v stty'));
if ($hasStty) {
    shell_exec('stty -echo');
}
$password = rtrim((string) fgets(STDIN), "\r\n");
if ($hasStty) {
    shell_exec('stty echo');
}
fwrite(STDOUT, "\n");

$client = new StudiePlusClient(
    (string) ($settings['base_url'] ?? 'https://all.studieplus.dk'),
    (string) ($settings['instnr'] ?? '621401'),
);
$range = DateRange::around(new DateTimeImmutable('now'));
$started = microtime(true);
try {
    $lessons = $source->fetchWith($client, new Credentials($username, $password), $range);
    $lessons = array_values(array_filter($lessons, $range->overlaps(...)));
    usort($lessons, static fn ($a, $b) => $a->start <=> $b->start);

    fwrite(STDOUT, "OK: login og skemahentning virker.\n");
    fwrite(STDOUT, sprintf(
        "Interval: %s til %s\n",
        $range->firstDay->format('Y-m-d'),
        $range->lastDay->format('Y-m-d'),
    ));
    fwrite(STDOUT, sprintf("Lektioner: %d\n", count($lessons)));
    if ($lessons !== []) {
        fwrite(STDOUT, sprintf(
            "Første: %s  Sidste: %s\n",
            $lessons[0]->start->format('Y-m-d H:i'),
            end($lessons)->start->format('Y-m-d H:i'),
        ));
    }
    fwrite(STDOUT, sprintf(
        "Med note: %d  Med lektier: %d  Uden lærer: %d  Uden lokale: %d\n",
        count(array_filter($lessons, static fn ($l) => $l->note !== '')),
        count(array_filter($lessons, static fn ($l) => $l->homework !== '')),
        count(array_filter($lessons, static fn ($l) => $l->teachers === [])),
        count(array_filter($lessons, static fn ($l) => $l->rooms === [])),
    ));
    fwrite(STDOUT, sprintf("Tid: %.1f s\n", microtime(true) - $started));
} catch (AuthenticationFailed) {
    fwrite(STDOUT, "AFVIST: Studie+ viste loginsiden igen (forkerte oplysninger, eller flowet kræver et ekstra trin).\n");
    exit(2);
} catch (SourceUnavailable $e) {
    fwrite(STDOUT, 'FEJL: ' . $e->errorCode . "\n");
    if ($e->errorCode === 'gwt_incompatible_version') {
        fwrite(STDOUT, "Studie+ er opdateret. Find nye værdier for X-GWT-Permutation og policy (2. streng i request-body'en)\n"
            . "i browserens DevTools, og sæt studieplus.permutation og studieplus.policy i secrets-filen.\n");
    }
    exit(1);
} finally {
    unset($password);
}
