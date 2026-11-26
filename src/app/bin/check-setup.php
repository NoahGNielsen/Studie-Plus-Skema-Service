<?php

declare(strict_types=1);

/*
 * Kontrollerer installationen trin for trin og viser den første fejl.
 * Køres via SSH:  /usr/local/bin/php <webroot>/app/bin/check-setup.php
 *
 * Udskriver aldrig hemmeligheder (adgangskoder, master_key).
 * PDO-fejl vises, fordi de kun ses i din egen terminal; de kan indeholde
 * databasebrugernavn og -vært, men ikke adgangskoden.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use SkemaService\Config;
use SkemaService\Services;

$failed = false;

function ok(string $msg): void
{
    fwrite(STDOUT, "[OK]   $msg\n");
}

function fail(string $msg): void
{
    global $failed;
    $failed = true;
    fwrite(STDOUT, "[FEJL] $msg\n");
}

function note(string $msg): void
{
    fwrite(STDOUT, "[INFO] $msg\n");
}

// 1. PHP og udvidelser
note('PHP ' . PHP_VERSION . ' (CLI). Kontrollér, at webstedet bruger samme version i kontrolpanelet.');
foreach (['pdo_mysql', 'sodium', 'curl', 'mbstring'] as $ext) {
    extension_loaded($ext) ? ok("udvidelsen $ext") : fail("udvidelsen $ext mangler");
}

// 2. Secrets-fil
$path = dirname(SPS_WEB_ROOT) . '/' . Config::SECRETS_FILENAME;
note('webroot: ' . SPS_WEB_ROOT);
note("forventet secrets-fil: $path");
if (!is_file($path)) {
    fail('secrets-filen findes ikke på den sti. Flyt den, eller ret server-dir/webroot.');
    exit(1);
}
if (!is_readable($path)) {
    fail('secrets-filen findes, men kan ikke læses af PHP-brugeren.');
    exit(1);
}
ok('secrets-filen findes og kan læses');

try {
    $config = Config::load($path);
    ok('secrets-filen kan indlæses');
} catch (Throwable $e) {
    fail('secrets-filen: ' . $e->getMessage());
    exit(1);
}

// 3. Felter
try {
    $config->masterKey();
    ok('master_key er 32 bytes base64');
} catch (Throwable $e) {
    fail($e->getMessage());
}

$baseUrl = $config->baseUrl();
str_starts_with($baseUrl, 'https://') || !$config->isProduction()
    ? ok("base_url = $baseUrl")
    : fail("base_url skal starte med https:// i produktion (er: $baseUrl)");

$logFile = $config->get('log_file');
if (is_string($logFile) && $logFile !== '') {
    $writable = is_file($logFile) ? is_writable($logFile) : is_writable(dirname($logFile));
    $writable ? ok("log_file kan skrives: $logFile") : fail("log_file kan ikke skrives (mappen findes ikke eller er skrivebeskyttet): $logFile");
} else {
    note('log_file er ikke sat; fejl går til PHP\'s error_log');
}

// 4. Database
$db = (array) $config->get('db');
foreach (['dsn', 'user', 'password'] as $k) {
    if (!isset($db[$k]) || $db[$k] === '' || str_contains((string) $db[$k], 'UDFYLD')) {
        fail("db.$k er ikke udfyldt");
    }
}
try {
    $pdo = new PDO((string) ($db['dsn'] ?? ''), (string) ($db['user'] ?? ''), (string) ($db['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    ok('forbindelse til databasen');
    foreach (['sps_users', 'sps_events', 'sps_rate_limits'] as $table) {
        try {
            $pdo->query("SELECT 1 FROM $table LIMIT 1");
            ok("tabellen $table findes");
        } catch (Throwable $e) {
            fail("tabellen $table findes ikke. Kør database/schema.sql i phpMyAdmin på den database, DSN'en peger på.");
        }
    }
} catch (Throwable $e) {
    fail('database: ' . $e->getMessage());
    note('På Simply.com er værten typisk mysqlNN.unoeuro.com (se kontrolpanelet), ikke localhost.');
}

// 5. Hele opstarten, som index.php gør den
if (!$failed) {
    try {
        new Services($config);
        ok('alle tjenester kan startes');
    } catch (Throwable $e) {
        fail('opstart: ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
}

fwrite(STDOUT, $failed ? "\nRet fejlene ovenfor og kør scriptet igen.\n" : "\nAlt ser fint ud.\n");
exit($failed ? 1 : 0);
