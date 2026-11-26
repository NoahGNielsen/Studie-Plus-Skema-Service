<?php

declare(strict_types=1);

/*
 * Manuel kontrol af Studie+ "Direkte"-login med DINE EGNE oplysninger.
 * Køres via SSH:  php <webroot>/app/bin/verify-studieplus.php
 *
 * - Adgangskoden indtastes skjult og gemmes ikke.
 * - Der udskrives kun ufarlig diagnostik (sti, statuskode, størrelse),
 *   aldrig cookies, svarindhold eller loginoplysninger.
 * - Kan køres uden secrets-fil; bruger HANSENBERG (621401) som standard.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use SkemaService\Credentials;
use SkemaService\StudiePlus\StudiePlusClient;
use SkemaService\Sync\AuthenticationFailed;
use SkemaService\Sync\SourceUnavailable;

$instnr = $argv[1] ?? '621401';

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

$client = new StudiePlusClient('https://all.studieplus.dk', $instnr);
try {
    $client->login(new Credentials($username, $password));
    fwrite(STDOUT, "OK: login endte på /skema. Det tidligere beskrevne Direkte-flow ser ud til at virke.\n");
    $page = $client->get('/skema/skema/skema.nocache.js');
    fwrite(STDOUT, sprintf("skema.nocache.js: HTTP %d, %d bytes\n", $page['status'], strlen($page['body'])));
} catch (AuthenticationFailed) {
    fwrite(STDOUT, "AFVIST: Studie+ viste loginsiden igen (forkerte oplysninger, eller flowet kræver et ekstra trin).\n");
    exit(2);
} catch (SourceUnavailable $e) {
    fwrite(STDOUT, 'FEJL: ' . $e->errorCode . "\n");
    exit(1);
} finally {
    $client->close();
    unset($password);
}
