<?php

declare(strict_types=1);

namespace SkemaService\Feed;

use DateTimeImmutable;
use DateTimeZone;
use SkemaService\Crypto;
use SkemaService\Ical\FeedEvent;
use SkemaService\Ical\IcalWriter;
use SkemaService\Logger;
use SkemaService\Schedule\Lesson;
use SkemaService\Store\Store;

/**
 * Slår et feed-token op (via hash) og returnerer KUN den tilhørende
 * brugers kalender. Ugyldige, roterede og slettede tokens giver null
 * — uden at afsløre hvilket af tilfældene der er tale om.
 */
final class FeedService
{
    public function __construct(
        private readonly Store $store,
        private readonly Crypto $crypto,
        private readonly IcalWriter $writer = new IcalWriter(),
        private readonly ?Logger $logger = null,
    ) {
    }

    public function render(string $token, ?DateTimeImmutable $now = null): ?string
    {
        $started = microtime(true);
        if (!Crypto::isWellFormedToken($token)) {
            $this->logger?->info('feed_token_malformed');

            return null;
        }
        $user = $this->store->findUserByTokenHash($this->crypto->tokenHash($token));
        if ($user === null) {
            $this->logger?->info('feed_token_unknown');

            return null;
        }
        $userId = (int) $user['id'];
        $this->store->touchFeedAccess($userId, $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));

        $events = [];
        $broken = 0;
        foreach ($this->store->eventsForUser($userId) as $row) {
            $data = json_decode($row['payload'], true);
            if (!is_array($data)) {
                $broken++;
                continue;
            }
            $events[] = new FeedEvent(
                $row['uid'],
                (int) $row['sequence'],
                new DateTimeImmutable($row['last_modified'], new DateTimeZone('UTC')),
                Lesson::fromArray($data),
            );
        }
        if ($broken > 0) {
            $this->logger?->warning('feed_broken_payloads', ['user' => $userId, 'count' => $broken]);
        }

        $body = $this->writer->render($events);
        $this->logger?->info('feed_rendered', [
            'user' => $userId,
            'status' => (string) ($user['status'] ?? ''),
            'events' => count($events),
            'bytes' => strlen($body),
            'last_success' => $user['last_success_at'] ?? null,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $body;
    }
}
