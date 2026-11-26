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

    'studieplus' => [
        // Forbliver false, indtil GWT-hentningen er verificeret (se README).
        'enabled' => false,
        'base_url' => 'https://all.studieplus.dk',
        'instnr' => '621401', // HANSENBERG (fra Studie+'s offentlige loginside)
    ],
];
