<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\RateSignal;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\RateSignal\TrendAdjustmentEvaluator;
use Watchtower\Connector\Model\Rollup\HourlyCountSample;
use Watchtower\Connector\Model\Rollup\RollupRepository;

/**
 * Check C, the trend adjustment. $currentMedian is always supplied
 * directly (it's Check A's
 * own already-computed median, not recomputed here) -- these tests isolate
 * the growth-ratio/clamp/projection arithmetic against the PRIOR window
 * this class itself fetches.
 *
 * Expected values below use the corrected projection formula
 * (clampedGrowthRate ** (CENTER_OFFSET_WEEKS / TREND_LOOKBACK_WEEKS), with
 * CENTER_OFFSET_WEEKS = 2.5 and TREND_LOOKBACK_WEEKS = 8, exponent =
 * 0.3125). Applying the raw ratio directly
 * (adjusted = currentMedian x growthRate) over-projects by roughly a full
 * lookback period. See TrendAdjustmentEvaluator's own docblock for the
 * full reasoning.
 */
class TrendAdjustmentEvaluatorTest extends TestCase
{
    private const STORE_VIEW_ID = 7;
    private const CATEGORY = 'checkout';
    private const EVALUATED_HOUR = '2026-08-15T15:00:00+00:00';

    public function testSustainedGrowthProducesAnAdjustedExpectedValueAboveTheCurrentMedian(): void
    {
        // prior median 80, current median 100 -> growth rate 1.25 (within
        // the clamp band) -> projected factor = 1.25 ** 0.3125 ~ 1.0722 ->
        // adjusted expected ~ 100 * 1.0722 = 107.22.
        $result = $this->evaluate(priorCounts: [78, 80, 82], currentMedian: 100.0);

        self::assertEqualsWithDelta(107.22, $result, 0.01);
    }

    public function testSustainedDeclineProducesAnAdjustedExpectedValueBelowTheCurrentMedian(): void
    {
        // prior median 100, current median 80 -> growth rate 0.8 ->
        // projected factor = 0.8 ** 0.3125 ~ 0.9326 -> adjusted expected
        // ~ 80 * 0.9326 = 74.61.
        $result = $this->evaluate(priorCounts: [98, 100, 102], currentMedian: 80.0);

        self::assertEqualsWithDelta(74.61, $result, 0.01);
    }

    public function testAnExtremeGrowthRatioIsClampedRatherThanAppliedRaw(): void
    {
        // prior median 1, current median 50 -> raw ratio 50x; clamped to
        // the documented ceiling (2.0) before projection -> projected
        // factor = 2.0 ** 0.3125 ~ 1.2419 -> adjusted expected ~ 50 * 1.2419
        // = 62.09, NOT the raw 50 * 50 = 2500 nor the unprojected 50 * 2.0 = 100.
        $result = $this->evaluate(priorCounts: [1, 1, 1], currentMedian: 50.0);

        self::assertEqualsWithDelta(62.09, $result, 0.01);
    }

    public function testAnExtremeDeclineRatioIsClampedRatherThanAppliedRaw(): void
    {
        // prior median 100, current median 1 -> raw ratio 0.01x; clamped
        // to the documented floor (0.5) before projection -> projected
        // factor = 0.5 ** 0.3125 ~ 0.8052 -> adjusted expected ~ 1 * 0.8052
        // = 0.805, NOT the unprojected 1 * 0.5 = 0.5.
        $result = $this->evaluate(priorCounts: [98, 100, 102], currentMedian: 1.0);

        self::assertEqualsWithDelta(0.805, $result, 0.01);
    }

    public function testFewerThanTheMinimumPriorSamplesAbstains(): void
    {
        $result = $this->evaluate(priorCounts: [80, 82], currentMedian: 100.0);

        self::assertNull($result);
    }

    public function testAZeroPriorMedianAbstainsRatherThanDividingByZero(): void
    {
        $result = $this->evaluate(priorCounts: [0, 0, 0], currentMedian: 100.0);

        self::assertNull($result);
    }

    /**
     * The prior window's own offset is the entire point of this check --
     * an earlier version fetched a window that outran the hourly rollup's
     * own retention (silently truncating the prior sample count with no
     * error), and no test asserted the offset directly, only the
     * arithmetic on whatever samples a non-discriminating stub happened to
     * hand back. Asserts hourlyCountsForBucket is actually called with
     * evaluatedHour minus TREND_LOOKBACK_WEEKS (8), not the evaluated hour
     * itself.
     */
    public function testFetchesThePriorWindowOffsetByTheTrendLookbackWeeksNotTheCurrentHour(): void
    {
        $evaluatedHour = new \DateTimeImmutable(self::EVALUATED_HOUR);
        $expectedPriorHour = $evaluatedHour->modify('-8 weeks');

        $rollupRepository = $this->createMock(RollupRepository::class);
        $rollupRepository->expects(self::once())
            ->method('hourlyCountsForBucket')
            ->with(
                self::STORE_VIEW_ID,
                self::CATEGORY,
                (int) $evaluatedHour->format('N'),
                (int) $evaluatedHour->format('G'),
                4,
                $expectedPriorHour
            )
            ->willReturn([]);

        (new TrendAdjustmentEvaluator($rollupRepository))->adjustedExpectedValue(
            self::STORE_VIEW_ID,
            self::CATEGORY,
            $evaluatedHour,
            100.0
        );
    }

    /**
     * TREND_LOOKBACK_WEEKS (8) + BASELINE_WEEKS (4) = 12 weeks = 84 days
     * must stay under RollupRepository::HOURLY_RETENTION_DAYS (90), with
     * margin, so the prior window's own oldest sample is never pruned
     * before this class can read it.
     */
    public function testThePriorWindowsOldestPossibleSampleStaysWithinHourlyRetention(): void
    {
        $evaluatedHour = new \DateTimeImmutable(self::EVALUATED_HOUR);
        $priorHour = $evaluatedHour->modify('-8 weeks');
        $oldestPossibleSample = $priorHour->modify('-4 weeks');

        $ageInDays = ($evaluatedHour->getTimestamp() - $oldestPossibleSample->getTimestamp()) / 86400;

        self::assertLessThan(RollupRepository::HOURLY_RETENTION_DAYS, $ageInDays);
    }

    /**
     * @param int[] $priorCounts
     */
    private function evaluate(array $priorCounts, float $currentMedian): ?float
    {
        $bucket = new \DateTimeImmutable(self::EVALUATED_HOUR);
        $samples = array_map(
            static fn (int $count): HourlyCountSample => new HourlyCountSample($bucket, $count),
            $priorCounts
        );

        $rollupRepository = $this->createStub(RollupRepository::class);
        $rollupRepository->method('hourlyCountsForBucket')->willReturn($samples);

        $evaluator = new TrendAdjustmentEvaluator($rollupRepository);

        return $evaluator->adjustedExpectedValue(
            self::STORE_VIEW_ID,
            self::CATEGORY,
            new \DateTimeImmutable(self::EVALUATED_HOUR),
            $currentMedian
        );
    }
}
