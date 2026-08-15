<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\RateSignal;

/**
 * InterArrivalGapCalculator::compute()'s return value: the current gap plus
 * the two historical gap-distributions DispersionEvaluator needs to pick a
 * percentile threshold from. It widens to the store-wide distribution only
 * when a bucket doesn't have enough observations to calibrate on its own.
 */
class InterArrivalGapResult
{
    /**
     * @param int $currentGapHours whole hours since the most recent nonzero hour strictly before the evaluated hour
     * @param int[] $bucketGapDistribution historical gaps at prior occurrences of the evaluated hour's own
     *              (hour-of-day, day-of-week) bucket
     * @param int[] $storeWideGapDistribution historical gaps observed at every hour in the window, unconditioned
     *              by bucket
     */
    public function __construct(
        public readonly int $currentGapHours,
        public readonly array $bucketGapDistribution,
        public readonly array $storeWideGapDistribution,
    ) {
    }
}
