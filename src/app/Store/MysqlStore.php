<?php

declare(strict_types=1);

namespace SkemaService\Store;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/** MySQL/MariaDB-implementering. Kun parameteriserede forespørgsler. */
final class MysqlStore implements Store
{
    private const USER_COLUMNS = 'id, lookup_hash, username_enc, password_enc, token_hash, status, failure_count, empty_streak, last_attempt_at, last_success_at, last_error, created_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $db */
    public static function connect(array $db): self
    {
        $pdo = new PDO((string) $db['dsn'], (string) $db['user'], (string) $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");

        return new self($pdo);
    }

    public function findUserById(int $id): ?array
    {
        return $this->fetchUser('id = ?', [$id]);
    }

    public function findUserByLookup(string $lookupHash): ?array
    {
        return $this->fetchUser('lookup_hash = ?', [$lookupHash]);
    }

    public function findUserByTokenHash(string $tokenHash): ?array
    {
        return $this->fetchUser('token_hash = ?', [$tokenHash]);
    }

    public function createUser(string $lookupHash, string $usernameEnc, string $passwordEnc, string $tokenHash, DateTimeImmutable $now): int
    {
        $ts = self::ts($now);
        $stmt = $this->pdo->prepare(
            'INSERT INTO sps_users (lookup_hash, username_enc, password_enc, token_hash, status, created_at, updated_at, token_created_at, last_attempt_at, feed_last_access_on)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        // last_attempt_at sættes straks, så cron ikke tager den nye bruger (og låsen), mens oprettelsen gemmer skemaet.
        $stmt->execute([$lookupHash, $usernameEnc, $passwordEnc, $tokenHash, self::STATUS_ACTIVE, $ts, $ts, $ts, $ts, $now->format('Y-m-d')]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateCredentials(int $id, string $usernameEnc, string $passwordEnc, ?string $tokenHash, DateTimeImmutable $now): void
    {
        $ts = self::ts($now);
        $this->pdo->prepare(
            'UPDATE sps_users SET username_enc = ?, password_enc = ?, status = ?, failure_count = 0, empty_streak = 0,
                 last_error = NULL, updated_at = ?, feed_last_access_on = ? WHERE id = ?'
        )->execute([$usernameEnc, $passwordEnc, self::STATUS_ACTIVE, $ts, $now->format('Y-m-d'), $id]);
        if ($tokenHash !== null) {
            $this->rotateToken($id, $tokenHash, $now);
        }
    }

    public function rotateToken(int $id, string $tokenHash, DateTimeImmutable $now): void
    {
        $ts = self::ts($now);
        $this->pdo->prepare('UPDATE sps_users SET token_hash = ?, token_created_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$tokenHash, $ts, $ts, $id]);
    }

    public function deleteUser(int $id): void
    {
        $this->transaction(function () use ($id): void {
            $this->pdo->prepare('DELETE FROM sps_events WHERE user_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM sps_users WHERE id = ?')->execute([$id]);
        });
    }

    public function syncCandidates(): array
    {
        $rows = $this->pdo->query(
            "SELECT id, status, failure_count, last_attempt_at FROM sps_users WHERE status = 'active' ORDER BY last_attempt_at IS NOT NULL, last_attempt_at, id"
        )->fetchAll();

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'status' => (string) $r['status'],
            'failure_count' => (int) $r['failure_count'],
            'last_attempt_at' => $r['last_attempt_at'] !== null ? (string) $r['last_attempt_at'] : null,
        ], $rows);
    }

    public function acquireUserLock(int $id, int $waitSeconds = 0): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([self::lockName($id), max(0, $waitSeconds)]);

        return (int) $stmt->fetchColumn() === 1;
    }

    public function releaseUserLock(int $id): void
    {
        $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([self::lockName($id)]);
    }

    public function acquireNamedLock(string $name): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute(['sps_' . $name]);

        return (int) $stmt->fetchColumn() === 1;
    }

    public function markAttempt(int $id, DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE sps_users SET last_attempt_at = ? WHERE id = ?')->execute([self::ts($now), $id]);
    }

    public function markSuccess(int $id, DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE sps_users SET last_success_at = ?, failure_count = 0, empty_streak = 0, last_error = NULL WHERE id = ?')
            ->execute([self::ts($now), $id]);
    }

    public function markFailure(int $id, string $errorCode, bool $needsReauth, DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'UPDATE sps_users SET failure_count = failure_count + 1, last_error = ?, status = IF(?, ?, status) WHERE id = ?'
        )->execute([substr($errorCode, 0, 64), $needsReauth ? 1 : 0, self::STATUS_NEEDS_REAUTH, $id]);
    }

