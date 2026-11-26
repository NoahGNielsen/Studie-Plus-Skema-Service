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

$logger->info('cron_start', ['pid' => getmypid(), 'php' => PHP_VERSION, 'source_enabled' => $services->source->isEnabled()]);

if (!$store->acquireNamedLock('cron')) {
    $logger->info('cron_already_running');
    exit(0);
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$policy = new SyncPolicy();
$counts = [];
$notDue = 0;
$candidates = [];

try {
    // Vedligehold
    $store->purgeRateLimits($now->modify('-1 day'));
    $logger->debug('rate_limits_purged');
    $deleted = $store->deleteInactiveUsers($now->modify('-' . INACTIVE_DAYS . ' days'));
    $logger->info('inactive_users_deleted', ['count' => $deleted, 'inactive_days' => INACTIVE_DAYS]);

    if (!$services->source->isEnabled()) {
        $logger->info('cron_skipped_source_disabled');
        exit(0);
    }

    $candidates = $store->syncCandidates();
    $logger->info('cron_candidates', ['count' => count($candidates)]);

    foreach ($candidates as $candidate) {
        $elapsed = time() - $started;
        if ($elapsed > MAX_RUNTIME_SECONDS) {
            $logger->warning('cron_time_budget_exhausted', ['seconds' => $elapsed, 'next_user' => $candidate['id']]);
            break;
        }
        $last = $candidate['last_attempt_at'] !== null
            ? new DateTimeImmutable($candidate['last_attempt_at'], new DateTimeZone('UTC'))
            : null;
        $current = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (!$policy->isDue($last, $current, $candidate['failure_count'])) {
            $notDue++;
            $logger->debug('sync_not_due', [
                'user' => $candidate['id'],
                'last_attempt' => $candidate['last_attempt_at'],
                'failures' => $candidate['failure_count'],
            ]);
            continue;
        }
        $userStarted = microtime(true);
        $result = $services->sync->syncUser($candidate['id']);
        $counts[$result] = ($counts[$result] ?? 0) + 1;
        $logger->info('cron_user_result', [
            'user' => $candidate['id'],
            'result' => $result,
            'failures_before' => $candidate['failure_count'],
            'ms' => (int) round((microtime(true) - $userStarted) * 1000),
        ]);
        usleep(PAUSE_BETWEEN_USERS_MS * 1000);
    }
} catch (Throwable $e) {
    $logger->exception('cron_failed', $e, ['seconds' => time() - $started]);
    exit(1);
}

$logger->info('cron_done', [
    'candidates' => count($candidates),
    'not_due' => $notDue,
    'ok' => $counts[SyncService::OK] ?? 0,
    'failed' => $counts[SyncService::FAILED] ?? 0,
    'auth_failed' => $counts[SyncService::AUTH_FAILED] ?? 0,
    'kept_previous' => $counts[SyncService::KEPT_PREVIOUS] ?? 0,
    'locked' => $counts[SyncService::LOCKED] ?? 0,
    'skipped' => $counts[SyncService::SKIPPED] ?? 0,
    'seconds' => time() - $started,
    'memory_peak_kb' => intdiv(memory_get_peak_usage(true), 1024),
]);
