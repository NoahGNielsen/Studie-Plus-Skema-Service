# Studie Plus Skema Service

Webservice der henter en elevs eget skema fra Studie+ (HANSENBERG) og udgiver det som en personlig iCalendar-feed (`.ics`), som kalenderapps kan abonnere på.

Uofficiel tjeneste. Den er ikke tilknyttet Studie+, Uddata+ eller HANSENBERG.

## Status

| Del | Status |
|---|---|
| iCalendar-feed (tidszone, UID'er, SEQUENCE, aflysninger) | Implementeret, testet med mockdata |
| Website (opret, kopiér link, nyt link, slet data) | Implementeret, ikke afprøvet i browser |
| Kryptering, tokens, CSRF, rate limiting | Implementeret, kernelogik testet |
| Opdateringsplan og cron | Implementeret, planlogik testet |
| MySQL-lagring (`MysqlStore`) | Implementeret, **ikke kørt mod en database** |
| Studie+-login (Direkte) | Implementeret efter tidligere beskrivelse, **ikke verificeret**, se nedenfor |
| Studie+-skemahentning (GWT-RPC) | **Afventer et anonymiseret eksempel**. Er slået fra |

Indtil GWT-delen er færdig, står `studieplus.enabled` til `false`. Så kontaktes Studie+ ikke, websitet viser "ikke aktiveret endnu", og der publiceres aldrig gættede data.

### Hvad er verificeret om Studie+ (26.11.2026)

Det følgende er kontrolleret på den offentlige loginside `https://all.studieplus.dk/login/`. Der er ikke sendt nogen loginoplysninger.

- Titlen er "Studie+ Login". Formularen poster til `/login/doLogin` med `instnr`, `acr_values` og knappen `how` (`UNILOGIN` eller `DIREKTE`). Der er også en valgfri `huskmig`-checkbox.
- HANSENBERG har `instnr` `621401`. Loginmulighederne er `UNILOGIN` og `DIREKTE`, begge med `mfa: false`.
- Der er ikke fundet noget offentligt API eller integrationsflow.

Ikke verificeret:

- At `user` og `pass` accepteres direkte i samme POST.
- At et vellykket login ender på `/skema/`.
- At GWT-endpointet, permutationen og formatet fra den tidligere undersøgelse stadig gælder.

Brug `app/bin/verify-studieplus.php` (via SSH) med dine egne oplysninger for at kontrollere loginflowet.

### Bevidste begrænsninger

- **Kun "Direkte" login.** UNI-Login og MitID automatiseres ikke, fordi det ville kræve, at man omgår interaktive logintrin.
- **`huskmig` sendes aldrig.** Hver hentning laver en ny session, og cookies findes kun i hukommelsen under kaldet.
- **Afvist login stopper automatiske forsøg.** Brugeren skal selv indtaste den nye adgangskode. Det forhindrer, at kontoen bliver spærret efter gentagne fejlforsøg.
- **Brugsvilkår.** Det er ikke afklaret, om Studie+/Uddata+ tillader automatiseret adgang. Afklar det med HANSENBERG eller Uddata+, før tjenesten åbnes for andre.

## Arkitektur

Projektet er ren PHP 8.2+ uden Composer-afhængigheder.

Alt i `src/` er webroot og uploades samlet. Intet andet i repositoriet skal på serveren, bortset fra secrets-filen.

```
src/                     ← uploades til webroot (GitHub Actions: ftp-upload.yml)
  index.php              eneste PHP-indgang
  .htaccess              sender alt undtagen statiske filer til index.php
  assets/                css/, js/, images/
  robots.txt  sitemap.xml  .well-known/security.txt
  app/                   koden, spærret med "Require all denied"
    bootstrap.php        autoloader
    Config.php           indlæser secrets-filen i mappen over webroot (og afviser den i webroot)
    Crypto.php           libsodium: kryptering, HMAC-hashes, tokens, UID'er
    Schedule/            Lesson (kun kalenderfelter), DateRange, HtmlToText
    Ical/                IcalWriter (RFC 5545) og FeedEvent
    Store/               Store-interface og MysqlStore
    Sync/                SyncService, SyncPolicy (plan og backoff), ScheduleSource
    StudiePlus/          login-klient, GWT-dato, generisk GWT-læser, mapper (afventer)
    Feed/FeedService.php token → én brugers kalender
    Web/                 App (routing), Csrf, View og skabeloner
    bin/cron.php         planlagt opdatering (kun CLI)
    bin/verify-studieplus.php  manuel kontrol af login (kun CLI)
database/schema.sql      databaseskema (køres i phpMyAdmin, uploades ikke)
config/…example.php      eksempel på secrets-fil (kun pladsholdere)
tests/                 test-runner uden afhængigheder, mockdata
```

### Kalenderfeed

- **Adresse:** `https://<domæne>/feed/<token>.ics`. Tokenet er 256 bit tilfældigt (43 tegn, base64url). Databasen gemmer kun en HMAC af det.
- **Tider:** skrives som `DTSTART;TZID=Europe/Copenhagen:…` med en medfølgende `VTIMEZONE`, så sommer- og vintertid håndteres korrekt.
- **UID:** `HMAC(bruger-id + Studie+-begivenhedens id)`. Den er stabil mellem opdateringer, så der ikke opstår dubletter, og den afslører hverken bruger eller indhold.
- **Ændringer:**
  - Ændrede lektioner får `SEQUENCE + 1` og ny `LAST-MODIFIED`.
  - Aflyste lektioner får `STATUS:CANCELLED` og titlen "Aflyst: …".
  - Fjernede lektioner udelades, og abonnenter fjerner dem ved næste opdatering.
- **Indhold:**
  - Titel = faget eller timens navn.
  - `LOCATION` = lokale(r).
  - `DESCRIPTION` = lærer(e), lokale, note og lektier.
  - Noter og lektier konverteres fra HTML til ren tekst. Script, style og iframe fjernes med indhold, også når de er entity-kodede. Kun http(s)-links bevares, som tekst.
- **Fravær** og andre felter, som kalenderen ikke bruger, hentes og gemmes ikke.
- **Interval:** 14 dage tilbage og 120 dage frem (`DateRange`).
- **Svar:** `Cache-Control: private, no-cache`, `ETag` (304 ved uændret indhold) og `REFRESH-INTERVAL`/`X-PUBLISHED-TTL` på 15 minutter. Kalenderappen bestemmer dog selv, hvor tit den henter. Google Kalender kan være flere timer om det.

### Opdatering og fejlhåndtering

- **Cron** kører `app/bin/cron.php` hvert 15. minut. `SyncPolicy` afgør pr. bruger, om en hentning er forfalden:
  - mandag–fredag kl. 06:30–16:00 dansk tid: hvert 15. minut
  - alle andre tidspunkter: hver time
- **Backoff** ved fejl: 30, 60, 120 og højst 240 minutter.
- **Låse:** MySQL `GET_LOCK` sikrer, at der kun er én hentning ad gangen pr. bruger, og at to cron-kørsler aldrig overlapper.
- **Fejl** (netværk, vedligehold, uventet format) ændrer aldrig de gemte data. Feeden viser fortsat det senest hentede gyldige skema.
- **Tomt svar:** Hvis Studie+ pludselig svarer tomt, og der findes data i forvejen, beholdes de gamle data indtil tre tomme svar i træk.
- **Afvist login** sætter status `needs_reauth` og stopper automatiske forsøg.

## Installation på Simply.com

Krav og antagelser, som du skal kontrollere i Simply.com-kontrolpanelet:

| Krav | Kilde / hvad du skal kontrollere |
|---|---|
| PHP-webhotel med PHP ≥ 8.2 | Simply.com: alle versioner fra 5.6, vælges under *Website → PHP Version* |
| Udvidelserne `pdo_mysql`, `sodium`, `curl`, `mbstring` | **Ikke dokumenteret af Simply.com.** Kontrollér med `php -m` via SSH |
| SSH-adgang og crontab | Simply.com: SSH under *Website → SSH-adgang* (`ssh.simply.com`). Crontab "kun på PHP-webhotel" |
| Mappe over webroot | Simply.com: roden er `/var/www/<domæne>/`, og webroot er `public_html`. Kontrollér hvilken mappe dit (sub)domæne peger på |
| `mod_rewrite` | Ikke verificeret. Test at `/administrer` virker efter upload |
| HTTPS | Aktivér SSL-certifikat for domænet i kontrolpanelet |

### 1. Filer

Der uploades præcis to ting:

```
<forældermappe>/
├── studie-plus-skema-service-secrets.php   ← hemmeligheder (chmod 600), uploades manuelt
└── <webroot>/                              ← indholdet af src/
    ├── index.php  .htaccess  robots.txt  sitemap.xml
    ├── assets/  .well-known/
    └── app/                                ← koden (spærret over HTTP)
```

- **`src/`** uploades automatisk af GitHub Actions ([ftp-upload.yml](.github/workflows/ftp-upload.yml)) ved push til `main`, til `server-dir` i workflowet. Den mappe skal være domænets webroot.
- **Secrets-filen** ligger i mappen lige over webroot. `Config::load()` leder efter `dirname(webroot)/studie-plus-skema-service-secrets.php`.

`index.php` finder koden via `app/bootstrap.php`. `app/.htaccess` afviser alle direkte forespørgsler, så koden ikke kan hentes, heller ikke hvis `mod_rewrite` mangler.

> **Kontrollér:** Hvis webroot er en undermappe af en anden webroot (fx et subdomæne i `public_html/<navn>/`), ligger mappen over webroot selv i en webroot. Filen giver intet output, hvis den kaldes direkte, men læg den helst et sted, der ikke er offentligt.

### 2. Database

1. Opret en MySQL-database i kontrolpanelet.
2. Kør `database/schema.sql` via phpMyAdmin.
3. Brug en databasebruger med **kun** `SELECT`, `INSERT`, `UPDATE` og `DELETE` på denne database. Uvist: om Simply.com lader dig begrænse rettigheder pr. bruger. Er det ikke muligt, så brug en database, der kun bruges til denne tjeneste.

### 3. Secrets-fil

```sh
# Udfyld config/studie-plus-skema-service-secrets.example.php lokalt, upload den som
# studie-plus-skema-service-secrets.php til mappen over webroot, og kør så:
chmod 600 studie-plus-skema-service-secrets.php
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'   # → master_key
```

| Felt | Indhold |
|---|---|
| `db.dsn`, `db.user`, `db.password` | Databaseforbindelsen (fra kontrolpanelet) |
| `master_key` | 32 tilfældige bytes, base64. **Skiftes eller mistes nøglen, kan gemte logins ikke læses, og alle feed-links holder op med at virke** |
| `base_url` | `https://<domæne>` uden afsluttende `/` |
| `production` | `true` (kræver HTTPS og sender HSTS) |
| `log_file` | Fx `<forældermappe>/studie-plus-skema-service.log` (uden for webroot, skrivbar). Udelades den, logges til PHP's `error_log` |
| `studieplus.enabled` | `false`, indtil GWT-hentningen er verificeret |
| `studieplus.instnr` | `621401` (HANSENBERG) |

Filen er nævnt i `.gitignore` og må aldrig committes eller lægges i webroot. `Config::load()` nægter at bruge den, hvis den ligger under `DOCUMENT_ROOT`.

### 4. Cron

Log ind med SSH og kør `crontab -e`:

```cron
*/15 * * * * /usr/local/bin/php <webroot>/app/bin/cron.php >/dev/null 2>&1
```

- `/usr/local/bin/php` følger den PHP-version, du har valgt (ifølge Simply.com).
- SSH-cron har ingen timeout ifølge Simply.com. Scriptet stopper alligevel selv efter 12 minutter.
- Brug **ikke** Simply.coms URL-baserede cron til dette. Den har samme HTTP-timeout som en browser, og den ville kræve et offentligt endpoint.
- Kontrollér efter en time, at logfilen indeholder `cron_done`-linjer.

## Tests

```sh
php tests/run.php            # alle tests
php tests/run.php ical       # kun tests hvis navn indeholder "ical"
```

- Testene kræver PHP 8.2+ med `sodium` og `mbstring`.
- De bruger kun mockdata, en in-memory store og en falsk skemakilde. De kontakter aldrig Studie+ og kræver ingen database.
- De dækker:
  - iCalendar-struktur, CRLF, escaping og linjefoldning
  - vinter- og sommertid samt skiftedagen
  - stabile UID'er og SEQUENCE
  - aflyste og fjernede lektioner
  - datointervallet
  - opdateringsplan og backoff
  - kryptering og tokens
  - brugeradskillelse
  - ugyldige, roterede og slettede tokens
  - sikker HTML-konvertering
  - at fejl og tomme svar ikke overskriver gyldige data
  - låse
  - den generiske GWT-læser
- Ikke dækket: `MysqlStore` (SQL'en er ikke kørt), `StudiePlusClient` (kræver Studie+) og `Web\App` (HTTP-laget).

## Sikkerhed og persondata

**Adgangskoder.** Studie+-brugernavn og -adgangskode krypteres med XChaCha20-Poly1305 (libsodium). Associated data binder chifferteksten til den enkelte bruger og det enkelte felt. Nøglen afledes fra `master_key` i secrets-filen.

> **Begrænsning:** Nøglen ligger på samme server som databasen. Det er nødvendigt for automatisk login. Bliver webhotellet kompromitteret, kan alle gemte adgangskoder afsløres. Det står på privatlivssiden, og brugerne opfordres til ikke at genbruge adgangskoden.

**Administration.** Brugeren logger ind med brugernavn og adgangskode, som sammenlignes med de gemte værdier i konstant tid. Sessionen udløber efter 15 minutter uden aktivitet, og der er ingen "husk mig". Her kan brugeren lave et nyt link (det gamle virker straks ikke) eller slette alle sine data.

**Feed-token.** Det vises kun én gang og gemmes kun som HMAC. Ugyldige, roterede og slettede tokens giver samme 404.

**Lækage.**

- Siderne sender `Referrer-Policy: no-referrer`, en streng CSP (ingen eksterne ressourcer eller analytics), `X-Frame-Options: DENY` og `Cache-Control: no-store`.
- Feeden sender desuden `X-Robots-Tag: noindex`.
- Tjenestens egen log indeholder kun bruger-id, fejlkoder og antal. PDO-fejlbeskeder logges ikke, og `Logger::redact` fjerner feed-stier og token-lignende strenge.

> **Begrænsning:** Simply.coms Apache-adgangslog registrerer formentlig request-linjen, og den indeholder feed-tokenet. Det kan applikationen ikke forhindre på et delt webhotel. Hvis et link kan være lækket, så lav et nyt.

**Formularer.** CSRF-token i sessionen, tjek af `Origin` og en sessionscookie med `HttpOnly`, `Secure` og `SameSite=Strict`.

**Rate limiting** (pr. hashet IP og pr. hashet brugernavn):

| Handling | Grænse |
|---|---|
| Oprettelse | 5 pr. 15 min. pr. IP, 5 pr. time pr. brugernavn |
| Administrations-login | 5 pr. 15 min. pr. IP, 5 pr. time pr. brugernavn |
| Ugyldige feed-tokens | 30 pr. time pr. IP |

Hvis rate limiting fejler, afvises forespørgslen.

**HTTPS.** I produktion bliver HTTP til en redirect, og der sendes HSTS. `X-Forwarded-Proto` accepteres. Kontrollér, at Simply.com sætter den eller `HTTPS`.

**Hvad der gemmes:**

- krypteret login
- HMAC af brugernavn og token
- kalenderfelter for intervallet
- fejlkode og tidspunkter
- datoen for seneste feed-hentning

**Hvad der ikke gemmes:** fravær, IP-adresser (kun som kortlivede HMAC-tællere), cookies og Studie+-sessioner.

**Sletning.** Brugeren kan selv slette sine data under *Administrér*. Data slettes desuden automatisk, hvis feeden ikke er hentet i 90 dage.

## Næste skridt: GWT-hentningen

Det, der mangler, er `StudiePlusScheduleSource::requestSchedule()` og `SkemaResponseMapper::map()`. De bygges ud fra ét anonymiseret eksempel:

1. Log ind i Studie+ i en browser, og åbn DevTools → *Network*.
2. Åbn skemaet, og find kaldet til `skemaservice`.
3. Kopiér:
   - request-body'en (linjen der starter med `7|…`)
   - værdierne af `X-GWT-Permutation` og `X-GWT-Module-Base`
   - svaret (`//OK[…]`)
4. Anonymisér: erstat navne, initialer, lokaler, noter og lektier i svarets strengtabel med pladsholdere som `LÆRER1` og `NOTE1`. Bevar tal, rækkefølge og struktur. Fjern fravær, hvis det optræder.
5. Send **aldrig** `Cookie`-headeren eller andre headers end de to ovenfor.

Eksemplet lægges i `tests/fixtures/` som testdata. Der skrives tests mod det, før `studieplus.enabled` sættes til `true`.
