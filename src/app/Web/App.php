<?php

declare(strict_types=1);

namespace SkemaService\Web;

use DateTimeImmutable;
use DateTimeZone;
use SkemaService\Config;
use SkemaService\Crypto;
use SkemaService\Feed\FeedService;
use SkemaService\Logger;
use SkemaService\Store\Store;
use SkemaService\StudiePlus\StudiePlusScheduleSource;
use SkemaService\Sync\AuthenticationFailed;
use SkemaService\Sync\SourceUnavailable;
use SkemaService\Sync\SyncService;
use Throwable;

/** Front controller for website og feed. */
final class App
{
    private const MANAGE_IDLE_SECONDS = 15 * 60;

    public function __construct(
        private readonly Config $config,
        private readonly Store $store,
        private readonly Crypto $crypto,
        private readonly SyncService $sync,
        private readonly FeedService $feed,
        private readonly StudiePlusScheduleSource $source,
        private readonly Logger $logger,
    ) {
    }

    public function run(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $this->logRequestOnShutdown($method, $path);

        if ($this->config->isProduction() && !self::isHttps()) {
            $this->logger->debug('https_redirect');
            if ($method === 'GET' || $method === 'HEAD') {
                header('Location: ' . $this->config->baseUrl() . (str_starts_with($path, '/feed/') ? '/' : $path), true, 301);
            } else {
                http_response_code(400);
            }

            return;
        }

        if (str_starts_with($path, '/feed/')) {
            $this->feed($path, $method);

            return;
        }

        $this->htmlHeaders();
        $this->startSession();

        match (true) {
            $method === 'GET' && $path === '/' => $this->home(),
            $method === 'POST' && $path === '/opret' => $this->create(),
            $method === 'GET' && $path === '/administrer' => $this->manage(),
            $method === 'POST' && $path === '/administrer/log-ind' => $this->manageLogin(),
            $method === 'POST' && $path === '/administrer/nyt-link' => $this->rotate(),
            $method === 'POST' && $path === '/administrer/slet' => $this->delete(),
            $method === 'POST' && $path === '/administrer/log-ud' => $this->logout(),
            $method === 'GET' && $path === '/privatliv' => View::render('privacy'),
            $method === 'GET' && $path === '/direkte-login' => View::render('direct_login'),
            default => $this->notFound(),
        };
    }

    private function notFound(): void
    {
        $this->logger->info('not_found');
        View::render('message', ['title' => 'Siden findes ikke', 'text' => 'Gå tilbage til forsiden.'], 404);
    }

