<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\RateSignal;

use Watchtower\Connector\Model\Rollup\DailyCountSample;
use Watchtower\Connector\Model\Rollup\RollupRepository;
use Watchtower\Connector\Model\Seasonal\RetailCalendar;

/**
 * Check B: a multiplicative seasonal index layered on top of Check A's own
 * median, so a calendar period (an ordinary weekday, Black Friday week,
 * Easter) that's typically busier or quieter than the yearly average shifts
 * the expected value accordingly rather than reading as a spike or drop
 * every time it recurs.
 *
 * seasonal_index = (this period's historical daily average) / (the retained
 * window's overall daily average); adjusted expected value = Check A's own
 * median x seasonal_index. Abstains (returns null) below the one-year
 * retention threshold or without enough same-period historical occurrences
 * to average meaningfully; DispersionEvaluator's ensemble combiner treats an
 * abstention as "this check did not vote".
 */
class SeasonalIndexEvaluator
{
    /** Retained daily history required before Check B activates at all. */
    private const MIN_RETAINED_DAYS = 365;

    /**
     * A period average computed from a single historical occurrence is not
     * a real average -- 2 is the lowest count that expresses any central
     * tendency at all.
     */
    private const MIN_PERIOD_SAMPLES = 2;

    /**
     * @param RollupRepository $rollupRepository
     * @param RetailCalendar $retailCalendar
     */
    public function __construct(
        private readonly RollupRepository $rollupRepository,
        private readonly RetailCalendar $retailCalendar,
    ) {
    }

    /**
     * Computes the seasonally-adjusted expected value, or null if this check abstains.
     *
     * @param int $storeViewId
     * @param string $category
     * @param \DateTimeImmutable $evaluatedHour top-of-hour instant of the completed hour being evaluated
     * @param float $median Check A's own bucket-conditioned median, the recent level the index adjusts
     * @return float|null
     */
    public function adjustedExpectedValue(
        int $storeViewId,
        string $category,
        \DateTimeImmutable $evaluatedHour,
        float $median
    ): ?float {
        $dailySamples = $this->rollupRepository->dailyCountsInWindow(
            $storeViewId,
            $category,
            $evaluatedHour,
            RollupRepository::DAILY_RETENTION_DAYS
        );

        // Evaluated day's own row (if a partial day-so-far count already
        // exists) must never pollute the averages used to classify it --
        // same self-pollution concern DispersionEvaluator::historicalSamples()
        // documents for Check A.
        $evaluatedDateKey = $evaluatedHour->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        $isNotTheEvaluatedDay = static fn (DailyCountSample $sample): bool
            => $sample->date->format('Y-m-d') !== $evaluatedDateKey;
        $dailySamples = array_values(array_filter($dailySamples, $isNotTheEvaluatedDay));

        if (empty($dailySamples)) {
            return null;
        }

        $retainedDays = $this->daysBetween($dailySamples[0]->date, $evaluatedHour);

        if ($retainedDays < self::MIN_RETAINED_DAYS) {
            return null;
        }

        $evaluatedPeriodKey = $this->retailCalendar->periodKeyFor($evaluatedHour);
        $matchesEvaluatedPeriod = function (DailyCountSample $sample) use ($evaluatedPeriodKey): bool {
            return $this->retailCalendar->periodKeyFor($sample->date) === $evaluatedPeriodKey;
        };
        $periodSamples = array_values(array_filter($dailySamples, $matchesEvaluatedPeriod));

        if (count($periodSamples) < self::MIN_PERIOD_SAMPLES) {
            return null;
        }

        $periodAverage = $this->average($periodSamples);
        $overallBaseline = $this->average($dailySamples);

        if ($overallBaseline <= 0.0) {
            return null;
        }

        $seasonalIndex = $periodAverage / $overallBaseline;

        return $median * $seasonalIndex;
    }

    /**
     * Arithmetic mean of a list of daily samples' own counts.
     *
     * @param DailyCountSample[] $samples
     * @return float
     */
    private function average(array $samples): float
    {
        $counts = array_map(static fn (DailyCountSample $sample): int => $sample->count, $samples);

        return array_sum($counts) / count($counts);
    }

    /**
     * Whole days between two instants, rounded to the nearest day.
     *
     * @param \DateTimeImmutable $from
     * @param \DateTimeImmutable $to
     * @return int
     */
    private function daysBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400);
    }
}
