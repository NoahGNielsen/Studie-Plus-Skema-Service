<?php

declare(strict_types=1);

namespace SkemaService\Store;

use DateTimeImmutable;

/**
 * Persistens. Alle forespørgsler er bundet til ét bruger-id, så data
 * aldrig kan blandes mellem brugere.
 *
 * Brugerrækker (array) har nøglerne: id, lookup_hash, username_enc,
 * password_enc, token_hash, status, failure_count, empty_streak,
 * last_attempt_at, last_success_at, last_error, created_at.
 * Tidspunkter er 'Y-m-d H:i:s' i UTC.
 *
 * Begivenhedsrækker (array) har nøglerne: uid, sequence, content_hash,
 * last_modified, payload (JSON).
 */
interface Store
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_NEEDS_REAUTH = 'needs_reauth';

    /** @return array<string, mixed>|null */
    public function findUserById(int $id): ?array;

    /** @return array<string, mixed>|null */
    public function findUserByLookup(string $lookupHash): ?array;

    /** @return array<string, mixed>|null */
    public function findUserByTokenHash(string $tokenHash): ?array;

    public function createUser(string $lookupHash, string $usernameEnc, string $passwordEnc, string $tokenHash, DateTimeImmutable $now): int;

    /** Opdaterer loginoplysninger og nulstiller fejlstatus. $tokenHash = null beholder det nuværende feed-token. */
    public function updateCredentials(int $id, string $usernameEnc, string $passwordEnc, ?string $tokenHash, DateTimeImmutable $now): void;

    public function rotateToken(int $id, string $tokenHash, DateTimeImmutable $now): void;

    /** Sletter brugeren og alle tilhørende skemadata. */
    public function deleteUser(int $id): void;

    /** @return list<array{id: int, status: string, failure_count: int, last_attempt_at: ?string}> */
    public function syncCandidates(): array;

    /** Ikke-blokerende lås pr. bruger; false hvis en anden proces er i gang. */
    public function acquireUserLock(int $id): bool;

    public function releaseUserLock(int $id): void;

    public function markAttempt(int $id, DateTimeImmutable $now): void;

    public function markSuccess(int $id, DateTimeImmutable $now): void;

    public function markFailure(int $id, string $errorCode, bool $needsReauth, DateTimeImmutable $now): void;

    /** Øger tælleren for "mistænkeligt tomme" svar og returnerer den nye værdi. */
    public function incrementEmptyStreak(int $id): int;

    /** @return list<array{uid: string, sequence: int, content_hash: string, last_modified: string, payload: string}> */
    public function eventsForUser(int $id): array;

    /**
     * Erstatter brugerens begivenheder atomisk (i én transaktion).
     *
     * @param list<array{uid: string, sequence: int, content_hash: string, last_modified: string, payload: string}> $events
     */
    public function replaceEvents(int $id, array $events): void;

    /** Registrerer (højst én gang pr. dag) at feeden er hentet — bruges til automatisk sletning af inaktive brugere. */
    public function touchFeedAccess(int $id, DateTimeImmutable $now): void;

    /** Sletter brugere hvis feed ikke er hentet siden $cutoff. Returnerer antal. */
    public function deleteInactiveUsers(DateTimeImmutable $cutoff): int;

    /** Registrerer et hit og returnerer antal hits for nøglen i det aktuelle vindue. */
    public function hitRateLimit(string $bucketKey, int $windowSeconds, DateTimeImmutable $now): int;

    public function purgeRateLimits(DateTimeImmutable $olderThan): void;
}