    public function incrementEmptyStreak(int $id): int
    {
        $this->pdo->prepare('UPDATE sps_users SET empty_streak = empty_streak + 1 WHERE id = ?')->execute([$id]);
        $stmt = $this->pdo->prepare('SELECT empty_streak FROM sps_users WHERE id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
    }

    public function eventsForUser(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT uid, sequence, content_hash, last_modified, payload FROM sps_events WHERE user_id = ? ORDER BY uid');
        $stmt->execute([$id]);

        return array_map(static fn (array $r): array => [
            'uid' => (string) $r['uid'],
            'sequence' => (int) $r['sequence'],
            'content_hash' => (string) $r['content_hash'],
            'last_modified' => (string) $r['last_modified'],
            'payload' => (string) $r['payload'],
        ], $stmt->fetchAll());
    }

    public function replaceEvents(int $id, array $events): void
    {
        $this->transaction(function () use ($id, $events): void {
            $this->pdo->prepare('DELETE FROM sps_events WHERE user_id = ?')->execute([$id]);
            $insert = $this->pdo->prepare(
                'INSERT INTO sps_events (user_id, uid, sequence, content_hash, last_modified, payload) VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($events as $e) {
                $insert->execute([$id, $e['uid'], $e['sequence'], $e['content_hash'], $e['last_modified'], $e['payload']]);
            }
        });
    }

    public function touchFeedAccess(int $id, DateTimeImmutable $now): void
    {
        $day = $now->format('Y-m-d');
        $this->pdo->prepare('UPDATE sps_users SET feed_last_access_on = ? WHERE id = ? AND (feed_last_access_on IS NULL OR feed_last_access_on <> ?)')
            ->execute([$day, $id, $day]);
    }

    public function deleteInactiveUsers(DateTimeImmutable $cutoff): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM sps_users WHERE feed_last_access_on < ?');
        $stmt->execute([$cutoff->format('Y-m-d')]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        foreach ($ids as $id) {
            $this->deleteUser($id);
        }

        return count($ids);
    }

    public function hitRateLimit(string $bucketKey, int $windowSeconds, DateTimeImmutable $now): int
    {
        $window = intdiv($now->getTimestamp(), $windowSeconds) * $windowSeconds;
        $key = hash('sha256', $bucketKey . '|' . $windowSeconds);
        $this->pdo->prepare(
            'INSERT INTO sps_rate_limits (bucket_key, window_start, hits) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE hits = IF(window_start = VALUES(window_start), hits + 1, 1), window_start = VALUES(window_start)'
        )->execute([$key, $window]);
        $stmt = $this->pdo->prepare('SELECT hits FROM sps_rate_limits WHERE bucket_key = ?');
        $stmt->execute([$key]);

        return (int) $stmt->fetchColumn();
    }

    public function purgeRateLimits(DateTimeImmutable $olderThan): void
    {
        $this->pdo->prepare('DELETE FROM sps_rate_limits WHERE window_start < ?')->execute([$olderThan->getTimestamp()]);
    }

    /** @param list<mixed> $params */
    private function fetchUser(string $where, array $params): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::USER_COLUMNS . " FROM sps_users WHERE $where LIMIT 1");
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['failure_count'] = (int) $row['failure_count'];
        $row['empty_streak'] = (int) $row['empty_streak'];

        return $row;
    }

    private function transaction(callable $fn): void
    {
        $this->pdo->beginTransaction();
        try {
            $fn();
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private static function lockName(int $id): string
    {
        return 'sps_user_' . $id;
    }

    private static function ts(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
