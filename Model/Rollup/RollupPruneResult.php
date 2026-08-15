<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Rollup;

/**
 * Outcome of one RollupRepository::rollupAndPrune() run, so a cron job or
 * CLI command can surface what happened without querying the tables itself.
 */
class RollupPruneResult
{
    /**
     * @param int $rolledDayGroups number of distinct (store view, category, day) groups rolled into the daily table
     * @param int $hourlyRowsPruned hourly rows deleted after being rolled up
     * @param int $dailyRowsPruned daily rows deleted for exceeding the daily retention window
     */
    public function __construct(
        public readonly int $rolledDayGroups,
        public readonly int $hourlyRowsPruned,
        public readonly int $dailyRowsPruned,
    ) {
    }
}
