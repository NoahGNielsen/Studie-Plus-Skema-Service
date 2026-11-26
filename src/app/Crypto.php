<?php

declare(strict_types=1);

namespace SkemaService;

use RuntimeException;

/**
 * Kryptografi baseret på libsodium.
 *
 * Fra én master-nøgle afledes separate undernøgler til:
 *  - kryptering af Studie+-brugernavn/adgangskode (XChaCha20-Poly1305)
 *  - opslags-hash af brugernavn (HMAC-SHA256)
 *  - hash af feed-token (HMAC-SHA256) — selve tokenet gemmes aldrig
 *  - stabile, ikke-gættelige UID'er for kalenderbegivenheder
 *  - hash af IP-adresser til rate limiting
 */
final class Crypto
{
    private const CONTEXT = 'SPSKEMA1';
    private const CIPHER_PREFIX = 'v1:';
    public const TOKEN_BYTES = 32;

    private string $encKey;
    private string $lookupKey;
    private string $tokenKey;
    private string $uidKey;
    private string $ipKey;

    public function __construct(#[\SensitiveParameter] string $masterKey)
    {
        if (strlen($masterKey) !== SODIUM_CRYPTO_KDF_KEYBYTES) {
            throw new RuntimeException('Ugyldig master-nøgle.');
        }
        $this->encKey = sodium_crypto_kdf_derive_from_key(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, 1, self::CONTEXT, $masterKey);
        $this->lookupKey = sodium_crypto_kdf_derive_from_key(32, 2, self::CONTEXT, $masterKey);
        $this->tokenKey = sodium_crypto_kdf_derive_from_key(32, 3, self::CONTEXT, $masterKey);
        $this->uidKey = sodium_crypto_kdf_derive_from_key(32, 4, self::CONTEXT, $masterKey);
        $this->ipKey = sodium_crypto_kdf_derive_from_key(32, 5, self::CONTEXT, $masterKey);
    }

    /** $associatedData binder chifferteksten til en bestemt bruger og et bestemt felt. */
    public function encrypt(#[\SensitiveParameter] string $plaintext, string $associatedData): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $associatedData, $nonce, $this->encKey);

        return self::CIPHER_PREFIX . base64_encode($nonce . $cipher);
    }

    public function decrypt(string $encoded, string $associatedData): string
    {
        if (!str_starts_with($encoded, self::CIPHER_PREFIX)) {
            throw new RuntimeException('Ukendt krypteringsformat.');
        }
        $raw = base64_decode(substr($encoded, strlen(self::CIPHER_PREFIX)), true);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($raw === false || strlen($raw) <= $nonceLength) {
            throw new RuntimeException('Beskadiget chiffertekst.');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($raw, $nonceLength),
            $associatedData,
            substr($raw, 0, $nonceLength),
            $this->encKey,
        );
        if ($plain === false) {
            throw new RuntimeException('Dekryptering mislykkedes.');
        }

        return $plain;
    }

    public static function normalizeUsername(string $username): string
    {
        return mb_strtolower(trim($username), 'UTF-8');
    }

    public function lookupHash(string $username): string
    {
        return hash_hmac('sha256', self::normalizeUsername($username), $this->lookupKey);
    }

    /** Nyt feed-token: 256 bit, base64url uden padding (43 tegn). */
    public function newFeedToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    public static function isWellFormedToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }

    public function tokenHash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->tokenKey);
    }

    /** Stabil UID: samme bruger + samme Studie+-begivenhed giver altid samme UID. */
    public function eventUid(int $userId, string $sourceId): string
    {
        return substr(hash_hmac('sha256', $userId . "\0" . $sourceId, $this->uidKey), 0, 40) . '@studie-plus-skema-service';
    }

    public function ipHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, $this->ipKey);
    }

    public function __debugInfo(): array
    {
        return ['keys' => '[skjult]'];
    }
}
