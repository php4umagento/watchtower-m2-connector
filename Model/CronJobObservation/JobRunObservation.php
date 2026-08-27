<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\CronJobObservation;

/**
 * One cron job code's observed run history: how often it has actually been
 * seen to succeed, and the gaps between those successes.
 *
 * Successes only, deliberately. The threshold this feeds answers "how long
 * may a success go unrenewed before something is wrong", which is exactly
 * the question IntegrationHealth\Evaluator::rawStatus() asks. Counting
 * errored runs as evidence of cadence would let a job that runs and fails
 * every time look perfectly healthy on period alone.
 */
class JobRunObservation
{
    /**
     * @param string $jobCode
     * @param \DateTimeImmutable $firstObservedAt
     * @param \DateTimeImmutable|null $lastSuccessAt cursor later runs are measured from
     * @param int $observedRunCount
     * @param int[] $gapSamples inter-run gaps in seconds, oldest first
     */
    public function __construct(
        public readonly string $jobCode,
        public readonly \DateTimeImmutable $firstObservedAt,
        public readonly ?\DateTimeImmutable $lastSuccessAt,
        public readonly int $observedRunCount,
        public readonly array $gapSamples,
    ) {
    }

    /**
     * The median gap between observed runs, or null when nothing has been measured yet.
     *
     * Median rather than mean: one missed run on a busy host produces a
     * single gap several times the normal one, and a mean would let that
     * outlier drag the whole estimate up.
     *
     * @return int|null
     */
    public function medianGapSeconds(): ?int
    {
        return $this->percentileGapSeconds(50);
    }

    /**
     * The gap at a given percentile, or null when nothing has been measured yet.
     *
     * Nearest-rank, not interpolated: the samples are already a small
     * integer set and an interpolated value would imply a precision the
     * measurement does not have.
     *
     * @param int $percentile
     * @return int|null
     */
    public function percentileGapSeconds(int $percentile): ?int
    {
        if ($this->gapSamples === []) {
            return null;
        }

        $sorted = $this->gapSamples;
        sort($sorted);

        $rank = (int) ceil(($percentile / 100) * count($sorted));
        $index = max(0, min(count($sorted) - 1, $rank - 1));

        return $sorted[$index];
    }
}
