<?php

declare(strict_types=1);

namespace SkemaService\StudiePlus;

use CurlHandle;
use SkemaService\Credentials;
use SkemaService\Sync\AuthenticationFailed;
use SkemaService\Sync\SourceUnavailable;

/**
 * HTTP-klient mod Studie+ med en kortlivet session.
 *
 * Status (2026-11-26):
 *  - VERIFICERET (offentlig loginside): formularen poster til /login/doLogin
 *    med felterne instnr og how (UNILOGIN/DIREKTE); HANSENBERG (instnr 621401)
 *    tilbyder DIREKTE og har mfa=false.
 *  - IKKE VERIFICERET: at felterne user/pass accepteres direkte i samme
 *    POST, og at et vellykket login ender på /skema/. Brug
 *    bin/verify-studieplus.php med egne oplysninger til at kontrollere det.
 *
 * Bevidste valg:
 *  - huskmig sendes ALDRIG.
 *  - Cookies holdes kun i hukommelsen og kasseres efter hver hentning.
 *  - Kun HTTPS, også ved redirects. Svar-bodies og cookies indgår aldrig i fejl.
 */
final class StudiePlusClient
{
    private const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;
    private const USER_AGENT = 'StudiePlusSkemaService/1.0 (+personligt skemaabonnement)';

    private ?CurlHandle $curl = null;
    private string $buffer = '';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $instnr,
        private readonly int $timeoutSeconds = 30,
    ) {
        if (!str_starts_with($this->baseUrl, 'https://')) {
            throw new \InvalidArgumentException('Studie+ base_url skal være https.');
        }
    }

    public function login(Credentials $credentials): void
    {
        $this->open();
        $this->get('/login/');
        // Svarer til hvad loginsiden selv sætter via JavaScript ved institutionsvalg.
        curl_setopt($this->curl, CURLOPT_COOKIELIST, 'Set-Cookie: instnr=' . rawurlencode($this->instnr) . '; Domain=' . $this->host() . '; Path=/; Secure');

        $response = $this->request('POST', '/login/doLogin', http_build_query([
            'instnr' => $this->instnr,
            'how' => 'DIREKTE',
            'user' => $credentials->username,
            'pass' => $credentials->password(),
        ]), ['Content-Type: application/x-www-form-urlencoded']);

        $path = (string) parse_url($response['url'], PHP_URL_PATH);
        if (str_starts_with($path, '/skema')) {
            return;
        }
        if (stripos($response['body'], 'Studie+ Login') !== false || str_starts_with($path, '/login')) {
            throw new AuthenticationFailed('Studie+ afviste login.');
        }
        throw new SourceUnavailable('login_flow_unexpected');
    }

    /** @return array{status: int, body: string, url: string} */
    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, null, $headers);
    }

    /**
     * @param list<string> $headers
     * @param bool $allowServerError returnér svar med status 5xx i stedet for at kaste (GWT sender //EX med 500)
     * @return array{status: int, body: string, url: string}
     */
    public function post(string $path, string $body, array $headers = [], bool $allowServerError = false): array
    {
        return $this->request('POST', $path, $body, $headers, $allowServerError);
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    /** Kasserer sessionen (cookies findes kun i denne handle). */
    public function close(): void
    {
        // Handlen (og dermed cookie-lageret) frigives når referencen fjernes;
        // curl_close() er en no-op siden PHP 8.0 og forældet fra 8.5.
        $this->curl = null;
        $this->buffer = '';
    }

    public function __destruct()
    {
        $this->close();
    }

    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl];
    }

    private function open(): void
    {
        $this->close();
        $curl = curl_init();
        if ($curl === false) {
            throw new SourceUnavailable('http_init_failed');
        }
        curl_setopt_array($curl, [
            CURLOPT_COOKIEFILE => '', // cookie-motor kun i hukommelsen
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADER => false,
        ]);
        $this->curl = $curl;
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string, url: string}
     */
    private function request(string $method, string $path, ?string $body, array $headers, bool $allowServerError = false): array
    {
        if ($this->curl === null) {
            $this->open();
        }
        $this->buffer = '';
        $tooLarge = false;
        curl_setopt_array($this->curl, [
            CURLOPT_URL => $this->baseUrl() . $path,
            CURLOPT_HTTPGET => $method === 'GET',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$tooLarge): int {
                if (strlen($this->buffer) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $tooLarge = true;

                    return 0; // afbryd overførslen
                }
                $this->buffer .= $chunk;

                return strlen($chunk);
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($this->curl, CURLOPT_POST, true);
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, (string) $body);
        }

        $ok = curl_exec($this->curl);
        if ($tooLarge) {
            throw new SourceUnavailable('response_too_large');
        }
        if ($ok === false) {
            throw new SourceUnavailable('http_error_' . curl_errno($this->curl));
        }
        $status = (int) curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE);
        $url = (string) curl_getinfo($this->curl, CURLINFO_EFFECTIVE_URL);

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host !== $this->host() && !str_ends_with($host, '.studieplus.dk')) {
            throw new SourceUnavailable('unexpected_redirect_host');
        }
        if ($status >= 500 && !($allowServerError && $status === 500)) {
            throw new SourceUnavailable('http_' . $status);
        }
        if ($status === 429) {
            throw new SourceUnavailable('http_429');
        }

        $result = ['status' => $status, 'body' => $this->buffer, 'url' => $url];
        $this->buffer = '';

        return $result;
    }

    private function host(): string
    {
        return (string) parse_url($this->baseUrl, PHP_URL_HOST);
    }
}