    /**
     * Én linje pr. request, skrevet til sidst (også efter fejl), med den endelige statuskode.
     * Feed-stier og query strings logges aldrig; IP kun som forkortet hash.
     */
    private function logRequestOnShutdown(string $method, string $path): void
    {
        $started = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $context = [
            'method' => Logger::redact(substr($method, 0, 10)),
            'path' => str_starts_with($path, '/feed/') ? '/feed/[skjult]' : Logger::redact(substr($path, 0, 120)),
            'query' => ($_SERVER['QUERY_STRING'] ?? '') !== '',
            'ip' => substr($this->ipKey(), 0, 12),
            'https' => self::isHttps(),
            'ua' => Logger::redact(substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 120)),
        ];
        register_shutdown_function(function () use ($context, $started): void {
            $status = http_response_code();
            $this->logger->info('request', $context + [
                'status' => is_int($status) ? $status : 0,
                'ms' => (int) round((microtime(true) - $started) * 1000),
                'memory_peak_kb' => intdiv(memory_get_peak_usage(true), 1024),
            ]);
        });
    }

    // ---------------------------------------------------------------- feed

    private function feed(string $path, string $method): void
    {
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        if ($this->config->isProduction()) {
            header('Strict-Transport-Security: max-age=31536000');
        }

        if ($method !== 'GET' && $method !== 'HEAD') {
            $this->logger->info('feed_method_not_allowed');
            http_response_code(405);

            return;
        }
        $token = preg_match('#^/feed/([A-Za-z0-9_-]{43})\.ics$#', $path, $m) === 1 ? $m[1] : '';
        if ($token === '') {
            $this->logger->info('feed_path_malformed');
        }
        $body = $token !== '' ? $this->feed->render($token) : null;

        if ($body === null) {
            $tooMany = $this->rateLimited('feed_invalid', $this->ipKey(), 30, 3600);
            http_response_code($tooMany ? 429 : 404);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo $tooMany ? "For mange forsøg.\n" : "Kalenderen findes ikke.\n";

            return;
        }

        $etag = '"' . substr(hash('sha256', $body), 0, 32) . '"';
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="skema.ics"');
        header('Cache-Control: private, no-cache');
        header('ETag: ' . $etag);
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            $this->logger->debug('feed_not_modified');
            http_response_code(304);

            return;
        }
        if ($method === 'GET') {
            echo $body;
        }
    }

    // ---------------------------------------------------------------- sider

    private function home(): void
    {
        View::render('home', ['enabled' => $this->source->isEnabled()]);
    }

    private function create(): void
    {
        if (!$this->checkCsrf()) {
            return;
        }
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $consent = ($_POST['consent'] ?? '') === 'yes';
        $keepLink = ($_POST['keep_link'] ?? '') === 'yes';

        $this->logger->debug('create_attempt', ['consent' => $consent, 'keep_link' => $keepLink]);

        $error = null;
        $reason = null;
        if (!$this->source->isEnabled()) {
            [$reason, $error] = ['source_disabled', 'Tjenesten er ikke aktiveret endnu. Prøv igen senere.'];
        } elseif ($username === '' || $password === '' || mb_strlen($username) > 100 || strlen($password) > 200) {
            [$reason, $error] = ['invalid_input', 'Udfyld både brugernavn og adgangskode.'];
        } elseif (!$consent) {
            [$reason, $error] = ['no_consent', 'Du skal acceptere, hvordan dine oplysninger gemmes, før vi kan oprette din kalender.'];
        } elseif ($this->rateLimited('create_ip', $this->ipKey(), 5, 900)
            || $this->rateLimited('create_user', $this->crypto->lookupHash($username), 5, 3600)) {
            [$reason, $error] = ['rate_limited', 'For mange forsøg. Vent et kvarter og prøv igen.'];
        }
        if ($error !== null) {
            $this->logger->info('create_rejected', ['reason' => $reason]);
            View::render('home', ['enabled' => $this->source->isEnabled(), 'error' => $error, 'username' => $username], 400);

            return;
        }

        try {
            $result = $this->sync->enroll($username, $password, $keepLink);
        } catch (AuthenticationFailed) {
            View::render('home', [
                'enabled' => true,
                'username' => $username,
                'error' => 'Studie+ godkendte ikke brugernavn og adgangskode. Bemærk: du skal bruge et "Direkte" Studie+-login. Logger du normalt ind med UNI-Login eller MitID, skal du først oprette et Direkte login (se guiden under "Direkte login" i menuen).',
            ], 400);

            return;
        } catch (SourceUnavailable $e) {
            $this->logger->warning('enroll_source_unavailable', ['code' => $e->errorCode]);
            View::render('home', [
                'enabled' => true,
                'username' => $username,
                'error' => 'Vi kunne ikke hente dit skema fra Studie+ lige nu. Der er ikke gemt noget. Prøv igen senere.',
            ], 503);

            return;
        }

        $this->logger->info('create_done', ['user' => $result['userId'], 'new_link' => $result['token'] !== null]);
        if ($result['token'] === null) {
            View::render('message', [
                'title' => 'Dine oplysninger er opdateret',
                'text' => 'Dit eksisterende kalenderlink virker stadig, og kalenderen opdateres igen automatisk.',
            ]);

            return;
        }
        View::render('created', ['feedUrl' => $this->feedUrl($result['token']), 'host' => $this->host()]);
    }

    private function manage(): void
    {
        $userId = $this->managedUserId();
        if ($userId === null) {
            $this->logger->debug('manage_login_form');
            View::render('manage_login');

            return;
        }
        $user = $this->store->findUserById($userId);
        if ($user === null) {
            $this->logger->info('manage_user_gone', ['user' => $userId]);
            $this->endManageSession();
            View::render('manage_login');

            return;
        }
        $this->logger->debug('manage_view', ['user' => $userId, 'status' => (string) $user['status']]);
        View::render('manage', ['user' => self::publicStatus($user)]);
    }

    private function manageLogin(): void
    {
        if (!$this->checkCsrf()) {
            return;
        }
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($this->rateLimited('manage_ip', $this->ipKey(), 5, 900)
            || ($username !== '' && $this->rateLimited('manage_user', $this->crypto->lookupHash($username), 5, 3600))) {
            $this->logger->info('manage_login_rejected', ['reason' => 'rate_limited']);
            View::render('manage_login', ['error' => 'For mange forsøg. Vent et kvarter og prøv igen.'], 429);

            return;
        }
        $userId = ($username !== '' && $password !== '') ? $this->sync->verifyStoredCredentials($username, $password) : null;
        if ($userId === null) {
            $this->logger->info('manage_login_rejected', ['reason' => ($username === '' || $password === '') ? 'empty_input' : 'no_match']);
            View::render('manage_login', [
                'error' => 'Vi fandt ingen kalender med de oplysninger. Har du skiftet adgangskode i Studie+, så opret din kalender igen på forsiden.',
            ], 400);

            return;
        }
        session_regenerate_id(true);
        $_SESSION['manage'] = ['uid' => $userId, 'at' => time()];
        $this->logger->info('manage_login_ok', ['user' => $userId]);
        $this->redirect('/administrer');
    }

    private function rotate(): void
    {
        if (!$this->checkCsrf()) {
            return;
        }
        $userId = $this->managedUserId();
        if ($userId === null) {
            $this->redirect('/administrer');

            return;
        }
        $token = $this->sync->rotateToken($userId);
        $this->logger->info('token_rotated', ['user' => $userId]);
        View::render('created', ['feedUrl' => $this->feedUrl($token), 'host' => $this->host(), 'rotated' => true]);
    }

    private function delete(): void
    {
        if (!$this->checkCsrf()) {
            return;
        }
        $userId = $this->managedUserId();
        if ($userId === null) {
            $this->redirect('/administrer');

            return;
        }
        if (($_POST['confirm'] ?? '') !== 'yes') {
            $this->logger->info('delete_not_confirmed', ['user' => $userId]);
            $user = $this->store->findUserById($userId);
            View::render('manage', ['user' => $user !== null ? self::publicStatus($user) : [], 'error' => 'Sæt flueben for at bekræfte sletningen.'], 400);

            return;
        }
        $this->store->deleteUser($userId);
        $this->logger->info('user_deleted', ['user' => $userId]);
        $this->endManageSession();
        View::render('message', [
            'title' => 'Dine data er slettet',
            'text' => 'Dine loginoplysninger, dit kalenderlink og dit gemte skema er slettet. Fjern også kalenderen i din kalender-app.',
        ]);
    }

    private function logout(): void
    {
        if (!$this->checkCsrf()) {
            return;
        }
        $this->logger->info('manage_logout', ['user' => $this->managedUserId()]);
        $this->endManageSession();
        $this->redirect('/');
    }

    // ---------------------------------------------------------------- hjælpere

    /**
     * Kun ufarlige statusfelter sendes til skabelonen.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private static function publicStatus(array $user): array
    {
        $fmt = static function (?string $utc): ?string {
            if ($utc === null || $utc === '') {
                return null;
            }

            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Europe/Copenhagen'))->format('d.m.Y \k\l. H:i');
        };

        return [
            'status' => (string) $user['status'],
            'lastSuccess' => $fmt($user['last_success_at'] ?? null),
            'lastAttempt' => $fmt($user['last_attempt_at'] ?? null),
            'lastError' => $user['last_error'] ?? null,
            'created' => $fmt($user['created_at'] ?? null),
        ];
    }

    private function managedUserId(): ?int
    {
        $m = $_SESSION['manage'] ?? null;
        if (!is_array($m) || !isset($m['uid'], $m['at'])) {
            return null;
        }
        if (time() - (int) $m['at'] > self::MANAGE_IDLE_SECONDS) {
            $this->logger->info('manage_session_expired', ['user' => (int) $m['uid'], 'idle_seconds' => time() - (int) $m['at']]);
            $this->endManageSession();

            return null;
        }
        $_SESSION['manage']['at'] = time();

        return (int) $m['uid'];
    }

    private function endManageSession(): void
    {
        unset($_SESSION['manage']);
        session_regenerate_id(true);
    }

    private function checkCsrf(): bool
    {
        if (Csrf::isValid($_POST, $_SERVER, $this->config->baseUrl())) {
            return true;
        }
        $this->logger->warning('csrf_rejected', [
            'has_token' => isset($_POST['csrf']),
            'has_session_token' => isset($_SESSION['csrf']),
            'origin' => Logger::redact(substr((string) ($_SERVER['HTTP_ORIGIN'] ?? '-'), 0, 80)),
        ]);
        View::render('message', [
            'title' => 'Formularen er udløbet',
            'text' => 'Gå tilbage, genindlæs siden og prøv igen.',
        ], 400);

        return false;
    }

    private function rateLimited(string $action, string $subject, int $max, int $windowSeconds): bool
    {
        try {
            $count = $this->store->hitRateLimit($action . '|' . $subject, $windowSeconds, new DateTimeImmutable());
            if ($count > $max) {
                $this->logger->warning('rate_limited', ['action' => $action, 'count' => $count, 'max' => $max, 'window_s' => $windowSeconds]);

                return true;
            }
            $this->logger->debug('rate_limit_hit', ['action' => $action, 'count' => $count, 'max' => $max]);

            return false;
        } catch (Throwable $e) {
            $this->logger->exception('rate_limit_error', $e, ['action' => $action]);

            return true; // fejl lukket
        }
    }

    private function ipKey(): string
    {
        return $this->crypto->ipHash((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    private function feedUrl(string $token): string
    {
        return $this->config->baseUrl() . '/feed/' . $token . '.ics';
    }

    private function host(): string
    {
        return (string) parse_url($this->config->baseUrl(), PHP_URL_HOST);
    }

    private function redirect(string $path): void
    {
        header('Location: ' . $path, true, 303);
    }

    private function htmlHeaders(): void
    {
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Cache-Control: no-store');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        if ($this->config->isProduction()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    private function startSession(): void
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name('sps_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
