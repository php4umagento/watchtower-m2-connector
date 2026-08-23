<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Cron;

use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\AdminAuthFailure\InstallEventCounterRepository;
use Watchtower\Connector\Model\EventCounter\EventCounterRepository;

/**
 * Scheduled counterpart to bin/magento watchtower:event-counter-prune. Keeps
 * every raw event-counter table within its declared retention window --
 * unlike the rollup tables, nothing else in the module ever prunes these, so
 * without this job all of them grow unbounded for the lifetime of the
 * install. Covers watchtower_event_counter, watchtower_event_drop_counter
 * (both via EventCounterRepository) and watchtower_install_event_counter
 * (InstallEventCounterRepository) -- one job for "raw counters", even though
 * the tables belong to two separate repositories with two separate scoping
 * concerns, since a merchant does not care how many small tables this module
 * happens to keep tidy.
 */
class EventCounterPruneJob
{
    /**
     * @param EventCounterRepository $eventCounterRepository
     * @param InstallEventCounterRepository $installEventCounterRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly EventCounterRepository $eventCounterRepository,
        private readonly InstallEventCounterRepository $installEventCounterRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Prunes every raw event counter table, logging the outcome for operator visibility.
     *
     * @return void
     */
    public function execute(): void
    {
        $now = new \DateTimeImmutable();
        $result = $this->eventCounterRepository->prune($now);
        $installRowsPruned = $this->installEventCounterRepository->prune($now);

        if ($result->counterRowsPruned === 0 && $result->dropCounterRowsPruned === 0 && $installRowsPruned === 0) {
            // Nothing to do this run is the ordinary case; not worth a log line every day.
            return;
        }

        $this->logger->info('Watchtower pruned local event counters.', [
            'counterRowsPruned' => $result->counterRowsPruned,
            'dropCounterRowsPruned' => $result->dropCounterRowsPruned,
            'installCounterRowsPruned' => $installRowsPruned,
        ]);
    }
}
