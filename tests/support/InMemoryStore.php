<?php

declare(strict_types=1);

use SkemaService\Store\Store;

/** Store-implementering i hukommelsen, kun til tests. */
final class InMemoryStore implements Store
{
    /** @var array<int, array<string, mixed>> */
    public array $users = [];
    /** @var array<int, list<array<string, mixed>>> */
    public array $events = [];
    /** @var array<int, true> */
    public array $locks = [];
    /** @var array<string, array{window: int, hits: int}> */
    public array $rate = [];
    private int $nextId = 1;

    public function findUserById(int $id): ?array
    {
        return $this->users[$id] ?? null;
    }

    public function findUserByLookup(string $lookupHash): ?array
    {
        foreach ($this->users as $u) {
            if ($u['lookup_hash'] === $lookupHash) {
                return $u;
            }
        }

        return null;
    }

    public function findUserByTokenHash(string $tokenHash): ?array
    {
        foreach ($this->users as $u) {
            if (hash_equals($u['token_hash'], $tokenHash)) {
                return $u;
            }
        }

        return null;
    }

    public function createUser(string $lookupHash, string $usernameEnc, string $passwordEnc, string $tokenHash, DateTimeImmutable $now): int
    {
        if ($this->findUserByLookup($lookupHash) !== null) {
            throw new RuntimeException('duplicate lookup_hash');
        }
        $id = $this->nextId++;
        $this->users[$id] = [
            'id' => $id,
            'lookup_hash' => $lookupHash,
            'username_enc' => $usernameEnc,
            'password_enc' => $passwordEnc,
            'token_hash' => $tokenHash,
            'status' => self::STATUS_ACTIVE,
            'failure_count' => 0,
            'empty_streak' => 0,
            'last_attempt_at' => null,
            'last_success_at' => null,
            'last_error' => null,
            'created_at' => self::ts($now),
            'feed_last_access_on' => $now->format('Y-m-d'),
        ];
        $this->events[$id] = [];

        return $id;
    }

    public function updateCredentials(int $id, string $usernameEnc, string $passwordEnc, ?string $tokenHash, DateTimeImmutable $now): void
    {
        $this->users[$id]['username_enc'] = $usernameEnc;
        $this->users[$id]['password_enc'] = $passwordEnc;
        $this->users[$id]['status'] = self::STATUS_ACTIVE;
        $this->users[$id]['failure_count'] = 0;
        $this->users[$id]['empty_streak'] = 0;
        $this->users[$id]['last_error'] = null;
        if ($tokenHash !== null) {
            $this->users[$id]['token_hash'] = $tokenHash;
        }
    }

    public function rotateToken(int $id, string $tokenHash, DateTimeImmutable $now): void
    {
        $this->users[$id]['token_hash'] = $tokenHash;
    }

    public function deleteUser(int $id): void
    {
        unset($this->users[$id], $this->events[$id]);
    }

    public function syncCandidates(): array
    {
        $out = [];
        foreach ($this->users as $u) {
            if ($u['status'] === self::STATUS_ACTIVE) {
                $out[] = ['id' => $u['id'], 'status' => $u['status'], 'failure_count' => $u['failure_count'], 'last_attempt_at' => $u['last_attempt_at']];
            }
        }

        return $out;
    }

    public function acquireUserLock(int $id): bool
    {
        if (isset($this->locks[$id])) {
            return false;
        }
        $this->locks[$id] = true;

        return true;
    }

    public function releaseUserLock(int $id): void
    {
        unset($this->locks[$id]);
    }

    public function markAttempt(int $id, DateTimeImmutable $now): void
    {
        $this->users[$id]['last_attempt_at'] = self::ts($now);
    }

    public function markSuccess(int $id, DateTimeImmutable $now): void
    {
        $this->users[$id]['last_success_at'] = self::ts($now);
        $this->users[$id]['failure_count'] = 0;
        $this->users[$id]['empty_streak'] = 0;
        $this->users[$id]['last_error'] = null;
    }

    public function markFailure(int $id, string $errorCode, bool $needsReauth, DateTimeImmutable $now): void
    {
        $this->users[$id]['failure_count']++;
        $this->users[$id]['last_error'] = $errorCode;
        if ($needsReauth) {
            $this->users[$id]['status'] = self::STATUS_NEEDS_REAUTH;
        }
    }

    public function incrementEmptyStreak(int $id): int
    {
        return ++$this->users[$id]['empty_streak'];
    }

    public function eventsForUser(int $id): array
    {
        return $this->events[$id] ?? [];
    }

    public function replaceEvents(int $id, array $events): void
    {
        $this->events[$id] = $events;
    }

    public function touchFeedAccess(int $id, DateTimeImmutable $now): void
    {
        $this->users[$id]['feed_last_access_on'] = $now->format('Y-m-d');
    }

    public function deleteInactiveUsers(DateTimeImmutable $cutoff): int
    {
        $count = 0;
        foreach ($this->users as $id => $u) {
            if ($u['feed_last_access_on'] < $cutoff->format('Y-m-d')) {
                $this->deleteUser($id);
                $count++;
            }
        }

        return $count;
    }

    public function hitRateLimit(string $bucketKey, int $windowSeconds, DateTimeImmutable $now): int
    {
        $window = intdiv($now->getTimestamp(), $windowSeconds) * $windowSeconds;
        $key = $bucketKey . '|' . $windowSeconds;
        if (!isset($this->rate[$key]) || $this->rate[$key]['window'] !== $window) {
            $this->rate[$key] = ['window' => $window, 'hits' => 0];
        }

        return ++$this->rate[$key]['hits'];
    }

    public function purgeRateLimits(DateTimeImmutable $olderThan): void
    {
        foreach ($this->rate as $key => $r) {
            if ($r['window'] < $olderThan->getTimestamp()) {
                unset($this->rate[$key]);
            }
        }
    }

    private static function ts(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
