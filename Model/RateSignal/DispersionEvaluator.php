<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\RateSignal;

use Watchtower\Connector\Model\Api\MetricReport;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Rollup\HourlyCountSample;
use Watchtower\Connector\Model\Rollup\RollupRepository;
use Watchtower\Connector\Model\Seasonal\RetailCalendar;

/**
 * Check A: the always-active dispersion-based bound for the three
 * rate-based categories (basket_quote, checkout, customer_account).
 * Classifies one hour's observed count against its own (store view,
 * category, hour-of-day, day-of-week) bucket's median/MAD via a modified
 * z-score, then runs it through the same two-evaluation debounce state
 * machine CronHealth\Evaluator uses.
 *
 * Below VOLUME_FLOOR, rawStatus() switches to an inter-arrival check
 * (interArrivalRawStatus()) instead of the median/MAD comparison. At or
 * above it, ensembleClassify() also runs Check B (seasonal) and Check C
 * (trend) once each has warmed up, and combines all active checks by
 * majority vote with Check A as tiebreaker.
 */
class DispersionEvaluator
{
    /** Bumped whenever baseline logic or thresholds change, distinct from CronHealth\Evaluator's own version. */
    public const RULESET_VERSION = '1.3.0';

    /**
     * Default rolling baseline window for Check A. Public because
     * CoverageCommand sizes HistorySeeder's own backfill window against
     * this and LOW_VOLUME_LOOKBACK_WEEKS -- a new install must accumulate
     * as much rollup history as evaluation will ever look back for, or
     * widening the evaluator's own query window here does nothing until
     * enough real hours have actually been recorded to fill it.
     */
    public const BASELINE_WEEKS = 4;

    /**
     * interArrivalRawStatus()'s own lookback window, wider than Check A's
     * BASELINE_WEEKS on purpose: a bucket-conditioned (day-of-week,
     * hour-of-day) gap or count distribution needs several occurrences of
     * that exact slot to be a stable percentile input, and a 4-week window
     * can never offer more than 3-4 no matter how mature the install is --
     * confirmed against a real low-volume store's actual order history,
     * where 3-sample bucket distributions (e.g. a single unusually-long
     * historical gap out of 3) produced thresholds barely above ordinary
     * overnight quiet periods, firing SEVERE_DROP on completely normal
     * traffic most of the day. 12 weeks stays under RollupRepository's
     * 90-day hourly retention ceiling while giving a mature bucket up to
     * ~12 samples instead of 3. Public for the same CoverageCommand reason
     * as BASELINE_WEEKS above.
     */
    public const LOW_VOLUME_LOOKBACK_WEEKS = 12;

    /**
     * Minimum historical samples before a bucket can leave INSUFFICIENT_DATA,
     * and before interArrivalRawStatus() trusts a bucket-conditioned
     * distribution over the store-wide fallback. A fresh install (fewer than
     * LOW_VOLUME_LOOKBACK_WEEKS old) will still fall back to the coarser,
     * far-more-sample-rich store-wide distribution until its own bucket
     * genuinely has enough history -- that fallback is what LOW_VOLUME_LOOKBACK_WEEKS
     * makes actually reachable, rather than permanently short-circuited by a
     * fixed 4-week window.
     */
    private const MIN_HISTORICAL_SAMPLES = 3;

    /** The modified z-score threshold at which a deviation counts as an anomaly. */
    private const Z_ANOMALY_THRESHOLD = 3.5;

    /** Split between a mild and a severe anomaly; chosen, not measured. */
    private const Z_SEVERE_THRESHOLD = 7.0;

    /**
     * A MAD of 0.0 usually just means half the samples share the median
     * (the ordinary case at low traffic), not zero real variance. Flooring
     * it keeps one z-score formula on every path instead of special-casing
     * the zero case, which would otherwise score an ordinary single-unit
     * fluctuation as a maximal-confidence anomaly.
     */
    private const MAD_CONTINUITY_FLOOR = 0.5;

    /** Below this measured rate, rawStatus() uses the inter-arrival check instead of median/MAD. */
    private const VOLUME_FLOOR = 5;

    /** Below this estimated daily volume, the inter-arrival check uses the quieter percentile. */
    private const MIN_VIABLE_DAILY_VOLUME = 10;

