<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Cron;

use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\EventCounter\EventCounterRepository;

/**
 * Scheduled counterpart to bin/magento watchtower:event-counter-prune. Keeps
 * watchtower_event_counter and watchtower_event_drop_counter within their
 * declared retention window -- unlike the rollup tables, nothing else in the
 * module ever prunes these, so without this job both grow unbounded for the
 * lifetime of the install.
 */
class EventCounterPruneJob
{
    /**
     * @param EventCounterRepository $eventCounterRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly EventCounterRepository $eventCounterRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Prunes both event counter tables, logging the outcome for operator visibility.
     *
     * @return void
     */
    public function execute(): void
    {
        $result = $this->eventCounterRepository->prune(new \DateTimeImmutable());

        if ($result->counterRowsPruned === 0 && $result->dropCounterRowsPruned === 0) {
            // Nothing to do this run is the ordinary case; not worth a log line every day.
            return;
        }

        $this->logger->info('Watchtower pruned local event counters.', [
            'counterRowsPruned' => $result->counterRowsPruned,
            'dropCounterRowsPruned' => $result->dropCounterRowsPruned,
        ]);
    }
}
