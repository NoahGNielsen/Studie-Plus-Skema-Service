<?php

declare(strict_types=1);

/*
 * Eneste PHP-indgang. Denne mappe (src/) er webroot; koden ligger i app/,
 * som .htaccess spærrer for direkte adgang. Hemmelighederne ligger i
 * mappen OVER webroot:  ../studie-plus-skema-service-secrets.php
 */

require __DIR__ . '/app/bootstrap.php';

use SkemaService\Config;
use SkemaService\Logger;
use SkemaService\Services;
use SkemaService\Web\App;

ini_set('display_errors', '0');
ini_set('expose_php', '0');
header_remove('X-Powered-By');

$config = null;
try {
    $config = Config::load();
    $services = new Services($config);
    (new App(
        $services->config,
        $services->store,
        $services->crypto,
        $services->sync,
        $services->feed,
        $services->source,
        $services->logger,
    ))->run();
} catch (Throwable $e) {
    // Brug den konfigurerede logfil, hvis konfigurationen nåede at blive indlæst.
    $logFile = $config?->get('log_file');
    (new Logger(is_string($logFile) && $logFile !== '' ? $logFile : null))->exception('request_failed', $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo "Der opstod en fejl. Prøv igen senere.\n";
}
