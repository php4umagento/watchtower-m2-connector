<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Rollup;

/**
 * One row returned by RollupRepository::dailyCountsInWindow(): the count
 * observed on a single historical day, plus the UTC calendar date it
 * counts. Mirrors HourlyCountSample's own shape at daily granularity, for
 * the seasonal-index calculation's whole-day totals.
 */
class DailyCountSample
{
    /**
     * @param \DateTimeImmutable $date UTC calendar date this sample counts (midnight, no time component)
     * @param int $count event count observed on that day
     */
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly int $count,
    ) {
    }
}
