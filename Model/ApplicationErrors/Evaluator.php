<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\ApplicationErrors;

use Watchtower\Connector\Model\AdminAuthFailure\InstallEventCounterRepository;
use Watchtower\Connector\Model\Api\MetricReport;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Debounce\TwoEvaluationDebounce;
use Watchtower\Connector\Model\HealthState\HealthState;
use Watchtower\Connector\Model\HealthState\HealthStateRepository;
use Watchtower\Connector\Model\Rollup\HourlyCountSample;
use Watchtower\Connector\Model\Rollup\InstallRollupRepository;

/**
 * The application_errors signal: caught application exceptions logged in the
 * evaluated hour, install-wide (docs/connector-error-log-signal-prd.md).
 *
 * INSTALL-SCOPED, BUT RATE-BASED. Every other install-scoped signal
 * (cron_health, admin_auth_failure, indexer_health, queue_health) is a
 * state or fixed-threshold check with no baseline. This is the first
 * install-scoped signal that also needs one: it classifies each hour's
 * count against THIS install's own historical median for that hour of the
 * week, the same Check A (median/MAD modified z-score) mechanism
 * RateSignal\DispersionEvaluator uses for the store-view-scoped rate
 * signals -- reimplemented independently here rather than reused, see below.
 *
 * WHY A SEPARATE classify()/median()/medianAbsoluteDeviation(), NOT SHARED
 * WITH DispersionEvaluator. That class is tightly coupled to store-view
 * keying (RollupRepository, DispersionStateRepository) and is depended on by
 * three live signals; changing its classification method to add a
 * one-directional mode would touch code every one of them relies on. A
 * small, independent copy here (~15 lines) means this signal cannot regress
 * checkout/basket_quote/customer_account, at the cost of duplication this
 * module already accepts elsewhere for exactly this reason (see
 * InstallEventCounterRepository vs EventCounterRepository's own docblocks).
 *
 * NO ENSEMBLE (Check B/C). DispersionEvaluator's ensembleClassify() also
 * runs SeasonalIndexEvaluator and TrendAdjustmentEvaluator once warmed up.
 * The PRD's EL1 only specifies the one-directional clamp for Check A;
 * reusing the full ensemble would silently inherit two more untested
 * one-directional gaps (a seasonal or trend check classifying a drop in
 * errors as anomalous would be exactly the false-positive risk this signal
 * exists to avoid). Check A alone, against this install's own
 * (hour-of-day, day-of-week) history, is what PRD §6.1 actually asks for.
 *
 * ONE-DIRECTIONAL (EL1). classify() never returns MILD_DROP/SEVERE_DROP:
 * fewer errors than the historical median is always NORMAL. Must be
 * explicit and tested, not inherited -- this is the entire reason this
 * class does not simply call DispersionEvaluator.
 *
 * BELOW-FLOOR BURST FALLBACK SHIPS IN SHADOW MODE (EL2/EL7). A quiet or new
 * install has no established median to compare against, so
 * BURST_THRESHOLD_N is a flat guessed number applied to every such install
 * -- unlike Check A, it has no per-install self-calibration. Per the
 * explicit product decision recorded here: this path is evaluated and
 * recorded (the rollup table keeps real counts regardless), but forced to
 * NORMAL on the wire until real install data justifies a value for N. Only
 * SHADOW_MODE_BURST_ALERTING flips this on, deliberately a single constant
 * so turning it on later is a one-line, reviewable change.
 */
class Evaluator
{
    public const EVENT_TYPE = 'application_errors';
    public const RULESET_VERSION = '1.0.0';

    /** Matches RateSignal\DispersionEvaluator's own constants -- see that class for the full rationale. */
    private const BASELINE_WEEKS = 4;
    private const MIN_HISTORICAL_SAMPLES = 3;
    private const Z_ANOMALY_THRESHOLD = 3.5;
    private const Z_SEVERE_THRESHOLD = 7.0;
    private const MAD_CONTINUITY_FLOOR = 0.5;
    private const VOLUME_FLOOR = 5;

    /**
     * Below VOLUME_FLOOR, this many or more logged errors in a single hour
     * would be SEVERE_SPIKE. UNCALIBRATED (PRD Q1: "needs the widest
     * data-gathering of any threshold proposed so far") -- deliberately
     * never alerts while SHADOW_MODE_BURST_ALERTING is false. Loosely
     * anchored to AdminAuthFailure::SEVERE_FAILURE_THRESHOLD as a
     * same-order-of-magnitude starting point for a sparse, install-scoped
     * hourly count, not derived from any real application_errors data --
     * there isn't any yet.
     */
    private const BURST_THRESHOLD_N = 25;

    /**
     * Ships false. The dispersion path above is self-calibrating per
     * install and alerts live regardless of this flag; only the below-floor
     * burst fallback is gated by it. Flip to true in a follow-up once real
     * install data (gathered via the rollup table, which records real
     * counts either way) justifies BURST_THRESHOLD_N's value.
     */
    private const SHADOW_MODE_BURST_ALERTING = false;

    /**
     * @param InstallEventCounterRepository $eventCounterRepository reads the live hourly counter Handler increments
     * @param InstallRollupRepository $rollupRepository the long-retention history Check A classifies against
     * @param HealthStateRepository $repository reused: keyed by event_type alone, which fits an install-scoped signal
     * @param TwoEvaluationDebounce $debounce
     */
    public function __construct(
        private readonly InstallEventCounterRepository $eventCounterRepository,
        private readonly InstallRollupRepository $rollupRepository,
        private readonly HealthStateRepository $repository,
        private readonly TwoEvaluationDebounce $debounce,
    ) {
    }

