<?php

declare(strict_types=1);

/*
 * Fælles opstart: autoloader for SkemaService\-klasser.
 * Indlæser IKKE hemmeligheder — det gør Config::load() eksplicit.
 */

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit("PHP 8.2 eller nyere er påkrævet.\n");
}

// src/app/ = koden, src/ = webroot. Secrets-filen ligger i mappen OVER webroot.
define('SPS_APP_ROOT', __DIR__);
define('SPS_WEB_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'SkemaService\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});
