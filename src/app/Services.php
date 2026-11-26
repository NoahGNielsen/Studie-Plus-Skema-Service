<?php

declare(strict_types=1);

namespace SkemaService;

use SkemaService\Feed\FeedService;
use SkemaService\Ical\IcalWriter;
use SkemaService\Store\MysqlStore;
use SkemaService\StudiePlus\StudiePlusScheduleSource;
use SkemaService\Sync\SyncService;

/** Samler afhængighederne ud fra konfigurationen. */
final class Services
{
    public readonly Logger $logger;
    public readonly MysqlStore $store;
    public readonly Crypto $crypto;
    public readonly StudiePlusScheduleSource $source;
    public readonly SyncService $sync;
    public readonly FeedService $feed;

    public function __construct(public readonly Config $config)
    {
        $logFile = $config->get('log_file');
        $this->logger = new Logger(
            is_string($logFile) && $logFile !== '' ? $logFile : null,
            (string) $config->get('log_level', 'debug'),
        );
        $this->logger->capturePhpErrors();
        $this->store = MysqlStore::connect((array) $config->get('db'));
        $this->crypto = new Crypto($config->masterKey());
        $this->source = StudiePlusScheduleSource::fromSettings((array) $config->get('studieplus', []), $this->logger);
        $this->sync = new SyncService($this->store, $this->source, $this->crypto, null, $this->logger);
        $this->feed = new FeedService($this->store, $this->crypto, new IcalWriter(), $this->logger);
    }

    public function __debugInfo(): array
    {
        return ['services' => '[skjult]'];
    }
}
