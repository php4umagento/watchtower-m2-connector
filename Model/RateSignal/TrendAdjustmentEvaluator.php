<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\RateSignal;

use Watchtower\Connector\Model\Rollup\HourlyCountSample;
use Watchtower\Connector\Model\Rollup\RollupRepository;

/**
 * Check C: compares Check A's current rolling baseline against the same-shape
 * baseline from TREND_LOOKBACK_WEEKS prior and adjusts the expected value by
 * the resulting growth rate, so an organically growing or declining store
 * isn't misread as a recurring anomaly.
 *
 * Both medians represent their own window's CENTER, roughly
 * CENTER_OFFSET_WEEKS old, not "now" -- so their raw ratio measures growth
 * over the full TREND_LOOKBACK_WEEKS separation between those centers.
 * Applying it directly would over-project by nearly the whole lookback and
 * hand a growing store the MILD_DROP this check exists to prevent. It is
 * instead compounded forward only as far as "now":
 * clampedGrowthRate ** (CENTER_OFFSET_WEEKS / TREND_LOOKBACK_WEEKS).
 *
 * Returns null below the history threshold; the ensemble combiner reads that
 * as "this check did not vote".
 */
class TrendAdjustmentEvaluator
{
    /**
     * TREND_LOOKBACK_WEEKS + BASELINE_WEEKS must fit inside
     * RollupRepository::HOURLY_RETENTION_DAYS or the prior window silently
     * truncates to fewer samples; 8+4 weeks leaves a 6-day margin.
     */
    private const TREND_LOOKBACK_WEEKS = 8;

    /** Matches DispersionEvaluator::MIN_HISTORICAL_SAMPLES, applied to the PRIOR window. */
    private const MIN_HISTORICAL_SAMPLES = 3;

    /** Matches DispersionEvaluator::BASELINE_WEEKS, so the two medians are directly comparable. */
    private const BASELINE_WEEKS = 4;

    /** Average age, in weeks, of a BASELINE_WEEKS-wide window's own weekly samples. */
    private const CENTER_OFFSET_WEEKS = (self::BASELINE_WEEKS + 1) / 2.0;

    /**
     * Bounds on the raw measured ratio, before projection, so one noisy prior
     * window can't imply e.g. 50x growth over the lookback period.
     */
    private const GROWTH_CLAMP_MIN = 0.5;
    private const GROWTH_CLAMP_MAX = 2.0;

    /**
     * @param RollupRepository $rollupRepository
     */
    public function __construct(
        private readonly RollupRepository $rollupRepository
    ) {
    }

    /**
     * Computes the trend-adjusted expected value, or null if this check abstains.
     *
     * @param int $storeViewId
     * @param string $category
     * @param \DateTimeImmutable $evaluatedHour top-of-hour instant of the completed hour being evaluated
     * @param float $currentMedian Check A's own bucket-conditioned median for the current window
     * @return float|null
     */
    public function adjustedExpectedValue(
        int $storeViewId,
        string $category,
        \DateTimeImmutable $evaluatedHour,
        float $currentMedian
    ): ?float {
        $priorHour = $evaluatedHour->modify('-' . self::TREND_LOOKBACK_WEEKS . ' weeks');

        $priorSamples = $this->rollupRepository->hourlyCountsForBucket(
            $storeViewId,
            $category,
            (int) $evaluatedHour->format('N'),
            (int) $evaluatedHour->format('G'),
            self::BASELINE_WEEKS,
            $priorHour
        );

        if (count($priorSamples) < self::MIN_HISTORICAL_SAMPLES) {
            return null;
        }

        $priorValues = array_map(static fn (HourlyCountSample $sample): int => $sample->count, $priorSamples);
        $priorMedian = $this->median($priorValues);

        if ($priorMedian <= 0.0) {
            return null;
        }

        $growthRate = $currentMedian / $priorMedian;
        $clampedGrowthRate = max(self::GROWTH_CLAMP_MIN, min(self::GROWTH_CLAMP_MAX, $growthRate));

        // Only CENTER_OFFSET_WEEKS of the measured growth is projected; see the class docblock.
        $projectedFactor = $clampedGrowthRate ** (self::CENTER_OFFSET_WEEKS / self::TREND_LOOKBACK_WEEKS);

        return $currentMedian * $projectedFactor;
    }

    /**
     * Computes the median of a list of counts.
     *
     * @param int[] $values
     * @return float
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return (float) $values[$middle];
    }
}