    /** Precision over recall below MIN_VIABLE_DAILY_VOLUME: a false "down" alert costs more than a slower true one. */
    private const LOW_VOLUME_PERCENTILE_QUIET = 0.99;

    /** Gap percentile at or above MIN_VIABLE_DAILY_VOLUME; chosen, not measured. */
    private const LOW_VOLUME_PERCENTILE_DEFAULT = 0.95;

    /**
     * An hour with any events at all used to classify as trivially Normal in
     * Low-Volume Signal Mode -- structurally unable to ever report a spike,
     * confirmed against a real store's full order history where every one of
     * hundreds of low-volume evaluations was either Normal or SevereDrop,
     * never a spike. A genuine burst is still real signal at low volume, so
     * $observedCount is now compared against the bucket's (or store-wide
     * fallback's) own historical maximum. LOW_VOLUME_SPIKE_MIN_COUNT is an
     * absolute floor so an ordinary single order against a historical max of
     * 0 is never itself flagged.
     */
    private const LOW_VOLUME_SPIKE_MIN_COUNT = 5;

    /** How many times the historical maximum $observedCount must reach to count as a severe spike; chosen, not measured. */
    private const LOW_VOLUME_SEVERE_SPIKE_MULTIPLIER = 4;

    /**
     * @var InterArrivalGapCalculator
     */
    private readonly InterArrivalGapCalculator $gapCalculator;

    /**
     * @var SeasonalIndexEvaluator
     */
    private readonly SeasonalIndexEvaluator $seasonalIndexEvaluator;

    /**
     * @var TrendAdjustmentEvaluator
     */
    private readonly TrendAdjustmentEvaluator $trendAdjustmentEvaluator;

    /**
     * Null defaults are deliberate: Magento's DI compiler var_export()s
     * reflected constructor defaults, and an object instance isn't safely
     * representable that way, so the real instantiation happens in the body.
     *
     * @param RollupRepository $rollupRepository
     * @param DispersionStateRepository $repository
     * @param InterArrivalGapCalculator|null $gapCalculator stateless, no DI wiring needed
     * @param SeasonalIndexEvaluator|null $seasonalIndexEvaluator Check B, defaults to a real instance
     * @param TrendAdjustmentEvaluator|null $trendAdjustmentEvaluator Check C, defaults to a real instance
     */
    public function __construct(
        private readonly RollupRepository $rollupRepository,
        private readonly DispersionStateRepository $repository,
        ?InterArrivalGapCalculator $gapCalculator = null,
        ?SeasonalIndexEvaluator $seasonalIndexEvaluator = null,
        ?TrendAdjustmentEvaluator $trendAdjustmentEvaluator = null,
    ) {
        $this->gapCalculator = $gapCalculator ?? new InterArrivalGapCalculator();
        $this->seasonalIndexEvaluator = $seasonalIndexEvaluator
            ?? new SeasonalIndexEvaluator($rollupRepository, new RetailCalendar());
        $this->trendAdjustmentEvaluator = $trendAdjustmentEvaluator ?? new TrendAdjustmentEvaluator($rollupRepository);
    }

