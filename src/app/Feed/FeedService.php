<?php

declare(strict_types=1);

namespace SkemaService\Feed;

use DateTimeImmutable;
use DateTimeZone;
use SkemaService\Crypto;
use SkemaService\Ical\FeedEvent;
use SkemaService\Ical\IcalWriter;
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
    ) {
    }

    public function render(string $token, ?DateTimeImmutable $now = null): ?string
    {
        if (!Crypto::isWellFormedToken($token)) {
            return null;
        }
        $user = $this->store->findUserByTokenHash($this->crypto->tokenHash($token));
        if ($user === null) {
            return null;
        }
        $userId = (int) $user['id'];
        $this->store->touchFeedAccess($userId, $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));

        $events = [];
        foreach ($this->store->eventsForUser($userId) as $row) {
            $data = json_decode($row['payload'], true);
            if (!is_array($data)) {
                continue;
            }
            $events[] = new FeedEvent(
                $row['uid'],
                (int) $row['sequence'],
                new DateTimeImmutable($row['last_modified'], new DateTimeZone('UTC')),
                Lesson::fromArray($data),
            );
        }

        return $this->writer->render($events);
    }
}
