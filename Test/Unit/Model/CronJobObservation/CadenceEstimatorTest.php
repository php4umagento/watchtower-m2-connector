<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\CronJobObservation;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\CronJobObservation\Cadence;
use Watchtower\Connector\Model\CronJobObservation\CadenceEstimator;
use Watchtower\Connector\Model\CronJobObservation\JobRunObservation;

/**
 * Covers the three states the picker and the evaluator branch on: still
 * learning, confident but irregular, and confident and regular.
 */
class CadenceEstimatorTest extends TestCase
{
    private const HOUR = 3600;

    public function testAJobNeverSeenIsStillLearning(): void
    {
        $cadence = (new CadenceEstimator())->estimate(null);

        self::assertFalse($cadence->isConfident);
        self::assertNull($cadence->periodSeconds);
        self::assertNull($cadence->thresholdSeconds);
    }

    public function testTooFewSamplesIsStillLearning(): void
    {
        $cadence = $this->estimateFor(array_fill(0, CadenceEstimator::MIN_GAP_SAMPLES - 1, 300));

        self::assertFalse($cadence->isConfident);
        self::assertNull($cadence->thresholdSeconds);
        self::assertSame(CadenceEstimator::MIN_GAP_SAMPLES - 1, $cadence->sampleCount);
    }

    public function testEnoughTightSamplesIsConfidentAndRegular(): void
    {
        $cadence = $this->estimateFor([300, 300, 305, 295, 300, 300]);

        self::assertTrue($cadence->isConfident);
        self::assertTrue($cadence->isRegular);
        self::assertSame(300, $cadence->periodSeconds);
    }

    public function testAWideSpreadIsConfidentButNotRegular(): void
    {
        // A job that sometimes runs on time and sometimes hours late: a
        // period exists, but no single threshold separates normal from stalled.
        $cadence = $this->estimateFor([300, 300, 300, 300, 7200, 9000]);

        self::assertTrue($cadence->isConfident);
        self::assertFalse($cadence->isRegular);
    }

    /**
     * The floor is the point of this test. The platform evaluates hourly and
     * only reports a transition after two consecutive evaluations, so a
     * threshold below an hour cannot make an alert arrive sooner. It would
     * only turn ordinary cron jitter into false alarms.
     */
    public function testAFastJobsThresholdIsFlooredAtAnHour(): void
    {
        $cadence = $this->estimateFor(array_fill(0, 10, 60));

        self::assertSame(60, $cadence->periodSeconds);
        self::assertSame(self::HOUR, $cadence->thresholdSeconds);
    }

    /**
     * The case the whole redesign exists for. A nightly ERP sync has a
     * roughly 24 hour cadence, so its threshold must be comfortably beyond a
     * day. Under the retired hand-typed 60 minute default this same job sat
     * in SevereDrop for 23 hours out of every 24 while perfectly healthy.
     */
    public function testANightlyJobGetsAThresholdBeyondADay(): void
    {
        $day = 24 * self::HOUR;
        $cadence = $this->estimateFor(array_fill(0, 8, $day));

        self::assertSame($day, $cadence->periodSeconds);
        self::assertGreaterThan($day, $cadence->thresholdSeconds);
    }

    public function testTheThresholdClearsTheObservedTailNotJustTheMedian(): void
    {
        // p95 is 1800 here, so a median-only threshold (2 x 300 = 600) would
        // fire on a gap the job routinely reaches.
        $cadence = $this->estimateFor([300, 300, 300, 300, 300, 1800]);

        self::assertGreaterThanOrEqual(1800, $cadence->thresholdSeconds);
    }

    /**
     * Runs the estimator over an observation carrying the given gaps.
     *
     * @param int[] $gaps
     * @return Cadence
     */
    private function estimateFor(array $gaps): Cadence
    {
        return (new CadenceEstimator())->estimate(new JobRunObservation(
            jobCode: 'ess_m2epro',
            firstObservedAt: new \DateTimeImmutable('2026-08-01T00:00:00+00:00'),
            lastSuccessAt: new \DateTimeImmutable('2026-08-13T15:00:00+00:00'),
            observedRunCount: count($gaps) + 1,
            gapSamples: $gaps,
        ));
    }
}
