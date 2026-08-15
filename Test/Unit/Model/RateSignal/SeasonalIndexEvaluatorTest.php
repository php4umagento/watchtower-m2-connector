<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\RateSignal;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\RateSignal\SeasonalIndexEvaluator;
use Watchtower\Connector\Model\Rollup\DailyCountSample;
use Watchtower\Connector\Model\Rollup\RollupRepository;
use Watchtower\Connector\Model\Seasonal\RetailCalendar;

/**
 * Check B, the seasonal index. Uses a REAL RetailCalendar throughout -- it's a cheap,
 * deterministic pure class already covered by its own dedicated test suite
 * -- so these tests isolate SeasonalIndexEvaluator's own averaging/
 * abstention logic rather than re-proving calendar correctness.
 *
 * Every fixture below spans at most ~1 year of history (well within
 * RollupRepository's own 400-day daily retention), getting its required
 * >=2 same-period samples from NEARBY DAYS WITHIN A SINGLE retained year
 * (RetailCalendar's own day-bucket period keys, see its docblock) rather
 * than from two separate years' worth of history. Fixtures spanning 730
 * days would get two period matches and be mechanically valid against the
 * mock, but are unreachable in production, since dailyCountsInWindow's
 * own 400-day lookback and the daily table's own retention can never
 * actually return data that old -- proving the arithmetic without proving
 * reachability would let Check B be structurally dead outside a
 * moving-holiday window.
 */
class SeasonalIndexEvaluatorTest extends TestCase
{
    private const STORE_VIEW_ID = 7;
    private const CATEGORY = 'checkout';
    private const EVALUATED_HOUR = '2026-08-15T15:00:00+00:00';
    private const MEDIAN = 100.0;

    public function testARealSeasonalUpliftWithSufficientHistoryProducesAnAdjustedExpectedValueAboveTheMedian(): void
    {
        // 2025-08-14 and 2025-08-16 share the evaluated day's own bucket
        // key (both day-of-year 226 +/-1, well within the 7-day bucket) --
        // TWO period matches from ONE retained year, ~366 days back, not
        // two separate years. overall = (150+50+150+50+50+50)/6 = 83.33,
        // period = (150+150)/2 = 150, seasonal_index = 1.8, adjusted
        // expected = 100 * 1.8 = 180.
        $samples = [
            $this->sample('2025-08-14', 150),
            $this->sample('2025-08-20', 50), // one bucket over -- ordinary, not a period match.
            $this->sample('2025-08-16', 150),
            $this->sample('2025-11-01', 50),
            $this->sample('2026-02-01', 50),
            $this->sample('2026-05-01', 50),
        ];

        $result = $this->evaluate($samples);

        self::assertEqualsWithDelta(180.0, $result, 0.01);
    }

    public function testRetainedHistoryShorterThanAYearAbstains(): void
    {
        $samples = [
            $this->sample('2026-02-01', 150),
            $this->sample('2026-03-01', 150),
            $this->sample('2026-05-01', 50),
        ];

        self::assertNull($this->evaluate($samples));
    }

    public function testFewerThanTwoHistoricalOccurrencesOfTheEvaluatedPeriodAbstains(): void
    {
        $samples = [
            // Only ONE prior occurrence in the evaluated day's own bucket;
            // everything else spans enough time to clear the retained-
            // history threshold on its own but falls in different buckets.
            $this->sample('2025-08-14', 150),
            $this->sample('2025-08-20', 50),
            $this->sample('2025-05-01', 50),
            $this->sample('2026-05-01', 50),
        ];

        self::assertNull($this->evaluate($samples));
    }

    public function testAZeroOverallBaselineAbstainsRatherThanDividingByZero(): void
    {
        $samples = [
            $this->sample('2025-08-14', 0),
            $this->sample('2025-08-16', 0),
            $this->sample('2025-11-01', 0),
            $this->sample('2026-02-01', 0),
        ];

        self::assertNull($this->evaluate($samples));
    }

    public function testTheEvaluatedDaysOwnRowIsExcludedFromBothAverages(): void
    {
        $samplesWithoutToday = [
            $this->sample('2025-08-14', 150),
            $this->sample('2025-08-20', 50),
            $this->sample('2025-08-16', 150),
            $this->sample('2025-11-01', 50),
            $this->sample('2026-02-01', 50),
            $this->sample('2026-05-01', 50),
        ];

        // A same-day row with a wildly different count; if this were NOT
        // excluded, it would shift both averages and change the result.
        $samplesWithToday = array_merge($samplesWithoutToday, [$this->sample('2026-08-15', 99999)]);

        self::assertEqualsWithDelta(
            $this->evaluate($samplesWithoutToday),
            $this->evaluate($samplesWithToday),
            0.01
        );
    }

    public function testAMovingHolidayPeriodUsesItsNamedKeyNotTheRawCalendarDate(): void
    {
        // Black Friday 2026 is Nov 27. 2025-11-25 and 2025-11-29 both fall
        // within +/-3 days of Black Friday 2025's own anchor (Nov 28) --
        // TWO period matches from ONE retained year (~367 days back), with
        // different raw MM-DD values (since the holiday moves), both
        // resolving to the SAME 'black_friday' key.
        $samples = [
            $this->sample('2025-11-25', 150),
            $this->sample('2025-11-29', 150),
            $this->sample('2025-06-01', 50),
            $this->sample('2026-01-01', 50),
            $this->sample('2026-03-01', 50),
            $this->sample('2026-05-01', 50),
        ];

        $result = $this->evaluate($samples, '2026-11-27T15:00:00+00:00');

        // overall = (150+150+50+50+50+50)/6 = 83.33, period = (150+150)/2 = 150,
        // seasonal_index = 1.8, adjusted expected = 100 * 1.8 = 180.
        self::assertEqualsWithDelta(180.0, $result, 0.01);
    }

    public function testFetchesOnlyWithinTheRepositorysOwnDailyRetentionWindow(): void
    {
        // The lookback window this class requests must never exceed what
        // RollupRepository::DAILY_RETENTION_DAYS can actually retain --
        // asking for more would silently return the same, shorter series
        // every time rather than fail loudly, silently masking a mismatch
        // between this class's own window and the daily table's real
        // retention.
        $rollupRepository = $this->createMock(RollupRepository::class);
        $rollupRepository->expects(self::once())
            ->method('dailyCountsInWindow')
            ->with(
                self::STORE_VIEW_ID,
                self::CATEGORY,
                self::anything(),
                RollupRepository::DAILY_RETENTION_DAYS
            )
            ->willReturn([]);

        (new SeasonalIndexEvaluator($rollupRepository, new RetailCalendar()))->adjustedExpectedValue(
            self::STORE_VIEW_ID,
            self::CATEGORY,
            new \DateTimeImmutable(self::EVALUATED_HOUR),
            self::MEDIAN
        );
    }

    /**
     * @param DailyCountSample[] $samples
     */
    private function evaluate(array $samples, string $evaluatedHour = self::EVALUATED_HOUR): ?float
    {
        $rollupRepository = $this->createStub(RollupRepository::class);
        $rollupRepository->method('dailyCountsInWindow')->willReturn($samples);

        $evaluator = new SeasonalIndexEvaluator($rollupRepository, new RetailCalendar());

        return $evaluator->adjustedExpectedValue(
            self::STORE_VIEW_ID,
            self::CATEGORY,
            new \DateTimeImmutable($evaluatedHour),
            self::MEDIAN
        );
    }

    private function sample(string $date, int $count): DailyCountSample
    {
        return new DailyCountSample(new \DateTimeImmutable($date), $count);
    }
}
