<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Cron;

use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Rollup\RollupRepository;

/**
 * Scheduled counterpart to bin/magento watchtower:rollup-prune. Keeps the
 * local historical retention store within its declared windows by rolling
 * aged hourly rows into the daily table and pruning both tables back down
 * to their retention horizons.
 */
class RollupPruneJob
{
    /**
     * @param RollupRepository $rollupRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RollupRepository $rollupRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Rolls up and prunes the historical rollup tables, logging the outcome for operator visibility.
     *
     * @return void
     */
    public function execute(): void
    {
        $result = $this->rollupRepository->rollupAndPrune(new \DateTimeImmutable());

        if ($result->rolledDayGroups === 0 && $result->dailyRowsPruned === 0) {
            // Nothing to do this run is the ordinary case; not worth a log line every day.
            return;
        }

        $this->logger->info('Watchtower rolled up and pruned local historical counters.', [
            'rolledDayGroups' => $result->rolledDayGroups,
            'hourlyRowsPruned' => $result->hourlyRowsPruned,
            'dailyRowsPruned' => $result->dailyRowsPruned,
        ]);
    }
}
