<?php

declare(strict_types=1);

namespace SkemaService\Sync;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use SkemaService\Credentials;
use SkemaService\Crypto;
use SkemaService\Logger;
use SkemaService\Schedule\DateRange;
use SkemaService\Schedule\Lesson;
use SkemaService\Store\Store;
use Throwable;

/**
 * Henter skema for én bruger ad gangen og gemmer det sikkert.
 *
 * Garantier:
 *  - Højst én samtidig hentning pr. bruger (databaselås).
 *  - En fejlet hentning ændrer aldrig de senest gemte, gyldige data.
 *  - Et pludseligt tomt svar erstatter først data efter flere bekræftelser.
 *  - Afviste loginoplysninger stopper automatiske forsøg (undgår kontospærring).
 */
final class SyncService
{
    public const OK = 'ok';
    public const LOCKED = 'locked';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';
    public const AUTH_FAILED = 'auth_failed';
    public const KEPT_PREVIOUS = 'kept_previous_after_empty';

    /** Antal tomme svar i træk før en tom kalender accepteres. */
    public const EMPTY_CONFIRMATIONS = 3;

    private Closure $clock;
    private Logger $logger;

    public function __construct(
        private readonly Store $store,
        private readonly ScheduleSource $source,
        private readonly Crypto $crypto,
        ?Closure $clock = null,
        ?Logger $logger = null,
        private readonly int $daysBack = DateRange::DEFAULT_DAYS_BACK,
        private readonly int $daysForward = DateRange::DEFAULT_DAYS_FORWARD,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Opretter eller opdaterer en bruger. Loginoplysningerne gemmes KUN hvis
     * Studie+ accepterer dem og skemaet kan hentes.
     *
     * Med $keepExistingToken beholder en eksisterende bruger sit kalenderlink
     * (fx efter skift af adgangskode); der returneres da token = null.
     *
     * @return array{userId: int, token: ?string}
     *
     * @throws AuthenticationFailed
     * @throws SourceUnavailable
     */
    public function enroll(string $username, #[\SensitiveParameter] string $password, bool $keepExistingToken = false): array
    {
        $username = trim($username);
        $now = $this->now();
        $lessons = $this->source->fetch(new Credentials($username, $password), $this->range($now));

        $lookup = $this->crypto->lookupHash($username);
        $usernameEnc = $this->crypto->encrypt($username, self::associatedData($lookup, 'username'));
        $passwordEnc = $this->crypto->encrypt($password, self::associatedData($lookup, 'password'));

        $existing = $this->store->findUserByLookup($lookup);
        $token = ($existing !== null && $keepExistingToken) ? null : $this->crypto->newFeedToken();
        $tokenHash = $token !== null ? $this->crypto->tokenHash($token) : null;
        if ($existing !== null) {
            $userId = (int) $existing['id'];
            $this->store->updateCredentials($userId, $usernameEnc, $passwordEnc, $tokenHash, $now);
        } else {
            $userId = $this->store->createUser($lookup, $usernameEnc, $passwordEnc, (string) $tokenHash, $now);
        }

        if ($this->store->acquireUserLock($userId)) {
            try {
                $this->storeLessons($userId, $lessons, $now);
                $this->store->markAttempt($userId, $now);
                $this->store->markSuccess($userId, $now);
            } finally {
                $this->store->releaseUserLock($userId);
            }
        }
        $this->logger->info('enrolled', ['user' => $userId, 'events' => count($lessons), 'existing' => $existing !== null]);

        return ['userId' => $userId, 'token' => $token];
    }

    public function syncUser(int $userId): string
    {
        if (!$this->store->acquireUserLock($userId)) {
            return self::LOCKED;
        }
        try {
            $user = $this->store->findUserById($userId);
            if ($user === null || $user['status'] !== Store::STATUS_ACTIVE) {
                return self::SKIPPED;
            }
            $now = $this->now();
            $this->store->markAttempt($userId, $now);

            try {
                $credentials = $this->credentialsFor($user);
            } catch (Throwable $e) {
                $this->store->markFailure($userId, 'decrypt_failed', true, $now);
                $this->logger->exception('decrypt_failed', $e, ['user' => $userId]);

                return self::AUTH_FAILED;
            }

            try {
                $lessons = $this->source->fetch($credentials, $this->range($now));
            } catch (AuthenticationFailed) {
                $this->store->markFailure($userId, 'auth_failed', true, $now);
                $this->logger->warning('auth_failed', ['user' => $userId]);

                return self::AUTH_FAILED;
            } catch (SourceUnavailable $e) {
                $this->store->markFailure($userId, $e->errorCode, false, $now);
                $this->logger->warning('source_unavailable', ['user' => $userId, 'code' => $e->errorCode]);

                return self::FAILED;
            } catch (Throwable $e) {
                $this->store->markFailure($userId, 'internal_error', false, $now);
                $this->logger->exception('sync_error', $e, ['user' => $userId]);

                return self::FAILED;
            }

            $lessons = $this->filter($lessons, $now);
            if ($lessons === [] && $this->store->eventsForUser($userId) !== []) {
                $streak = $this->store->incrementEmptyStreak($userId);
                if ($streak < self::EMPTY_CONFIRMATIONS) {
                    $this->logger->warning('empty_result_kept_previous', ['user' => $userId, 'streak' => $streak]);

                    return self::KEPT_PREVIOUS;
                }
            }

            $this->storeLessons($userId, $lessons, $now);
            $this->store->markSuccess($userId, $now);

            return self::OK;
        } finally {
            $this->store->releaseUserLock($userId);
        }
    }

    /** Laver et nyt feed-token; det gamle virker straks ikke længere. */
    public function rotateToken(int $userId): string
    {
        $token = $this->crypto->newFeedToken();
        $this->store->rotateToken($userId, $this->crypto->tokenHash($token), $this->now());

        return $token;
    }

    /** Kontrollerer brugernavn/adgangskode mod de gemte (krypterede) værdier. */
    public function verifyStoredCredentials(string $username, #[\SensitiveParameter] string $password): ?int
    {
        $user = $this->store->findUserByLookup($this->crypto->lookupHash($username));
        if ($user === null) {
            // Udfør en tilsvarende operation for at udjævne svartid.
            hash_equals(hash('sha256', 'x'), hash('sha256', $password));

            return null;
        }
        try {
            $stored = $this->credentialsFor($user);
        } catch (Throwable) {
            return null;
        }

        return hash_equals(hash('sha256', $stored->password()), hash('sha256', $password)) ? (int) $user['id'] : null;
    }

    public static function associatedData(string $lookupHash, string $field): string
    {
        return 'sps|' . $lookupHash . '|' . $field;
    }

    /** @param array<string, mixed> $user */
    private function credentialsFor(array $user): Credentials
    {
        $lookup = (string) $user['lookup_hash'];

        return new Credentials(
            $this->crypto->decrypt((string) $user['username_enc'], self::associatedData($lookup, 'username')),
            $this->crypto->decrypt((string) $user['password_enc'], self::associatedData($lookup, 'password')),
        );
    }

    /**
     * @param list<Lesson> $lessons
     * @return list<Lesson>
     */
    private function filter(array $lessons, DateTimeImmutable $now): array
    {
        $range = $this->range($now);
        $seen = [];
        $out = [];
        foreach ($lessons as $lesson) {
            if (!$lesson instanceof Lesson || !$range->overlaps($lesson) || isset($seen[$lesson->sourceId])) {
                continue;
            }
            $seen[$lesson->sourceId] = true;
            $out[] = $lesson;
        }

        return $out;
    }

    /** @param list<Lesson> $lessons */
    private function storeLessons(int $userId, array $lessons, DateTimeImmutable $now): void
    {
        $existing = [];
        foreach ($this->store->eventsForUser($userId) as $row) {
            $existing[$row['uid']] = $row;
        }
        $nowUtc = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $rows = [];
        foreach ($this->filter($lessons, $now) as $lesson) {
            $uid = $this->crypto->eventUid($userId, $lesson->sourceId);
            $hash = $lesson->contentHash();
            $previous = $existing[$uid] ?? null;
            if ($previous === null) {
                $sequence = 0;
                $lastModified = $nowUtc;
            } elseif ($previous['content_hash'] === $hash) {
                $sequence = (int) $previous['sequence'];
                $lastModified = (string) $previous['last_modified'];
            } else {
                $sequence = (int) $previous['sequence'] + 1;
                $lastModified = $nowUtc;
            }
            $rows[] = [
                'uid' => $uid,
                'sequence' => $sequence,
                'content_hash' => $hash,
                'last_modified' => $lastModified,
                'payload' => json_encode($lesson->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ];
        }
        $this->store->replaceEvents($userId, $rows);
    }

    private function range(DateTimeImmutable $now): DateRange
    {
        return DateRange::around($now, $this->daysBack, $this->daysForward);
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }
}