    /**
     * Runs one debounce tick for one (store view, category) pair and returns the MetricReport to submit for it.
     *
     * Evaluation always targets the last complete hour ($evaluatedHour),
     * never the hour still in progress. $evaluatedAt is the real wall-clock
     * instant the evaluation ran, carried on the wire so the receiver can
     * detect clock skew and backfill bursts.
     *
     * @param int $storeViewId Magento store view id, used for local baseline lookups and state keying
     * @param string $storeViewCode wire-level store view code for the outgoing report
     * @param string $category signal category, e.g. basket_quote, checkout, customer_account
     * @param int $observedCount the completed hour's observed event count for the category
     * @param \DateTimeImmutable $evaluatedHour top-of-hour instant of the completed hour being evaluated
     * @param \DateTimeImmutable $evaluatedAt real wall-clock instant the evaluation ran, for the wire evaluated_at
     * @return MetricReport
     */
    public function evaluate(
        int $storeViewId,
        string $storeViewCode,
        string $category,
        int $observedCount,
        \DateTimeImmutable $evaluatedHour,
        \DateTimeImmutable $evaluatedAt
    ): MetricReport {
        $state = $this->repository->get($storeViewId, $category);
        $rawStatus = $this->rawStatus($storeViewId, $category, $observedCount, $evaluatedHour);

        // First evaluation for this pair: nothing to debounce against yet.
        if ($state->isFirstEvaluation()) {
            $this->save($storeViewId, $category, null, SignalStatus::InsufficientData, $state->sequenceNumber + 1);

            return $this->report(
                $storeViewCode,
                $category,
                SignalStatus::InsufficientData,
                $state->sequenceNumber,
                $evaluatedAt,
                ReportReason::Transition
            );
        }

        if ($rawStatus === $state->confirmedStatus) {
            // No change; clear any stale pending status from a raw blip that never confirmed.
            $this->save($storeViewId, $category, null, $state->confirmedStatus, $state->sequenceNumber + 1);

            return $this->report(
                $storeViewCode,
                $category,
                $state->confirmedStatus,
                $state->sequenceNumber,
                $evaluatedAt,
                ReportReason::Heartbeat
            );
        }

        if ($state->pendingStatus !== null) {
            // Second consecutive differing tick: confirm using the current raw status.
            $this->save($storeViewId, $category, null, $rawStatus, $state->sequenceNumber + 1);

            // Same "warm-up finishing is not a recovery" reasoning as
            // CronHealth\Evaluator and IntegrationHealth\Evaluator --
            // confirming NORMAL straight out of the INSUFFICIENT_DATA seed
            // (a fresh install's baseline just finished building, see
            // BASELINE_WEEKS/LOW_VOLUME_LOOKBACK_WEEKS above) must not report
            // as a transition, or the platform sends a "back to normal" email
            // for a store view that was never actually down. An anomalous
            // status confirmed out of the seed is still a genuine
            // first-detected problem and keeps alerting.
            $reason = $state->confirmedStatus === SignalStatus::InsufficientData && $rawStatus === SignalStatus::Normal
                ? ReportReason::Heartbeat
                : ReportReason::Transition;

            return $this->report(
                $storeViewCode,
                $category,
                $rawStatus,
                $state->sequenceNumber,
                $evaluatedAt,
                $reason
            );
        }

        // First differing tick: start the confirmation counter; still report the old confirmed value.
        $this->save($storeViewId, $category, $rawStatus, $state->confirmedStatus, $state->sequenceNumber + 1);

        return $this->report(
            $storeViewCode,
            $category,
            $state->confirmedStatus,
            $state->sequenceNumber,
            $evaluatedAt,
            ReportReason::Heartbeat
        );
    }

    /**
     * Check A itself: classifies the observed count against its historical bucket via a modified z-score.
     *
     * Low-Volume Signal Mode keys off the signal's measured rate (its own
     * historical median), not off $observedCount directly -- otherwise a
     * normally-busy signal that happened to record only 2 or 3 events this
     * hour would read as trivially Normal instead of a real severe drop.
     *
     * @param int $storeViewId
     * @param string $category
     * @param int $observedCount
     * @param \DateTimeImmutable $evaluatedHour top-of-hour instant of the completed hour being evaluated
     * @return SignalStatus
     */
    private function rawStatus(
        int $storeViewId,
        string $category,
        int $observedCount,
        \DateTimeImmutable $evaluatedHour
    ): SignalStatus {
        $samples = $this->historicalSamples($storeViewId, $category, $evaluatedHour);

        if (count($samples) >= self::MIN_HISTORICAL_SAMPLES) {
            $values = array_map(static fn (HourlyCountSample $sample): int => $sample->count, $samples);
            $median = $this->median($values);

            if ($median >= self::VOLUME_FLOOR) {
                $mad = max($this->medianAbsoluteDeviation($values, $median), self::MAD_CONTINUITY_FLOOR);

                return $this->ensembleClassify($storeViewId, $category, $evaluatedHour, $observedCount, $median, $mad);
            }
        }

        // Too little history, or this signal's typical rate is below the volume floor: use the inter-arrival path.
        return $this->interArrivalRawStatus($storeViewId, $category, $evaluatedHour, $observedCount);
    }

