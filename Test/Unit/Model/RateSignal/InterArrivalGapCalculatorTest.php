<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\RateSignal;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\RateSignal\InterArrivalGapCalculator;
use Watchtower\Connector\Model\Rollup\HourlyCountSample;

/**
 * Pure-computation coverage for low-volume signal mode's inter-arrival
 * gap calculation, isolated from
 * DispersionEvaluator's own mode-switch/threshold/debounce concerns --
 * see DispersionEvaluatorTest.php's own "below the volume floor" tests for
 * those.
 */
class InterArrivalGapCalculatorTest extends TestCase
{
    private const EVALUATED_HOUR_STRING = '2026-08-13T15:00:00+00:00'; // Thursday, hour 15.

    /**
     * The calculator deliberately never looks at the evaluated hour's own
     * count (see its own docblock on self-pollution) -- so even with
     * activity every single hour, the smallest possible currentGapHours is 1
     * (the bucket-distance to the immediately preceding nonzero hour), never
     * 0. DispersionEvaluator itself is what short-circuits observedCount > 0
     * straight to Normal without consulting this calculator at all; see
     * DispersionEvaluatorTest's own coverage of that.
     */
    public function testConsecutiveHourlyActivityGivesTheSmallestPossibleGapOfOneNeverZero(): void
    {
        $series = $this->hourlySeries(hoursBack: 10, countPerHour: 3);

        $result = (new InterArrivalGapCalculator())->compute($series, $this->evaluatedHour());

        self::assertSame(1, $result->currentGapHours);
    }

    public function testTheCurrentGapCountsHoursSinceTheMostRecentNonzeroHourStrictlyBeforeTheEvaluatedHour(): void
    {
        $series = [
            $this->sample('2026-08-13T10:00:00+00:00', 4),
            $this->sample('2026-08-13T11:00:00+00:00', 0),
            $this->sample('2026-08-13T12:00:00+00:00', 0),
            $this->sample('2026-08-13T13:00:00+00:00', 0),
            $this->sample('2026-08-13T14:00:00+00:00', 0),
            $this->sample('2026-08-13T15:00:00+00:00', 0), // the evaluated hour itself
        ];

        $result = (new InterArrivalGapCalculator())->compute($series, $this->evaluatedHour());

        // Last nonzero hour was 10:00; evaluated hour is 15:00 -- a 5 hour gap.
        self::assertSame(5, $result->currentGapHours);
    }

    public function testTheEvaluatedHoursOwnCountNeverInfluencesItsOwnCurrentGap(): void
    {
        // The evaluated hour's own row has a nonzero count, but the gap must
        // still be measured from the last PRIOR nonzero hour -- a row is
        // never allowed to poll its own classification, mirroring
        // DispersionEvaluator::historicalSamples()'s own exclusion for
        // Check A.
        $series = [
            $this->sample('2026-08-13T10:00:00+00:00', 4),
            $this->sample('2026-08-13T11:00:00+00:00', 0),
            $this->sample('2026-08-13T12:00:00+00:00', 0),
            $this->sample('2026-08-13T15:00:00+00:00', 2), // the evaluated hour itself
        ];

        $result = (new InterArrivalGapCalculator())->compute($series, $this->evaluatedHour());

        self::assertSame(5, $result->currentGapHours);
    }

