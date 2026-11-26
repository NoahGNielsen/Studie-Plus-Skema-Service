<?php

declare(strict_types=1);

/*
 * Planlagt opdatering. Køres af crontab hvert 15. minut:
 *   *\/15 * * * * /usr/local/bin/php <webroot>/app/bin/cron.php
 *
 * Scriptet afgør selv pr. bruger, om en hentning er "forfalden"
 * (15 min. på hverdage 06:30–16:00, ellers 60 min. — se SyncPolicy).
 * Det må kun køres fra kommandolinjen.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use SkemaService\Config;
use SkemaService\Logger;
use SkemaService\Services;
use SkemaService\Sync\SyncPolicy;
use SkemaService\Sync\SyncService;

const MAX_RUNTIME_SECONDS = 12 * 60;   // under 15 min, så kørsler ikke overlapper
const PAUSE_BETWEEN_USERS_MS = 750;    // skån Studie+
const INACTIVE_DAYS = 90;

$started = time();

try {
    $services = new Services(Config::load());
} catch (Throwable $e) {
    (new Logger())->exception('cron_bootstrap_failed', $e);
    exit(1);
}

$store = $services->store;
$logger = $services->logger;

if (!$store->acquireNamedLock('cron')) {
    $logger->info('cron_already_running');
    exit(0);
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$policy = new SyncPolicy();
$counts = [];

try {
    // Vedligehold
    $store->purgeRateLimits($now->modify('-1 day'));
    $deleted = $store->deleteInactiveUsers($now->modify('-' . INACTIVE_DAYS . ' days'));
    if ($deleted > 0) {
        $logger->info('inactive_users_deleted', ['count' => $deleted]);
    }

    if (!$services->source->isEnabled()) {
        $logger->info('cron_skipped_source_disabled');
        exit(0);
    }

    foreach ($store->syncCandidates() as $candidate) {
        if (time() - $started > MAX_RUNTIME_SECONDS) {
            $logger->warning('cron_time_budget_exhausted');
            break;
        }
        $last = $candidate['last_attempt_at'] !== null
            ? new DateTimeImmutable($candidate['last_attempt_at'], new DateTimeZone('UTC'))
            : null;
        $current = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (!$policy->isDue($last, $current, $candidate['failure_count'])) {
            continue;
        }
        $result = $services->sync->syncUser($candidate['id']);
        $counts[$result] = ($counts[$result] ?? 0) + 1;
        usleep(PAUSE_BETWEEN_USERS_MS * 1000);
    }
} catch (Throwable $e) {
    $logger->exception('cron_failed', $e);
    exit(1);
}

$logger->info('cron_done', [
    'ok' => $counts[SyncService::OK] ?? 0,
    'failed' => $counts[SyncService::FAILED] ?? 0,
    'auth_failed' => $counts[SyncService::AUTH_FAILED] ?? 0,
    'kept_previous' => $counts[SyncService::KEPT_PREVIOUS] ?? 0,
    'locked' => $counts[SyncService::LOCKED] ?? 0,
    'seconds' => time() - $started,
]);
