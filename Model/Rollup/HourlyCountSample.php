<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\Rollup;

/**
 * One row returned by RollupRepository::hourlyCountsForBucket(): the count
 * observed in a single historical occurrence of the requested (hour-of-day,
 * day-of-week) bucket, plus the UTC instant it counts, so a caller can chart
 * or window the series rather than only average it.
 */
class HourlyCountSample
{
    /**
     * @param \DateTimeImmutable $bucket UTC top-of-hour instant this sample counts
     * @param int $count event count observed in that hour
     */
    public function __construct(
        public readonly \DateTimeImmutable $bucket,
        public readonly int $count,
    ) {
    }
}
