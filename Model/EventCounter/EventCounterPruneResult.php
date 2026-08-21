<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\EventCounter;

/**
 * Outcome of one EventCounterRepository::prune() run, so a cron job or CLI
 * command can surface what happened without querying the tables itself.
 */
class EventCounterPruneResult
{
    /**
     * @param int $counterRowsPruned watchtower_event_counter rows deleted for exceeding the retention window
     * @param int $dropCounterRowsPruned watchtower_event_drop_counter rows deleted for exceeding the retention window
     */
    public function __construct(
        public readonly int $counterRowsPruned,
        public readonly int $dropCounterRowsPruned,
    ) {
    }
}