    public function testAHistoricalGapOnASpecificWeekdayHourOnlyAppearsInThatBucketsOwnDistributionNotAnothers(): void
    {
        // Three prior Thursday-15:00 occurrences, each preceded by a real
        // gap of a different size, plus unrelated Wednesday/Friday activity
        // that must never leak into the Thursday-15:00 bucket distribution.
        $series = [
            // Week 3 ago: Wednesday 10:00 nonzero, then quiet until Thursday 15:00 (29 hour gap).
            $this->sample('2026-07-22T10:00:00+00:00', 5),
            $this->sample('2026-07-23T15:00:00+00:00', 0), // Thursday 15:00, 3 weeks ago.
            // Week 2 ago: nonzero at Thursday 13:00, quiet until Thursday 15:00 (2 hour gap).
            $this->sample('2026-07-30T13:00:00+00:00', 5),
            $this->sample('2026-07-30T15:00:00+00:00', 0), // Thursday 15:00, 2 weeks ago.
            // Week 1 ago: nonzero at Thursday 14:00, quiet until Thursday 15:00 (1 hour gap).
            $this->sample('2026-08-06T14:00:00+00:00', 5),
            $this->sample('2026-08-06T15:00:00+00:00', 0), // Thursday 15:00, 1 week ago.
            // Unrelated Friday activity between the last two samples above and now.
            $this->sample('2026-08-07T09:00:00+00:00', 5),
            $this->sample($this->evaluatedHour()->format(\DateTimeInterface::ATOM), 0),
        ];

        $result = (new InterArrivalGapCalculator())->compute($series, $this->evaluatedHour());

        $distribution = $result->bucketGapDistribution;
        sort($distribution);
        self::assertSame([1, 2, 29], $distribution);
    }

    public function testTheStoreWideDistributionIncludesGapsFromEveryHourRegardlessOfBucket(): void
    {
        $series = [
            $this->sample('2026-08-13T10:00:00+00:00', 5),
            $this->sample('2026-08-13T11:00:00+00:00', 0), // 1 hour since the 10:00 event.
            $this->sample('2026-08-13T12:00:00+00:00', 0), // 2 hours since the 10:00 event.
            // 3 hours since the 10:00 event, as measured arriving at this
            // hour (before ITS OWN nonzero count resets the running gap for
            // whatever comes after it -- the same "gap immediately before
            // this hour" definition applies uniformly to every row).
            $this->sample('2026-08-13T13:00:00+00:00', 5),
            $this->sample('2026-08-13T14:00:00+00:00', 0), // 1 hour since the 13:00 event.
            $this->sample('2026-08-13T15:00:00+00:00', 0), // evaluated hour, excluded.
        ];

        $result = (new InterArrivalGapCalculator())->compute($series, $this->evaluatedHour());

        $distribution = $result->storeWideGapDistribution;
        sort($distribution);
        self::assertSame([1, 1, 2, 3], $distribution);
    }

    public function testAnEmptySeriesReturnsEmptyDistributionsAndAZeroCurrentGapRatherThanThrowing(): void
    {
        $result = (new InterArrivalGapCalculator())->compute([], $this->evaluatedHour());

        self::assertSame(0, $result->currentGapHours);
        self::assertSame([], $result->bucketGapDistribution);
        self::assertSame([], $result->storeWideGapDistribution);
    }

    public function testASeriesWithNoNonzeroHourAtAllFloorsTheCurrentGapToTheWindowsOwnSpanRatherThanGuessing(): void
    {
        $series = [
            $this->sample('2026-08-13T09:00:00+00:00', 0),
            $this->sample('2026-08-13T10:00:00+00:00', 0),
            $this->sample('2026-08-13T15:00:00+00:00', 0), // evaluated hour.
        ];

        $result = (new InterArrivalGapCalculator())->compute($series, $this->evaluatedHour());

        // Window spans from 09:00 (the series' own first row) to the
        // evaluated hour at 15:00: 6 hours, the documented floor.
        self::assertSame(6, $result->currentGapHours);
    }

    /**
     * @return HourlyCountSample[]
     */
    private function hourlySeries(int $hoursBack, int $countPerHour): array
    {
        $samples = [];

        for ($i = $hoursBack; $i >= 0; $i--) {
            $samples[] = new HourlyCountSample($this->evaluatedHour()->modify("-{$i} hours"), $countPerHour);
        }

        return $samples;
    }

    private function sample(string $bucket, int $count): HourlyCountSample
    {
        return new HourlyCountSample(new \DateTimeImmutable($bucket), $count);
    }

    private function evaluatedHour(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::EVALUATED_HOUR_STRING);
    }
}
