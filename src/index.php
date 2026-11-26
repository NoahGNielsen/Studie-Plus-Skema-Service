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
$services = null;
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
    // Brug den fælles logger (samme request-id), ellers den konfigurerede logfil, hvis konfigurationen nåede at blive indlæst.
    $logFile = $config?->get('log_file');
    $logger = $services?->logger ?? new Logger(is_string($logFile) && $logFile !== '' ? $logFile : null);
    $logger->exception($services === null ? 'request_bootstrap_failed' : 'request_failed', $e, [
        'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
        'path' => Logger::redact((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/')),
    ]);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo "Der opstod en fejl. Prøv igen senere.\n";
}
