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
 * Observed rather than declared, deliberately. crontab.xml states what a job
 * is *supposed* to do; this measures what it actually does on this host. On a
 * congested install a job declared "* / 5" may really run every 18 minutes,
 * and a threshold derived from the declared expression would alert
 * constantly. Measuring instead self-calibrates per store, and removes the
 * expected-max-interval field the merchant used to have to guess at.
 */
class CadenceEstimator
{
    /**
     * Gaps needed before any number here is trusted. Below this the job is
     * reported as still learning and the signal stays INSUFFICIENT_DATA
     * rather than alerting on an estimate drawn from one or two runs.
     */
    public const MIN_GAP_SAMPLES = 5;

    /**
     * A job is "regular" when its p90 gap is within this multiple of its
     * median. Wider than that and a single threshold cannot separate normal
     * spread from a real stall, so the picker warns and the merchant decides.
     */
    private const REGULARITY_RATIO = 2.0;

    /**
     * The threshold takes whichever of these is larger, so a job with a tight
     * distribution still gets headroom for one skipped run (the median term)
     * and a job with a wide one is judged against its own tail (the p95 term)
     * rather than against a median it routinely exceeds.
     */
    private const THRESHOLD_P95_MULTIPLIER = 1.5;
    private const THRESHOLD_MEDIAN_MULTIPLIER = 2.0;

    /**
     * The platform evaluates hourly and only reports a transition after it
     * holds for two consecutive evaluations, so end-to-end alert latency is
     * one to two hours no matter what this returns. A threshold tighter than
     * an hour therefore buys no detection speed at all and only converts
     * ordinary cron jitter into false alarms.
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

        // Guarded for static analysis only: a non-empty sample set always
        // yields all three, and MIN_GAP_SAMPLES above already proved it is
        // non-empty.
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
