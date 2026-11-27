# Studie Plus Skema Service

Få dit Studie+-skema fra HANSENBERG direkte i din kalender-app.

Tjenesten logger ind på Studie+ for dig, henter dit eget skema og udgiver det som et personligt kalenderlink (`.ics`), som Google Kalender, Apple Kalender, Outlook og andre kalenderapps kan abonnere på. Skemaet opdateres automatisk, også når lektioner flyttes eller aflyses.

**Prøv den:** <https://studieplus-skema-service.noahgajnielsen.dk/>

> Uofficiel tjeneste. Den er ikke tilknyttet Studie+, Uddata+ eller HANSENBERG.

---

## Indhold

- [Sådan bruger du den](#sådan-bruger-du-den)
- [Hvad kommer med i kalenderen](#hvad-kommer-med-i-kalenderen)
- [Begrænsninger](#begrænsninger)
- [Sikkerhed og persondata](#sikkerhed-og-persondata)
- [Teknisk overblik](#teknisk-overblik)
- [Installation på Simply.com](#installation-på-simplycom)
- [Drift og fejlfinding](#drift-og-fejlfinding)
- [Tests](#tests)
- [Licens](#licens)

---

## Sådan bruger du den

1. **Skaf et "Direkte" Studie+-login.** Logger du normalt ind med UNI-Login eller MitID, skal du først oprette et Direkte login. Se guiden på [/direkte-login](https://studieplus-skema-service.noahgajnielsen.dk/direkte-login).
2. **Opret din kalender** på forsiden med dit Studie+-brugernavn og din adgangskode. Tjenesten henter dit skema med det samme, og du får et personligt kalenderlink.
3. **Abonnér på linket** i din kalender-app ("Tilføj kalender fra URL" eller lignende). Kalenderen hedder *Skema (Studie+ Skema Service)*.
4. **Administrér** på [/administrer](https://studieplus-skema-service.noahgajnielsen.dk/administrer): opdatér din adgangskode, lav et nyt link (det gamle holder straks op med at virke) eller slet alle dine data.

> Kalenderlinket vises kun én gang. Behandl det som en adgangskode: alle, der har linket, kan se dit skema.

## Hvad kommer med i kalenderen

| Kalenderfelt | Indhold |
|---|---|
| Titel | Faget eller timens navn. Aflyste lektioner hedder "Aflyst: …" |
| Sted | Lokale(r) |
| Beskrivelse | Lærer(e), note og lektier (som ren tekst) |
| Tidsrum | 14 dage tilbage og 120 dage frem |

- **Opdatering:** Mandag–fredag kl. 06:30–16:00 hentes skemaet hvert 15. minut, ellers hver time. Din kalender-app bestemmer selv, hvor tit den henter linket. Google Kalender kan være flere timer om det.
- **Ændringer:** Flyttede eller ændrede lektioner opdateres i kalenderen uden dubletter. Fjernede lektioner forsvinder ved næste opdatering.
- **Fravær** og andre data, kalenderen ikke bruger, hentes og gemmes ikke.

## Begrænsninger

- **Kun "Direkte" login.** UNI-Login og MitID automatiseres ikke, fordi det ville kræve, at man omgår interaktive logintrin.
- **Skifter du adgangskode i Studie+,** stopper opdateringen. Tjenesten prøver ikke igen af sig selv (så din konto ikke bliver spærret), men venter på, at du indtaster den nye adgangskode under *Administrér*.
- **Aflyste lektioner** er endnu ikke set i et rigtigt svar fra Studie+. Dukker en ukendt status op, fejler hentningen med `gwt_unknown_event_status` i stedet for at gætte, og kalenderen beholder det senest hentede skema.
- **Kun HANSENBERG** (`instnr` `621401`) er understøttet.
- **Brugsvilkår.** Det er ikke afklaret, om Studie+/Uddata+ tillader automatiseret adgang.

## Sikkerhed og persondata

Den fulde forklaring til brugerne står på [/privatliv](https://studieplus-skema-service.noahgajnielsen.dk/privatliv). Kort fortalt:

**Loginoplysninger** krypteres med XChaCha20-Poly1305 (libsodium). Associated data binder chifferteksten til den enkelte bruger og det enkelte felt. Nøglen afledes fra `master_key` i en secrets-fil uden for webroot.

> **Begrænsning:** Nøglen ligger på samme server som databasen. Det er nødvendigt for automatisk login. Bliver webhotellet kompromitteret, kan de gemte adgangskoder afsløres. Brugerne opfordres derfor til ikke at genbruge adgangskoden.

**Kalenderlinket** indeholder et tilfældigt token på 256 bit. Databasen gemmer kun en HMAC af det. Ugyldige, roterede og slettede tokens giver samme 404.

> **Begrænsning:** Webhotellets adgangslog registrerer formentlig request-linjen og dermed tokenet. Det kan applikationen ikke forhindre på et delt webhotel. Hvis et link kan være lækket, så lav et nyt.

**Studie+-sessioner:** Hver hentning laver en ny session. Cookies findes kun i hukommelsen under kaldet, og `huskmig` sendes aldrig.

**Administration:** Login sammenlignes med de gemte værdier i konstant tid. Sessionen udløber efter 15 minutter uden aktivitet.

**Web:**

- CSRF-token, tjek af `Origin` og sessionscookie med `HttpOnly`, `Secure` og `SameSite=Strict`.
- Streng CSP (ingen eksterne ressourcer eller analytics), `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY` og `Cache-Control: no-store`.
- I produktion bliver HTTP til en redirect, og der sendes HSTS.
- Feeden sender `X-Robots-Tag: noindex`.

**Rate limiting** (pr. hashet IP og pr. hashet brugernavn). Fejler rate limiting, afvises forespørgslen.

| Handling | Grænse |
|---|---|
| Oprettelse | 5 pr. 15 min. pr. IP, 5 pr. time pr. brugernavn |
| Administrations-login | 5 pr. 15 min. pr. IP, 5 pr. time pr. brugernavn |
| Ugyldige feed-tokens | 30 pr. time pr. IP |

**Logning:** Kun bruger-id, fejlkoder og antal. `Logger::redact` fjerner feed-stier og token-lignende strenge.

| Gemmes | Gemmes ikke |
|---|---|
| Krypteret login | Fravær |
| HMAC af brugernavn og token | IP-adresser (kun som kortlivede HMAC-tællere) |
| Kalenderfelter for tidsrummet | Cookies og Studie+-sessioner |
| Fejlkode og tidspunkter | |
| Dato for seneste feed-hentning | |

**Sletning:** Brugeren kan selv slette alt under *Administrér*. Data slettes desuden automatisk, hvis feeden ikke er hentet i 90 dage.

Sikkerhedsproblemer kan rapporteres via [security.txt](src/.well-known/security.txt).

## Teknisk overblik

Ren PHP 8.2+ uden Composer-afhængigheder, MySQL og en cron-job. Alt i `src/` er webroot.

```
src/                         ← webroot (uploades af GitHub Actions)
  index.php                  eneste PHP-indgang
  .htaccess                  sender alt undtagen statiske filer til index.php
  assets/                    css/, js/
  robots.txt  sitemap.xml  .well-known/security.txt
  app/                       koden, spærret med "Require all denied"
    bootstrap.php            autoloader
    Config.php               indlæser secrets-filen over webroot (og afviser den i webroot)
    Crypto.php               kryptering, HMAC-hashes, tokens, UID'er
    Schedule/                Lesson, DateRange, HtmlToText
    Ical/                    IcalWriter (RFC 5545), FeedEvent
    Store/                   Store-interface, MysqlStore
    Sync/                    SyncService, SyncPolicy (plan og backoff), ScheduleSource
    StudiePlus/              login-klient, GWT-request, GWT-læser, skemamapper
    Feed/FeedService.php     token → én brugers kalender
    Web/                     App (routing), Csrf, View, skabeloner
    bin/cron.php             planlagt opdatering (kun CLI)
    bin/check-setup.php      kontrol af installationen (kun CLI)
    bin/verify-studieplus.php  manuel kontrol af login og hentning (kun CLI)
database/schema.sql          databaseskema
config/…example.php          eksempel på secrets-fil (kun pladsholdere)
tests/                       test-runner uden afhængigheder, mockdata og fixtures
```

### Ruter

| Metode | Sti | Formål |
|---|---|---|
| GET | `/` | Forside med oprettelsesformular |
| POST | `/opret` | Opret kalender (eller opdatér adgangskode for en eksisterende) |
| GET | `/administrer` | Administrationsside |
| POST | `/administrer/log-ind`, `/nyt-link`, `/slet`, `/log-ud` | Administrationshandlinger |
| GET | `/direkte-login` | Guide til Direkte login |
| GET | `/privatliv` | Privatlivspolitik |
| GET | `/feed/<token>.ics` | Kalenderfeed |

### Kalenderfeed

- **Tider** skrives som `DTSTART;TZID=Europe/Copenhagen:…` med en medfølgende `VTIMEZONE`, så sommer- og vintertid håndteres korrekt.
- **UID** = `HMAC(bruger-id + Studie+-begivenhedens id)`. Stabil mellem opdateringer og afslører hverken bruger eller indhold.
- **Ændringer** giver `SEQUENCE + 1` og ny `LAST-MODIFIED`. Aflyste lektioner får `STATUS:CANCELLED`.
- **HTML** i noter og lektier konverteres til ren tekst. Script, style og iframe fjernes med indhold, også når de er entity-kodede. Kun http(s)-links bevares, som tekst.
- **Svar:** `Cache-Control: private, no-cache`, `ETag` (304 ved uændret indhold) og `REFRESH-INTERVAL`/`X-PUBLISHED-TTL` på 15 minutter.

### Opdatering og fejlhåndtering

- **Cron** kører `app/bin/cron.php` hvert 15. minut. `SyncPolicy` afgør pr. bruger, om en hentning er forfalden. Kørslen stopper selv efter 12 minutter.
- **Backoff** ved fejl: 30, 60, 120 og højst 240 minutter.
- **Låse:** MySQL `GET_LOCK` sikrer én hentning ad gangen pr. bruger, og at to cron-kørsler aldrig overlapper.
- **Fejl** (netværk, vedligehold, uventet format) ændrer aldrig de gemte data. Feeden viser det senest hentede gyldige skema.
- **Tomt svar:** Findes der data i forvejen, beholdes de indtil tre tomme svar i træk.
- **Afvist login** sætter status `needs_reauth` og stopper automatiske forsøg.

### Studie+-integrationen

**Login** poster til `https://all.studieplus.dk/login/doLogin` med `instnr`, `how=DIREKTE`, `user` og `pass`. Der findes intet offentligt API.

**Skemaet** hentes med GWT-RPC-kaldet `SkemaService.hentEgnePersSkemaData(UDate fra, UDate til)` på `/skema/skema/skemaservice`.

- [SkemaRequest](src/app/StudiePlus/SkemaRequest.php) bygger request-body'en byte for byte som browserens. Tidsrummet hentes i bidder på `chunk_days` dage (standard 7).
- GWT-formatet er ikke selvbeskrivende. [GwtObjectReader](src/app/StudiePlus/GwtObjectReader.php) læser objektgrafen ud fra et fast katalog over klasser og feltrækkefølge i [SkemaResponseMapper](src/app/StudiePlus/SkemaResponseMapper.php). Svaret skal læses præcis til ende. En ukendt type, en ændret typesignatur eller overskydende data giver en fejl i stedet for et gæt.
- Svaret indeholder også fraværsdata og elevens navn. De læses kun for at komme forbi dem og bruges eller gemmes aldrig.
- [tests/fixtures/skema-response.txt](tests/fixtures/skema-response.txt) er et rigtigt svar (uge 48, 2026), hvor alle personhenførbare værdier er erstattet med pladsholdere. **Commit aldrig et rå svar fra Studie+.**

## Installation på Simply.com

### Krav

| Krav | Bemærkning |
|---|---|
| PHP ≥ 8.2 | Vælges under *Website → PHP Version* |
| `pdo_mysql`, `sodium`, `curl`, `mbstring` | Kontrollér med `php -m` via SSH (eller kør `check-setup.php`) |
| SSH og crontab | *Website → SSH-adgang* (`ssh.simply.com`). Crontab findes kun på PHP-webhotel |
| MySQL | Opret databasen i kontrolpanelet |
| `mod_rewrite` | Test at `/administrer` virker efter upload |
| HTTPS | Aktivér SSL-certifikat for domænet |

### 1. Filer

Der skal præcis to ting på serveren:

```
<forældermappe>/
├── studie-plus-skema-service-secrets.php   ← hemmeligheder (chmod 600), uploades manuelt
└── <webroot>/                              ← indholdet af src/
```

- **`src/`** uploades automatisk via FTPS af [ftp-upload.yml](.github/workflows/ftp-upload.yml) ved hvert push til `main`. Workflowet bruger repository-secrets `FTP_HOST`, `FTP_USER` og `FTP_PASSWORD`, og `server-dir` skal være domænets webroot.
- **Secrets-filen** ligger i mappen lige over webroot. `Config::load()` leder efter `dirname(webroot)/studie-plus-skema-service-secrets.php` og nægter at bruge den, hvis den ligger under `DOCUMENT_ROOT`.

`app/.htaccess` afviser alle direkte forespørgsler, så koden ikke kan hentes, heller ikke hvis `mod_rewrite` mangler.

> Er webroot en undermappe af en anden webroot (fx `public_html/<navn>/`), ligger mappen over webroot selv i en webroot. Filen giver intet output, hvis den kaldes direkte, men læg den helst et sted, der ikke er offentligt.

### 2. Database

1. Opret en MySQL-database.
2. Kør [database/schema.sql](database/schema.sql) i phpMyAdmin.
3. Giv databasebrugeren **kun** `SELECT`, `INSERT`, `UPDATE` og `DELETE`. Kan rettighederne ikke begrænses, så brug en database, der kun bruges til denne tjeneste.

### 3. Secrets-fil

Kopiér [config/studie-plus-skema-service-secrets.example.php](config/studie-plus-skema-service-secrets.example.php), udfyld den, og upload den som `studie-plus-skema-service-secrets.php` til mappen over webroot:

```sh
chmod 600 studie-plus-skema-service-secrets.php
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'   # → master_key
```

| Felt | Indhold |
|---|---|
| `db.dsn`, `db.user`, `db.password` | Databaseforbindelsen |
| `master_key` | 32 tilfældige bytes, base64. **Skiftes eller mistes nøglen, kan gemte logins ikke læses, og alle feed-links holder op med at virke** |
| `base_url` | `https://<domæne>` uden afsluttende `/` |
| `production` | `true` (kræver HTTPS og sender HSTS) |
| `log_file` | Fx `<forældermappe>/studie-plus-skema-service.log` (uden for webroot, skrivbar). Udelades den, logges til PHP's `error_log` |
| `studieplus.enabled` | `false`, indtil `verify-studieplus.php` er kørt med succes |
| `studieplus.instnr` | `621401` (HANSENBERG) |
| `studieplus.permutation`, `.policy` | Valgfri. Se [Når Studie+ opdateres](#når-studie-opdateres) |
| `studieplus.chunk_days` | Valgfri. Dage pr. kald (1–140, standard 7) |

Filen er nævnt i `.gitignore` og må aldrig committes. Så længe `studieplus.enabled` er `false`, kontaktes Studie+ ikke, og websitet viser "ikke aktiveret endnu".

### 4. Kontrol

```sh
/usr/local/bin/php <webroot>/app/bin/check-setup.php
```

Kontrollerer udvidelser, placeringen af secrets-filen, felterne, databaseforbindelsen og tabellerne og viser den første fejl. Hemmeligheder udskrives aldrig.

### 5. Aktivér Studie+

```sh
/usr/local/bin/php <webroot>/app/bin/verify-studieplus.php
```

Logger ind med dine egne oplysninger, henter hele tidsrummet og udskriver kun antal og datoer. Lykkes det, så sæt `studieplus.enabled` til `true`.

Kør den eventuelt igen med `140` som argument. Giver det samme antal lektioner, kan `chunk_days` sættes op, så der bruges færre kald.

### 6. Cron

Kør `crontab -e` via SSH:

```cron
*/15 * * * * /usr/local/bin/php <webroot>/app/bin/cron.php >/dev/null 2>&1
```

- `/usr/local/bin/php` følger den valgte PHP-version.
- Brug **ikke** Simply.coms URL-baserede cron. Den har en HTTP-timeout og ville kræve et offentligt endpoint.
- Kontrollér efter en time, at logfilen indeholder `cron_done`-linjer.

## Drift og fejlfinding

| Symptom | Gør dette |
|---|---|
| Websitet viser "Der opstod en fejl" | Kør `check-setup.php` via SSH |
| Kalenderen opdateres ikke | Kig efter `cron_done` og fejlkoder i logfilen |
| `gwt_incompatible_version` | Studie+ har udgivet en ny version. Find de nye værdier i browserens DevTools (headeren `X-GWT-Permutation` og den 2. streng i request-body'en), og sæt `studieplus.permutation` og `studieplus.policy` i secrets-filen |
| `gwt_unknown_type` eller `gwt_trailing_data` | En klasse har ændret felter. Skaf et nyt svar, anonymisér det, og opdatér layoutet i `SkemaResponseMapper` |
| `gwt_unknown_event_status` | En lektion har en ukendt status (sandsynligvis aflyst). Skaf et anonymiseret svar med en aflyst lektion, og tilføj statussen |
| En bruger har status `needs_reauth` | Brugeren skal indtaste sin nye adgangskode på forsiden eller under *Administrér* |

### Når Studie+ opdateres

Standardværdierne for `permutation` og `policy` er fra 26.11.2026. Når de ændres, fejler alle hentninger med `gwt_incompatible_version`, men feeden viser fortsat det senest hentede skema, indtil secrets-filen er opdateret.

## Tests

```sh
php tests/run.php            # alle tests
php tests/run.php ical       # kun tests hvis navn indeholder "ical"
```

Kræver PHP 8.2+ med `sodium` og `mbstring`. Testene bruger kun mockdata, en in-memory store og en falsk skemakilde. De kontakter aldrig Studie+ og kræver ingen database.

**Dækket:**

- iCalendar-struktur, CRLF, escaping og linjefoldning
- vinter- og sommertid samt skiftedagen
- stabile UID'er, SEQUENCE, aflyste og fjernede lektioner
- tidsrum, opdateringsplan og backoff
- kryptering, tokens og brugeradskillelse
- ugyldige, roterede og slettede tokens
- sikker HTML-konvertering
- at fejl og tomme svar ikke overskriver gyldige data, og låse
- GWT-læseren, GWT-request (byte for byte) og mapning af et rigtigt, anonymiseret skemasvar
- at fravær og elevdata aldrig kommer med, og at ændret format giver fejl

**Ikke dækket:** `MysqlStore`, `StudiePlusClient` (kræver Studie+) og `Web\App` (HTTP-laget).

## Licens

[Apache License 2.0](LICENSE). Se også [NOTICE](NOTICE).
