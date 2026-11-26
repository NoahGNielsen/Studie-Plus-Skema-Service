<?php

/*
 * EKSEMPEL — indeholder ingen rigtige værdier.
 *
 * Upload til mappen OVER webroot (forælderen til den mappe, src/ uploades til):
 *   <webroot>/../studie-plus-skema-service-secrets.php
 * Læg den ALDRIG i webroot,
 * og commit den aldrig. Sæt rettigheder:  chmod 600 studie-plus-skema-service-secrets.php
 */

return [
    'db' => [
        'dsn' => 'mysql:host=UDFYLD-DATABASEVAERT;dbname=UDFYLD-DATABASENAVN;charset=utf8mb4',
        'user' => 'UDFYLD-DATABASEBRUGER',
        'password' => 'UDFYLD-DATABASEADGANGSKODE',
    ],

    // 32 tilfældige bytes, base64. Generér med:
    //   php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
    // Skiftes nøglen, kan gemte loginoplysninger ikke længere læses, og alle feed-links holder op med at virke.
    'master_key' => 'UDFYLD-GENERERET-NOEGLE',

    // Offentlig adresse uden afsluttende skråstreg. Skal være https i produktion.
    'base_url' => 'https://UDFYLD-DOMAENE',

    'production' => true,

    // Logfil uden for webroot, fx ved siden af denne fil (mappen skal være skrivbar).
    // Udelades den, logges til PHP's error_log.
    'log_file' => '/UDFYLD-STI-OVER-WEBROOT/studie-plus-skema-service.log',

    // Laveste logniveau: 'debug' (standard — næsten alt, inkl. hvert HTTP-kald til Studie+),
    // 'info' (requests, hentninger, logins), 'warn' eller 'error'.
    // Der logges aldrig brugernavne, adgangskoder, cookies, feed-links eller skemaindhold.
    'log_level' => 'debug',

    'studieplus' => [
        // Forbliver false, indtil GWT-hentningen er verificeret (se README).
        'enabled' => false,
        'base_url' => 'https://all.studieplus.dk',
        'instnr' => '621401', // HANSENBERG (fra Studie+'s offentlige loginside)

        // Valgfrit. Standardværdierne er fra 26.11.2026. Når Studie+ udgiver en ny
        // version, fejler hentningen med gwt_incompatible_version; så findes de nye
        // værdier i browserens DevTools (X-GWT-Permutation og 2. streng i request-body'en).
        // 'permutation' => '59FCE8AE0F360F593819F2DD865EEEBA',
        // 'policy' => '5BE4D5F01665D46AC05E7085B8ECDF64',

        // Antal dage pr. kald (1–140). Browseren bruger 7. Kan sættes op, hvis
        // verify-studieplus.php giver samme antal lektioner med en større værdi.
        // 'chunk_days' => 7,
    ],
];