    /**
     * The ensemble combiner: Check A's verdict is passed in; Check B and
     * Check C each independently classify their own seasonally/trend-
     * adjusted expected value against the same $mad and $observedCount,
     * via the same classify() method, so there's only one z-score formula.
     * With 2+ active checks, a strict majority wins; no majority (or only
     * Check A active) defers to Check A.
     *
     * @param int $storeViewId
     * @param string $category
     * @param \DateTimeImmutable $evaluatedHour top-of-hour instant of the completed hour being evaluated
     * @param int $observedCount
     * @param float $median Check A's own bucket-conditioned median
     * @param float $mad Check A's own (already MAD_CONTINUITY_FLOOR-floored) median absolute deviation
     * @return SignalStatus
     */
    private function ensembleClassify(
        int $storeViewId,
        string $category,
        \DateTimeImmutable $evaluatedHour,
        int $observedCount,
        float $median,
        float $mad
    ): SignalStatus {
        $checkA = $this->classify(0.6745 * ($observedCount - $median) / $mad);

        $activeVotes = [$checkA];

        $seasonalExpected = $this->seasonalIndexEvaluator->adjustedExpectedValue(
            $storeViewId,
            $category,
            $evaluatedHour,
            $median
        );

        if ($seasonalExpected !== null) {
            $activeVotes[] = $this->classify(0.6745 * ($observedCount - $seasonalExpected) / $mad);
        }

        $trendExpected = $this->trendAdjustmentEvaluator->adjustedExpectedValue(
            $storeViewId,
            $category,
            $evaluatedHour,
            $median
        );

        if ($trendExpected !== null) {
            $activeVotes[] = $this->classify(0.6745 * ($observedCount - $trendExpected) / $mad);
        }

        if (count($activeVotes) === 1) {
            return $checkA;
        }

        $voteCounts = [];

        foreach ($activeVotes as $vote) {
            $voteCounts[$vote->value] = ($voteCounts[$vote->value] ?? 0) + 1;
        }

        $majorityThreshold = intdiv(count($activeVotes), 2) + 1;

        foreach ($voteCounts as $statusValue => $count) {
            if ($count >= $majorityThreshold) {
                return SignalStatus::from($statusValue);
            }
        }

        return $checkA;
    }

    /**
     * Low-Volume Signal Mode: below VOLUME_FLOOR, a per-hour count isn't a
     * meaningful measurement on its own, so silence is checked against the
     * bucket's own historical inter-arrival distribution, and any nonzero
     * count is checked for a genuine burst via lowVolumeSpikeStatus() rather
     * than assumed Normal.
     *
     * @param int $storeViewId
     * @param string $category
     * @param \DateTimeImmutable $evaluatedHour top-of-hour instant of the completed hour being evaluated
     * @param int $observedCount the completed hour's own observed count
     * @return SignalStatus
     */
    private function interArrivalRawStatus(
        int $storeViewId,
        string $category,
        \DateTimeImmutable $evaluatedHour,
        int $observedCount
    ): SignalStatus {
        $series = $this->rollupRepository->allHourlyCountsInWindow(
            $storeViewId,
            $category,
            self::LOW_VOLUME_LOOKBACK_WEEKS,
            $evaluatedHour
        );

        $gaps = $this->gapCalculator->compute($series, $evaluatedHour);

        // Widen to the store-wide distribution only if the bucket-specific one lacks enough samples to calibrate on.
        $distribution = count($gaps->bucketGapDistribution) >= self::MIN_HISTORICAL_SAMPLES
            ? $gaps->bucketGapDistribution
            : $gaps->storeWideGapDistribution;

        if (count($distribution) < self::MIN_HISTORICAL_SAMPLES) {
            return SignalStatus::InsufficientData;
        }

        if ($observedCount > 0) {
            return $this->lowVolumeSpikeStatus($series, $evaluatedHour, $observedCount);
        }

        $threshold = $this->percentile($distribution, $this->lowVolumeThresholdPercentile($series, $evaluatedHour));

        return $gaps->currentGapHours > $threshold ? SignalStatus::SevereDrop : SignalStatus::Normal;
    }

