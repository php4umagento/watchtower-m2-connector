<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\CronJobObservation;

/**
 * Turns a job's observed run history into the window integration_health
 * judges it against.
 *
 * Measured rather than declared: crontab.xml says what a job should do, and on
 * a congested host a five minute job may really run every eighteen.
 */
class CadenceEstimator
{
    /** Below this the job reports as still learning, rather than alerting on a one-or-two-run estimate. */
    public const MIN_GAP_SAMPLES = 5;

    /** Wider spread than this and no single threshold separates normal from stalled, so the picker warns. */
    private const REGULARITY_RATIO = 2.0;

    /**
     * Larger of the two wins: the median term gives a tight job headroom for
     * one skipped run, the p95 term keeps a wide one off its own tail.
     */
    private const THRESHOLD_P95_MULTIPLIER = 1.5;
    private const THRESHOLD_MEDIAN_MULTIPLIER = 2.0;

    /**
     * The platform evaluates hourly with a two-evaluation debounce, so
     * anything tighter buys no detection speed and only adds false alarms.
     */
    private const MIN_THRESHOLD_SECONDS = 3600;

    /**
     * 30 days, carried over from the bound the retired
     * IntegrationHealthConfigValidator::MAX_EXPECTED_MAX_INTERVAL_MINUTES
     * enforced on the hand-typed field this replaces.
     */
    private const MAX_THRESHOLD_SECONDS = 2592000;

    /**
     * Estimates one job's cadence from its recorded gaps.
     *
     * @param JobRunObservation|null $observation null when the job has never been seen to run
     * @return Cadence
     */
    public function estimate(?JobRunObservation $observation): Cadence
    {
        if ($observation === null) {
            return $this->learning(0, 0);
        }

        $sampleCount = count($observation->gapSamples);

        if ($sampleCount < self::MIN_GAP_SAMPLES) {
            return $this->learning($sampleCount, $observation->observedRunCount);
        }

        $median = $observation->medianGapSeconds();
        $p90 = $observation->percentileGapSeconds(90);
        $p95 = $observation->percentileGapSeconds(95);

        // Static analysis only: MIN_GAP_SAMPLES above proved the set non-empty.
        if ($median === null || $p90 === null || $p95 === null) {
            return $this->learning($sampleCount, $observation->observedRunCount);
        }

        return new Cadence(
            periodSeconds: $median,
            thresholdSeconds: $this->threshold($median, $p95),
            isConfident: true,
            isRegular: $p90 <= $median * self::REGULARITY_RATIO,
            sampleCount: $sampleCount,
            observedRunCount: $observation->observedRunCount,
        );
    }

    /**
     * A cadence for a job too little has been measured for to judge it yet.
     *
     * @param int $sampleCount
     * @param int $observedRunCount
     * @return Cadence
     */
    private function learning(int $sampleCount, int $observedRunCount): Cadence
    {
        return new Cadence(
            periodSeconds: null,
            thresholdSeconds: null,
            isConfident: false,
            isRegular: false,
            sampleCount: $sampleCount,
            observedRunCount: $observedRunCount,
        );
    }

    /**
     * How long this job's last success may go unrenewed before it is treated as stalled.
     *
     * @param int $median
     * @param int $p95
     * @return int
     */
    private function threshold(int $median, int $p95): int
    {
        $threshold = (int) ceil(max(
            $p95 * self::THRESHOLD_P95_MULTIPLIER,
            $median * self::THRESHOLD_MEDIAN_MULTIPLIER
        ));

        return max(self::MIN_THRESHOLD_SECONDS, min(self::MAX_THRESHOLD_SECONDS, $threshold));
    }
}
