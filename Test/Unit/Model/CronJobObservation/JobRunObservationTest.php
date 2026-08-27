<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\CronJobObservation;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\CronJobObservation\JobRunObservation;

/**
 * Covers the percentile maths the cadence estimate rests on, in particular
 * that a single outlier gap cannot drag the central estimate with it.
 */
class JobRunObservationTest extends TestCase
{
    public function testMedianIgnoresASingleOutlierGap(): void
    {
        // Four normal five-minute gaps and one 90-minute stall.
        $observation = $this->observationWithGaps([300, 300, 300, 300, 5400]);

        self::assertSame(300, $observation->medianGapSeconds());
    }

    public function testMedianOfAnEvenSampleTakesTheNearestRankNotAnInterpolation(): void
    {
        $observation = $this->observationWithGaps([100, 200, 300, 400]);

        // Nearest-rank p50 of four samples is the 2nd, not the 250 an
        // interpolated median would invent.
        self::assertSame(200, $observation->medianGapSeconds());
    }

    public function testPercentileTracksTheTail(): void
    {
        $observation = $this->observationWithGaps([300, 300, 300, 300, 5400]);

        self::assertSame(5400, $observation->percentileGapSeconds(95));
        self::assertSame(5400, $observation->percentileGapSeconds(90));
    }

    public function testPercentileIsUnaffectedByTheOrderSamplesWereRecordedIn(): void
    {
        $ascending = $this->observationWithGaps([100, 200, 300, 400, 500]);
        $shuffled = $this->observationWithGaps([400, 100, 500, 200, 300]);

        self::assertSame($ascending->medianGapSeconds(), $shuffled->medianGapSeconds());
        self::assertSame($ascending->percentileGapSeconds(95), $shuffled->percentileGapSeconds(95));
    }

    public function testNoSamplesYieldsNullRatherThanZero(): void
    {
        $observation = $this->observationWithGaps([]);

        self::assertNull($observation->medianGapSeconds());
        self::assertNull($observation->percentileGapSeconds(95));
    }

    /**
     * Builds an observation carrying just the gaps under test.
     *
     * @param int[] $gaps
     * @return JobRunObservation
     */
    private function observationWithGaps(array $gaps): JobRunObservation
    {
        return new JobRunObservation(
            jobCode: 'ebizmarts_ecommerce',
            firstObservedAt: new \DateTimeImmutable('2026-08-13T10:00:00+00:00'),
            lastSuccessAt: new \DateTimeImmutable('2026-08-13T15:00:00+00:00'),
            observedRunCount: count($gaps) + 1,
            gapSamples: $gaps,
        );
    }
}