    /**
     * An hour with events is Normal unless $observedCount is a genuine burst:
     * at least LOW_VOLUME_SPIKE_MIN_COUNT, and at least
     * LOW_VOLUME_SEVERE_SPIKE_MULTIPLIER times the historical maximum for
     * this bucket (or the store-wide series, under the same sample-count
     * fallback interArrivalRawStatus() itself uses). A bucket/series that has
     * never seen an event has a historical maximum of 0, so the absolute
     * floor is what stops an ordinary first order from reading as a spike.
     *
     * @param HourlyCountSample[] $series the same window fetched for the gap calculation, oldest first
     * @param \DateTimeImmutable $evaluatedHour top-of-hour instant of the completed hour being evaluated
     * @param int $observedCount the completed hour's own observed count
     * @return SignalStatus
     */
    private function lowVolumeSpikeStatus(
        array $series,
        \DateTimeImmutable $evaluatedHour,
        int $observedCount
    ): SignalStatus {
        $bucketCounts = $this->bucketCounts($series, $evaluatedHour);

        $historicalCounts = count($bucketCounts) >= self::MIN_HISTORICAL_SAMPLES
            ? $bucketCounts
            : array_map(static fn (HourlyCountSample $sample): int => $sample->count, $series);

        $historicalMax = $historicalCounts !== [] ? max($historicalCounts) : 0;

        if ($observedCount >= self::LOW_VOLUME_SPIKE_MIN_COUNT
            && $observedCount >= $historicalMax * self::LOW_VOLUME_SEVERE_SPIKE_MULTIPLIER
        ) {
            return SignalStatus::SevereSpike;
        }

        return SignalStatus::Normal;
    }

    /**
     * Historical counts from $series sharing the evaluated hour's own
     * (day-of-week, hour-of-day) bucket, excluding the evaluated hour's own row.
     *
     * @param HourlyCountSample[] $series oldest first
     * @param \DateTimeImmutable $evaluatedHour top-of-hour instant of the completed hour being evaluated
     * @return int[]
     */
    private function bucketCounts(array $series, \DateTimeImmutable $evaluatedHour): array
    {
        $isoDayOfWeek = (int) $evaluatedHour->format('N');
        $hourOfDay = (int) $evaluatedHour->format('G');
        $evaluatedBucketKey = $evaluatedHour->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:00:00');

        $counts = [];

        foreach ($series as $sample) {
            $sampleBucketKey = $sample->bucket->format('Y-m-d H:00:00');

            if ($sampleBucketKey === $evaluatedBucketKey) {
                continue;
            }

            $sameBucket = (int) $sample->bucket->format('N') === $isoDayOfWeek
                && (int) $sample->bucket->format('G') === $hourOfDay;

            if ($sameBucket) {
                $counts[] = $sample->count;
            }
        }

        return $counts;
    }

    /**
     * Picks the low-volume percentile threshold: the quieter (higher)
     * percentile below MIN_VIABLE_DAILY_VOLUME, the default at or above it.
     * Estimated daily volume uses the window's actual elapsed span, not the
     * nominal LOW_VOLUME_LOOKBACK_WEEKS, so a brand-new install isn't understated.
     *
     * @param HourlyCountSample[] $series the same window fetched for the gap calculation
     * @param \DateTimeImmutable $evaluatedHour top-of-hour instant of the completed hour being evaluated
     * @return float
     */
    private function lowVolumeThresholdPercentile(array $series, \DateTimeImmutable $evaluatedHour): float
    {
        if (empty($series)) {
            return self::LOW_VOLUME_PERCENTILE_QUIET;
        }

        $totalCount = array_sum(array_map(static fn (HourlyCountSample $sample): int => $sample->count, $series));
        $elapsedHours = max(1, $this->gapCalculator->hoursBetween($series[0]->bucket, $evaluatedHour));
        $estimatedDailyVolume = $totalCount / ($elapsedHours / 24);

        return $estimatedDailyVolume < self::MIN_VIABLE_DAILY_VOLUME
            ? self::LOW_VOLUME_PERCENTILE_QUIET
            : self::LOW_VOLUME_PERCENTILE_DEFAULT;
    }

    /**
     * Computes the Nth percentile of a list of gap-hour samples via linear
     * interpolation between the two closest ranks (the common "inclusive"
     * definition, matching numpy's and Excel's PERCENTILE.INC default).
     *
     * @param int[] $values
     * @param float $percentile 0.0 through 1.0
     * @return float
     */
    private function percentile(array $values, float $percentile): float
    {
        sort($values);
        $count = count($values);

        if ($count === 1) {
            return (float) $values[0];
        }

        $rank = $percentile * ($count - 1);
        $lowerIndex = (int) floor($rank);
        $upperIndex = (int) ceil($rank);

        if ($lowerIndex === $upperIndex) {
            return (float) $values[$lowerIndex];
        }

        $weight = $rank - $lowerIndex;

        return $values[$lowerIndex] + $weight * ($values[$upperIndex] - $values[$lowerIndex]);
    }

