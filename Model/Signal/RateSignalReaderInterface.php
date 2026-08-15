<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Signal;

/**
 * Common contract for a table-sourced rate signal reader: an aggregate-only
 * COUNT() of one store view's rows created within an hourly window, backing
 * basket_quote, checkout, and customer_account's registrations sub-counter.
 *
 * Callers invoke countForWindow() per evaluation tick and write the result
 * into RollupRepository::recordHourlyCount() -- these readers know nothing
 * about rollups, cron, or the evaluator.
 */
interface RateSignalReaderInterface
{
    /**
     * Counts rows attributable to one store view within [$windowStart, $windowEnd).
     *
     * Start-inclusive, end-exclusive, matching how an hourly bucket
     * [hour_start, hour_start + 1hr) naturally divides a timeline with no
     * overlap and no gap between consecutive windows.
     *
     * @param int $storeViewId
     * @param \DateTimeImmutable $windowStart inclusive
     * @param \DateTimeImmutable $windowEnd exclusive
     * @return int
     */
    public function countForWindow(
        int $storeViewId,
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd
    ): int;
}