    /**
     * Runs one debounce tick and returns the report to submit.
     *
     * @param \DateTimeImmutable $evaluatedHour top-of-hour instant of the completed hour being evaluated
     * @param \DateTimeImmutable $evaluatedAt wall-clock instant this evaluation ran
     * @return MetricReport
     */
    public function evaluate(\DateTimeImmutable $evaluatedHour, \DateTimeImmutable $evaluatedAt): MetricReport
    {
        $observedCount = $this->eventCounterRepository->countFor(Handler::EVENT_NAME, $evaluatedHour);

        // Recorded regardless of classification outcome: this is the
        // dataset a future review reads to calibrate BURST_THRESHOLD_N
        // (PRD Q1), and Check A's own baseline for future hours.
        $this->rollupRepository->recordHourlyCount(Handler::EVENT_NAME, $evaluatedHour, $observedCount);

        $state = $this->repository->get(self::EVENT_TYPE);
        $rawStatus = $this->rawStatus($observedCount, $evaluatedHour);

        // warmsUp: true -- unlike admin_auth_failure's fixed threshold, Check
        // A needs real history before its verdict means anything (EL7).
        $decision = $this->debounce->decide($rawStatus, $state->confirmedStatus, $state->pendingStatus, warmsUp: true);

        $this->repository->save(new HealthState(
            eventType: self::EVENT_TYPE,
            lastSuccessAt: null,
            lastFailureAt: null,
            pendingStatus: $decision->nextPendingStatus,
            confirmedStatus: $decision->nextConfirmedStatus,
            sequenceNumber: $state->sequenceNumber + 1,
            lastReportedReason: $decision->reportReason,
        ));

        return new MetricReport(
            storeViewCode: null,
            eventType: self::EVENT_TYPE,
            status: $decision->reportStatus,
            sequenceNumber: $state->sequenceNumber,
            evaluatedAt: $evaluatedAt,
            reason: $decision->reportReason,
            rulesetVersion: self::RULESET_VERSION,
        );
    }

    /**
     * Check A, or the shadow-mode burst fallback below the volume floor.
     *
     * @param int $observedCount
     * @param \DateTimeImmutable $evaluatedHour top-of-hour instant of the completed hour being evaluated
     * @return SignalStatus
     */
    private function rawStatus(int $observedCount, \DateTimeImmutable $evaluatedHour): SignalStatus
    {
        $samples = $this->historicalSamples($evaluatedHour);

        // Below MIN_HISTORICAL_SAMPLES there is not yet enough of this
        // bucket's own history to know whether its median even clears
        // VOLUME_FLOOR, so neither path below is trustworthy yet (EL7).
        // Once there are enough samples, this signal never returns here
        // again: either the median clears the floor (Check A, live below)
        // or it does not (burst fallback, live but shadow-gated below) --
        // a quiet hour past this point is a real NORMAL, not missing data.
        if (count($samples) < self::MIN_HISTORICAL_SAMPLES) {
            return SignalStatus::InsufficientData;
        }

        $values = array_map(static fn (HourlyCountSample $sample): int => $sample->count, $samples);
        $median = $this->median($values);

        if ($median >= self::VOLUME_FLOOR) {
            $mad = max($this->medianAbsoluteDeviation($values, $median), self::MAD_CONTINUITY_FLOOR);
            $modifiedZ = 0.6745 * ($observedCount - $median) / $mad;

            return $this->classify($modifiedZ);
        }

        $wouldFire = $observedCount >= self::BURST_THRESHOLD_N;

        return (self::SHADOW_MODE_BURST_ALERTING && $wouldFire) ? SignalStatus::SevereSpike : SignalStatus::Normal;
    }

    /**
     * This bucket's history, excluding the evaluated hour's own row so the
     * value being classified can't pollute its own baseline -- mirrors
     * RateSignal\DispersionEvaluator::historicalSamples().
     *
     * @param \DateTimeImmutable $evaluatedHour
     * @return HourlyCountSample[]
     */
    private function historicalSamples(\DateTimeImmutable $evaluatedHour): array
    {
        $samples = $this->rollupRepository->hourlyCountsForBucket(
            Handler::EVENT_NAME,
            (int) $evaluatedHour->format('N'),
            (int) $evaluatedHour->format('G'),
            self::BASELINE_WEEKS,
            $evaluatedHour
        );

        $evaluatedHourBucket = $evaluatedHour->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:00:00');
        $isNotTheEvaluatedHour = static fn (HourlyCountSample $sample): bool
            => $sample->bucket->format('Y-m-d H:00:00') !== $evaluatedHourBucket;

        return array_values(array_filter($samples, $isNotTheEvaluatedHour));
    }

    /**
     * Maps a modified z-score to a status -- one-directional (EL1), unlike
     * RateSignal\DispersionEvaluator::classify(): a negative z (fewer errors
     * than the historical median) is always NORMAL, never MILD_DROP/
     * SEVERE_DROP. Fewer errors is never bad, so this must not fire on an
     * install's error rate improving.
     *
     * @param float $modifiedZ
     * @return SignalStatus
     */
    private function classify(float $modifiedZ): SignalStatus
    {
        // Covers both "modifiedZ <= 0" (fewer errors, never anomalous) and a
        // small positive rise that hasn't cleared the anomaly threshold yet
        // -- no abs() and no separate negative-direction branch, since only
        // the positive direction carries meaning here at all.
        if ($modifiedZ < self::Z_ANOMALY_THRESHOLD) {
            return SignalStatus::Normal;
        }

        return $modifiedZ >= self::Z_SEVERE_THRESHOLD ? SignalStatus::SevereSpike : SignalStatus::MildSpike;
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
}