    /**
     * Fetches this bucket's historical counts, excluding the evaluated hour's own row.
     *
     * @param int $storeViewId
     * @param string $category
     * @param \DateTimeImmutable $evaluatedHour top-of-hour instant of the completed hour being evaluated
     * @return HourlyCountSample[]
     */
    private function historicalSamples(int $storeViewId, string $category, \DateTimeImmutable $evaluatedHour): array
    {
        $samples = $this->rollupRepository->hourlyCountsForBucket(
            $storeViewId,
            $category,
            (int) $evaluatedHour->format('N'),
            (int) $evaluatedHour->format('G'),
            self::BASELINE_WEEKS,
            $evaluatedHour
        );

        // Exclude the evaluated hour's own row so the value being classified can't pollute its own baseline.
        $evaluatedHourBucket = $evaluatedHour->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:00:00');
        $isNotTheEvaluatedHour = static fn (HourlyCountSample $sample): bool
            => $sample->bucket->format('Y-m-d H:00:00') !== $evaluatedHourBucket;

        return array_values(array_filter($samples, $isNotTheEvaluatedHour));
    }

    /**
     * Maps a modified z-score to a status, per the mild/severe split documented on Z_SEVERE_THRESHOLD above.
     *
     * @param float $modifiedZ
     * @return SignalStatus
     */
    private function classify(float $modifiedZ): SignalStatus
    {
        $magnitude = abs($modifiedZ);

        if ($magnitude < self::Z_ANOMALY_THRESHOLD) {
            return SignalStatus::Normal;
        }

        $severe = $magnitude >= self::Z_SEVERE_THRESHOLD;

        if ($modifiedZ > 0) {
            return $severe ? SignalStatus::SevereSpike : SignalStatus::MildSpike;
        }

        return $severe ? SignalStatus::SevereDrop : SignalStatus::MildDrop;
    }

    /**
     * Computes the median of a list of counts or deviations.
     *
     * @param array<int|float> $values
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

    /**
     * Computes the median absolute deviation of a list of integer counts around a given median.
     *
     * @param int[] $values
     * @param float $median
     * @return float
     */
    private function medianAbsoluteDeviation(array $values, float $median): float
    {
        $deviations = array_map(static fn (int $value): float => abs($value - $median), $values);

        return $this->median($deviations);
    }

    /**
     * Persists the updated DispersionState for this tick.
     *
     * @param int $storeViewId
     * @param string $category
     * @param SignalStatus|null $pendingStatus
     * @param SignalStatus|null $confirmedStatus
     * @param int $sequenceNumber
     * @return void
     */
    private function save(
        int $storeViewId,
        string $category,
        ?SignalStatus $pendingStatus,
        ?SignalStatus $confirmedStatus,
        int $sequenceNumber,
    ): void {
        $this->repository->save(new DispersionState(
            storeViewId: $storeViewId,
            category: $category,
            pendingStatus: $pendingStatus,
            confirmedStatus: $confirmedStatus,
            sequenceNumber: $sequenceNumber,
        ));
    }

    /**
     * Builds the MetricReport to submit for this tick.
     *
     * @param string $storeViewCode
     * @param string $category
     * @param SignalStatus $status
     * @param int $sequenceNumber
     * @param \DateTimeImmutable $evaluatedAt real wall-clock instant the evaluation ran
     * @param ReportReason $reason
     * @return MetricReport
     */
    private function report(
        string $storeViewCode,
        string $category,
        SignalStatus $status,
        int $sequenceNumber,
        \DateTimeImmutable $evaluatedAt,
        ReportReason $reason
    ): MetricReport {
        return new MetricReport(
            storeViewCode: $storeViewCode,
            eventType: $category,
            status: $status,
            sequenceNumber: $sequenceNumber,
            evaluatedAt: $evaluatedAt,
            reason: $reason,
            rulesetVersion: self::RULESET_VERSION,
        );
    }
}
