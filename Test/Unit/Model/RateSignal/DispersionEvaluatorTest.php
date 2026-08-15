<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\RateSignal;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\RateSignal\DispersionEvaluator;
use Watchtower\Connector\Model\RateSignal\DispersionState;
use Watchtower\Connector\Model\RateSignal\DispersionStateRepository;
use Watchtower\Connector\Model\Rollup\HourlyCountSample;
use Watchtower\Connector\Model\Rollup\RollupRepository;

/**
 * Regression coverage for Check A (the dispersion-based bound) and its
 * reuse of the two-evaluation debounce state machine.
 * Classification tests deliberately pin confirmedStatus to
 * the expected raw status so evaluate() takes its "no change" branch,
 * isolating the median/MAD/modified-z classification itself from the
 * debounce transition logic, which gets its own dedicated tests mirroring
 * CronHealth\Evaluator's own (see EvaluatorTest.php).
 */
class DispersionEvaluatorTest extends TestCase
{
    private const NOW_STRING = '2026-08-13T15:00:00+00:00';
    private const STORE_VIEW_ID = 7;
    private const STORE_VIEW_CODE = 'default';
    private const CATEGORY = 'checkout';

    /**
     * Baseline dataset used across the classification tests: historical
     * counts 98/100/101/102 at the same hour-of-day/day-of-week over the
     * prior 4 weeks give median=100.5, MAD=1.0, so modified_z = 0.6745 *
     * (value - 100.5) / 1.0 -- chosen so each status boundary lands on a
     * clean integer observed count.
     */
    private const BASELINE_COUNTS_BY_WEEKS_AGO = [4 => 100, 3 => 102, 2 => 98, 1 => 101];

    public function testValueNearHistoricalMedianReportsNormal(): void
    {
        $report = $this->evaluateWithConfirmedStatus(
            observedCount: 100,
            confirmed: SignalStatus::Normal,
            samples: $this->baselineSamples()
        );

        self::assertSame(SignalStatus::Normal, $report->status);
    }

    public function testValueSeveralMadsBelowMedianReportsMildDrop(): void
    {
        // modified_z = 0.6745 * (95 - 100.5) / 1.0 = -3.71 (magnitude in [3.5, 7.0)).
        $report = $this->evaluateWithConfirmedStatus(
            observedCount: 95,
            confirmed: SignalStatus::MildDrop,
            samples: $this->baselineSamples()
        );

        self::assertSame(SignalStatus::MildDrop, $report->status);
    }

    public function testValueManyMadsBelowMedianReportsSevereDrop(): void
    {
        // modified_z = 0.6745 * (85 - 100.5) / 1.0 = -10.45 (magnitude >= 7.0).
        $report = $this->evaluateWithConfirmedStatus(
            observedCount: 85,
            confirmed: SignalStatus::SevereDrop,
            samples: $this->baselineSamples()
        );

        self::assertSame(SignalStatus::SevereDrop, $report->status);
    }

    public function testValueSeveralMadsAboveMedianReportsMildSpike(): void
    {
        // modified_z = 0.6745 * (106 - 100.5) / 1.0 = 3.71 (magnitude in [3.5, 7.0)).
        $report = $this->evaluateWithConfirmedStatus(
            observedCount: 106,
            confirmed: SignalStatus::MildSpike,
            samples: $this->baselineSamples()
        );

        self::assertSame(SignalStatus::MildSpike, $report->status);
    }

    public function testValueManyMadsAboveMedianReportsSevereSpike(): void
    {
        // modified_z = 0.6745 * (116 - 100.5) / 1.0 = 10.45 (magnitude >= 7.0).
        $report = $this->evaluateWithConfirmedStatus(
            observedCount: 116,
            confirmed: SignalStatus::SevereSpike,
            samples: $this->baselineSamples()
        );

        self::assertSame(SignalStatus::SevereSpike, $report->status);
    }

    public function testInsufficientDataWhenHistoricalSampleCountIsBelowTheDocumentedMinimum(): void
    {
        // Only 2 historical samples; MIN_HISTORICAL_SAMPLES is 3.
        $samples = [
            $this->sample(weeksAgo: 2, count: 100),
            $this->sample(weeksAgo: 1, count: 101),
        ];

        $report = $this->evaluateWithConfirmedStatus(
            observedCount: 100,
            confirmed: SignalStatus::InsufficientData,
            samples: $samples
        );

        self::assertSame(SignalStatus::InsufficientData, $report->status);
    }

    /**
     * A bucket with zero historical variance (e.g. always exactly 10 orders
     * in this hour) must not divide by a zero MAD. An earlier version of
     * this class treated a bare MAD of 0 as "zero tolerance for any
     * deviation" (matching exactly was Normal, anything else was a
     * maximal-confidence Severe anomaly in its direction); these four
     * tests lock the corrected behavior (MAD_CONTINUITY_FLOOR) via the
     * same genuinely-constant [10,10,10,10] history used before the fix,
     * with expectations recomputed against the floor instead of the old
     * always-Severe branch, plus one still confirming a genuinely large
     * deviation is correctly flagged Severe even with the floor applied.
     */
    public function testMadOfZeroMatchingTheConstantValueReportsNormal(): void
    {
        $report = $this->evaluateWithConfirmedStatus(
            observedCount: 10,
            confirmed: SignalStatus::Normal,
            samples: $this->constantSamples(10)
        );

        self::assertSame(SignalStatus::Normal, $report->status);
    }

    public function testMadOfZeroSmallDeviationAboveReportsMildSpikeNotSevere(): void
    {
        // z = 0.6745 * (13 - 10) / MAD_CONTINUITY_FLOOR(0.5) = 4.047 (magnitude in [3.5, 7.0)).
        $report = $this->evaluateWithConfirmedStatus(
            observedCount: 13,
            confirmed: SignalStatus::MildSpike,
            samples: $this->constantSamples(10)
        );

        self::assertSame(SignalStatus::MildSpike, $report->status);
    }

    public function testMadOfZeroSmallDeviationBelowReportsMildDropNotSevere(): void
    {
        // z = 0.6745 * (7 - 10) / 0.5 = -4.047 (magnitude in [3.5, 7.0)).
        $report = $this->evaluateWithConfirmedStatus(
            observedCount: 7,
            confirmed: SignalStatus::MildDrop,
            samples: $this->constantSamples(10)
        );

        self::assertSame(SignalStatus::MildDrop, $report->status);
    }

    public function testMadOfZeroLargeDeviationStillReportsSevere(): void
    {
        // z = 0.6745 * (20 - 10) / 0.5 = 13.49 -- the floor damps small
        // deviations without neutering real anomaly detection.
        $report = $this->evaluateWithConfirmedStatus(
            observedCount: 20,
            confirmed: SignalStatus::SevereSpike,
            samples: $this->constantSamples(10)
        );

        self::assertSame(SignalStatus::SevereSpike, $report->status);
    }

    /**
     * A MAD of 0.0 does not mean zero real variance, it means at least
     * half the samples share the median -- the ORDINARY case for a
     * low-traffic bucket with small integer counts, not a rare edge case.
     * Without a continuity floor, history=[0,0,1,0] (median=0, mad=0)
     * classifies an observed value of 1 -- a value that already APPEARS
     * in its own history -- as SevereSpike, and a history of mostly-5s
     * with one 4 classifies as SevereDrop for being one order fewer than
     * usual. Both must be Normal.
     */
    /**
     * The counts are deliberately shifted up so the median sits at the
     * VOLUME_FLOOR boundary (`>= VOLUME_FLOOR` keeps this on Check A).
     * The natural numbers for this scenario -- history=[0,0,1,0],
     * observed=1 -- are a genuinely low-volume bucket that rate-gating
     * routes to the inter-arrival path instead (see rawStatus()'s own
     * docblock), which the low-volume tests below cover separately.
     */
    public function testMadOfZeroWithAnOccasionalDeviationAlreadyInItsOwnHistoryIsNormalNotSevereSpike(): void
    {
        $samples = [
            $this->sample(weeksAgo: 4, count: 5),
            $this->sample(weeksAgo: 3, count: 5),
            $this->sample(weeksAgo: 2, count: 6),
            $this->sample(weeksAgo: 1, count: 5),
        ];

        // z = 0.6745 * (6 - 5) / 0.5 = 1.349 (below the 3.5 anomaly threshold).
        $report = $this->evaluateWithConfirmedStatus(
            observedCount: 6,
            confirmed: SignalStatus::Normal,
            samples: $samples
        );

        self::assertSame(SignalStatus::Normal, $report->status);
    }

    public function testMadOfZeroOneUnitBelowAMostlyConstantHistoryIsNormalNotSevereDrop(): void
    {
        $samples = [
            $this->sample(weeksAgo: 4, count: 5),
            $this->sample(weeksAgo: 3, count: 5),
            $this->sample(weeksAgo: 2, count: 5),
            $this->sample(weeksAgo: 1, count: 5),
        ];

        // z = 0.6745 * (4 - 5) / 0.5 = -1.349 (below the 3.5 anomaly threshold).
        $report = $this->evaluateWithConfirmedStatus(
            observedCount: 4,
            confirmed: SignalStatus::Normal,
            samples: $samples
        );

        self::assertSame(SignalStatus::Normal, $report->status);
    }

    /**
     * @return HourlyCountSample[]
     */
    private function constantSamples(int $count): array
    {
        return [
            $this->sample(weeksAgo: 4, count: $count),
            $this->sample(weeksAgo: 3, count: $count),
            $this->sample(weeksAgo: 2, count: $count),
            $this->sample(weeksAgo: 1, count: $count),
        ];
    }

    /**
     * hourlyCountsForBucket's own upper bound is inclusive of the evaluated
     * hour, so that hour's own row must be excluded from its own baseline,
     * or the value being classified would pollute the statistic used to
     * classify it.
     */
    public function testTheEvaluatedHoursOwnRowIsExcludedFromItsOwnBaseline(): void
    {
        $samples = $this->baselineSamples();
        // A same-top-of-hour row with a wildly different count; if this were
        // NOT excluded, it would shift the median/MAD enough to change the
        // classification of observedCount=100 away from Normal.
        $samples[] = new HourlyCountSample(new \DateTimeImmutable(self::NOW_STRING), 9999);

        $report = $this->evaluateWithConfirmedStatus(
            observedCount: 100,
            confirmed: SignalStatus::Normal,
            samples: $samples
        );

        self::assertSame(SignalStatus::Normal, $report->status);
    }

    public function testFirstEvaluationEverReportsInsufficientDataAsATransition(): void
    {
        $savedState = null;
        $stateRepository = $this->createMock(DispersionStateRepository::class);
        $stateRepository->method('get')->willReturn($this->freshState());
        $stateRepository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $rollupRepository = $this->createStub(RollupRepository::class);
        $rollupRepository->method('hourlyCountsForBucket')->willReturn($this->baselineSamples());

        $report = (new DispersionEvaluator($rollupRepository, $stateRepository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::CATEGORY,
            100,
            $this->now(),
            $this->now()
        );

        self::assertSame(SignalStatus::InsufficientData, $report->status);
        self::assertSame(ReportReason::Transition, $report->reason);
        self::assertSame(1, $report->sequenceNumber);
        self::assertSame(self::STORE_VIEW_CODE, $report->storeViewCode);
        self::assertSame(self::CATEGORY, $report->eventType);

        self::assertSame(SignalStatus::InsufficientData, $savedState->confirmedStatus);
        self::assertNull($savedState->pendingStatus);
        self::assertSame(2, $savedState->sequenceNumber);
    }

    public function testFirstTickOfADifferentRawStatusHeartbeatsTheOldStatusAndSetsPending(): void
    {
        $state = $this->stateWith(confirmed: SignalStatus::Normal, pending: null, sequence: 5);

        $savedState = null;
        $stateRepository = $this->createMock(DispersionStateRepository::class);
        $stateRepository->method('get')->willReturn($state);
        $stateRepository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        // observedCount=85 against the baseline dataset classifies as SevereDrop, differing from confirmed Normal.
        $rollupRepository = $this->createStub(RollupRepository::class);
        $rollupRepository->method('hourlyCountsForBucket')->willReturn($this->baselineSamples());

        $report = (new DispersionEvaluator($rollupRepository, $stateRepository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::CATEGORY,
            85,
            $this->now(),
            $this->now()
        );

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertSame(5, $report->sequenceNumber);

        self::assertSame(SignalStatus::Normal, $savedState->confirmedStatus);
        self::assertSame(SignalStatus::SevereDrop, $savedState->pendingStatus);
        self::assertSame(6, $savedState->sequenceNumber);
    }

    public function testSecondConsecutiveTickOfADifferingStatusConfirmsUsingTheCurrentRawStatus(): void
    {
        // Pending was set to MildDrop by a prior tick, but THIS tick's raw
        // (observedCount=85 -> SevereDrop) differs from it; must still
        // confirm on the current raw value, not require it to match pending
        // exactly (the same real bug CronHealth\Evaluator's debounce fixed).
        $state = $this->stateWith(confirmed: SignalStatus::Normal, pending: SignalStatus::MildDrop, sequence: 6);

        $savedState = null;
        $stateRepository = $this->createMock(DispersionStateRepository::class);
        $stateRepository->method('get')->willReturn($state);
        $stateRepository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $rollupRepository = $this->createStub(RollupRepository::class);
        $rollupRepository->method('hourlyCountsForBucket')->willReturn($this->baselineSamples());

        $report = (new DispersionEvaluator($rollupRepository, $stateRepository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::CATEGORY,
            85,
            $this->now(),
            $this->now()
        );

        self::assertSame(SignalStatus::SevereDrop, $report->status);
        self::assertSame(ReportReason::Transition, $report->reason);
        self::assertSame(6, $report->sequenceNumber);

        self::assertSame(SignalStatus::SevereDrop, $savedState->confirmedStatus);
        self::assertNull($savedState->pendingStatus);
        self::assertSame(7, $savedState->sequenceNumber);
    }

    public function testUnchangedStatusIsReportedAsAHeartbeatAndSequenceStillAdvances(): void
    {
        $state = $this->stateWith(confirmed: SignalStatus::Normal, pending: null, sequence: 9);

        $savedState = null;
        $stateRepository = $this->createMock(DispersionStateRepository::class);
        $stateRepository->method('get')->willReturn($state);
        $stateRepository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $rollupRepository = $this->createStub(RollupRepository::class);
        $rollupRepository->method('hourlyCountsForBucket')->willReturn($this->baselineSamples());

        $report = (new DispersionEvaluator($rollupRepository, $stateRepository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::CATEGORY,
            100,
            $this->now(),
            $this->now()
        );

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertSame(9, $report->sequenceNumber);

        self::assertNull($savedState->pendingStatus);
        self::assertSame(
            10,
            $savedState->sequenceNumber,
            'Sequence must advance on a heartbeat too, not only on a transition.'
        );
    }

    /**
     * Gating the mode switch on THIS HOUR's observedCount directly would
     * wave a normally ~100/hour checkout signal that recorded only 2
     * orders in one hour -- a real, severe drop -- through as trivially
     * Normal instead of comparing it against its own median/MAD baseline.
     * Mode selection therefore depends on the signal's own
     * historical median (still well above VOLUME_FLOOR here, unmoved by
     * one bad hour), not on observedCount, so this must still run Check A
     * and correctly report the drop.
     */
    public function testAHighVolumeSignalThatDipsBelowTheFloorInOneHourStillUsesCheckANotATrivialNormal(): void
    {
        $stateRepository = $this->createStub(DispersionStateRepository::class);
        $stateRepository->method('get')->willReturn($this->stateWith(SignalStatus::SevereDrop, null, 3));
        $now = $this->now();

        $rollupRepository = $this->createMock(RollupRepository::class);
        $rollupRepository->expects(self::atLeastOnce())
            ->method('hourlyCountsForBucket')
            ->willReturnCallback($this->samplesOnlyForCurrentWindow($this->baselineSamples(), $now));
        $rollupRepository->expects(self::never())->method('allHourlyCountsInWindow');
        $rollupRepository->method('dailyCountsInWindow')->willReturn([]);

        $report = (new DispersionEvaluator($rollupRepository, $stateRepository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::CATEGORY,
            2,
            $now,
            $now
        );

        // observedCount=2 against the 98/100/101/102 baseline (median 100.5,
        // MAD 1.0) is a severe drop by the same modified-z formula every
        // other test in this file uses -- proving the dispersion path, not
        // a trivial below-floor Normal, actually ran.
        self::assertSame(SignalStatus::SevereDrop, $report->status);
    }

    /**
     * A signal with no baseline at all -- fewer than MIN_HISTORICAL_SAMPLES
     * on both Check A's own bucket AND (consequently) the inter-arrival gap
     * distribution -- must report InsufficientData even when this hour
     * happens to record a real event. Checking observedCount > 0 BEFORE
     * the sufficiency check inside interArrivalRawStatus() would make a
     * brand-new signal with literally zero history report a
     * false-assurance Normal the moment it recorded one event, leaving
     * INSUFFICIENT_DATA condition 1 unreachable for any nonzero count.
     * Condition 1 must always win.
     */
    public function testANonzeroCountOnASignalWithNoHistoryAtAllStillReportsInsufficientDataNotNormal(): void
    {
        $stateRepository = $this->createStub(DispersionStateRepository::class);
        $stateRepository->method('get')->willReturn($this->stateWith(SignalStatus::InsufficientData, null, 3));

        $rollupRepository = $this->createMock(RollupRepository::class);
        $rollupRepository->expects(self::once())->method('hourlyCountsForBucket')->willReturn([]);
        // Sufficiency must still be checked -- the query cannot be skipped
        // just because observedCount is nonzero, or condition 1 would be
        // unreachable again.
        $rollupRepository->expects(self::once())->method('allHourlyCountsInWindow')->willReturn([]);

        $report = (new DispersionEvaluator($rollupRepository, $stateRepository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::CATEGORY,
            2,
            $this->now(),
            $this->now()
        );

        self::assertSame(SignalStatus::InsufficientData, $report->status);
    }

    /**
     * The genuine below-floor-median trigger for the mode switch, as
     * opposed to the short-history fallthrough every other low-volume test
     * in this file exercises: a real, sufficient (>= MIN_HISTORICAL_SAMPLES)
     * Check A bucket history whose OWN median is below VOLUME_FLOOR.
     * Without this test, deleting the `$median >= self::VOLUME_FLOOR`
     * condition from rawStatus() entirely leaves every other test in this
     * file green.
     */
    public function testABelowFloorMedianWithSufficientHistoryTriggersTheInterArrivalPathAndReportsSevereDrop(): void
    {
        $stateRepository = $this->createStub(DispersionStateRepository::class);
        $stateRepository->method('get')->willReturn($this->stateWith(SignalStatus::SevereDrop, null, 3));

        $rollupRepository = $this->createMock(RollupRepository::class);
        // Median of [2,2,3,3] is 2.5, below VOLUME_FLOOR (5), with 4 samples
        // -- comfortably at/above MIN_HISTORICAL_SAMPLES, so this triggers
        // via the median gate, not a short-history fallthrough.
        $rollupRepository->expects(self::once())->method('hourlyCountsForBucket')->willReturn([
            $this->sample(weeksAgo: 4, count: 2),
            $this->sample(weeksAgo: 3, count: 2),
            $this->sample(weeksAgo: 2, count: 3),
            $this->sample(weeksAgo: 1, count: 3),
        ]);
        $rollupRepository->expects(self::once())->method('allHourlyCountsInWindow')->willReturn($this->lowVolumeSeries(
            currentGapHours: 9,
            bucketGapHoursByWeeksAgo: [4 => 1, 3 => 1, 2 => 1, 1 => 10],
            anchorCount: 100,
        ));

        $report = (new DispersionEvaluator($rollupRepository, $stateRepository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::CATEGORY,
            0,
            $this->now(),
            $this->now()
        );

        // Same distribution/threshold arithmetic as
        // testAZeroCountWithAGapExceedingTheThresholdReportsSevereDrop:
        // [1,1,1,10] at the 0.95 percentile = 8.65; gap 9 > 8.65.
        self::assertSame(SignalStatus::SevereDrop, $report->status);
    }

    /**
     * Once a signal's own historical median genuinely IS below the volume
     * floor with a SUFFICIENT gap distribution to calibrate against (unlike
     * the InsufficientData test above), a nonzero observedCount below
     * LOW_VOLUME_SPIKE_MIN_COUNT is still Normal regardless of the gap
     * distribution -- proving lowVolumeSpikeStatus()'s absolute floor
     * engages AFTER sufficiency is established, not instead of it. (Prior
     * to the spike-detection fix, ANY nonzero count short-circuited to
     * Normal here; this fixture's bucket rows are all 0, so a genuinely
     * anomalous burst would still correctly be caught by the tests below --
     * this one only proves an ordinary small count isn't one.)
     */
    public function testWithinAConfirmedLowVolumeSignalANonzeroObservedCountIsTriviallyNormalOnceSufficient(): void
    {
        $stateRepository = $this->createStub(DispersionStateRepository::class);
        $stateRepository->method('get')->willReturn($this->stateWith(SignalStatus::Normal, null, 3));

        $rollupRepository = $this->createMock(RollupRepository::class);
        $rollupRepository->expects(self::once())->method('hourlyCountsForBucket')->willReturn([
            $this->sample(weeksAgo: 4, count: 2),
            $this->sample(weeksAgo: 3, count: 2),
            $this->sample(weeksAgo: 2, count: 3),
            $this->sample(weeksAgo: 1, count: 3),
        ]);
        // A distribution that WOULD report SevereDrop for a currentGap of 9
        // (same fixture as the SevereDrop test above) -- proving Normal
        // here comes from the spike-floor short-circuit, not from an
        // empty/insufficient distribution masking the real comparison.
        $rollupRepository->expects(self::once())->method('allHourlyCountsInWindow')->willReturn($this->lowVolumeSeries(
            currentGapHours: 9,
            bucketGapHoursByWeeksAgo: [4 => 1, 3 => 1, 2 => 1, 1 => 10],
            anchorCount: 100,
        ));

        $report = (new DispersionEvaluator($rollupRepository, $stateRepository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::CATEGORY,
            2,
            $this->now(),
            $this->now()
        );

        self::assertSame(SignalStatus::Normal, $report->status);
    }

    /**
     * Confirmed against a real low-volume store's actual order history:
     * Low-Volume Signal Mode used to treat any nonzero observedCount as
     * trivially Normal, meaning a real burst (a flash sale, a bot storm) on
     * a thin signal could never be flagged -- of 1008 real hourly
     * evaluations replayed, every single one that wasn't Normal was
     * SevereDrop, never a spike, no matter how large the count. This locks
     * the fix: a count far above the bucket's own historical maximum is now
     * a SevereSpike.
     */
    public function testALowVolumeBurstFarAboveTheHistoricalBucketMaximumReportsSevereSpike(): void
    {
        // Pinned to the expected raw status, not Normal, so evaluate() takes
        // the "no change" branch -- see this file's own docblock at the top
        // for why a mismatched confirmed status here would instead exercise
        // the debounce transition logic (reporting the OLD confirmed status
        // for one tick) rather than isolating the classification itself.
        $stateRepository = $this->createStub(DispersionStateRepository::class);
        $stateRepository->method('get')->willReturn($this->stateWith(SignalStatus::SevereSpike, null, 3));

        $rollupRepository = $this->createMock(RollupRepository::class);
        $rollupRepository->expects(self::once())->method('hourlyCountsForBucket')->willReturn([
            $this->sample(weeksAgo: 4, count: 2),
            $this->sample(weeksAgo: 3, count: 2),
            $this->sample(weeksAgo: 2, count: 3),
            $this->sample(weeksAgo: 1, count: 3),
        ]);
        // Bucket rows carry 0/1 historically (max=1); 20 is both >=
        // LOW_VOLUME_SPIKE_MIN_COUNT (5) and >= 1 * LOW_VOLUME_SEVERE_SPIKE_MULTIPLIER (4).
        $rollupRepository->expects(self::once())->method('allHourlyCountsInWindow')->willReturn($this->lowVolumeSeries(
            currentGapHours: 1,
            bucketGapHoursByWeeksAgo: [4 => 1, 3 => 1, 2 => 1, 1 => 1],
            anchorCount: 100,
            bucketCountsByWeeksAgo: [4 => 0, 3 => 1, 2 => 0, 1 => 1],
        ));

        $report = (new DispersionEvaluator($rollupRepository, $stateRepository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::CATEGORY,
            20,
            $this->now(),
            $this->now()
        );

        self::assertSame(SignalStatus::SevereSpike, $report->status);
    }

    /**
     * At or above LOW_VOLUME_SPIKE_MIN_COUNT is necessary but not
     * sufficient: the count must also clear
     * LOW_VOLUME_SEVERE_SPIKE_MULTIPLIER times the historical bucket
     * maximum, so a signal whose own normal rhythm already reaches several
     * events an hour isn't flagged for one more.
     */
    public function testALowVolumeCountAboveTheFloorButBelowTheMultiplierIsStillNormal(): void
    {
        $stateRepository = $this->createStub(DispersionStateRepository::class);
        $stateRepository->method('get')->willReturn($this->stateWith(SignalStatus::Normal, null, 3));

        $rollupRepository = $this->createMock(RollupRepository::class);
        $rollupRepository->expects(self::once())->method('hourlyCountsForBucket')->willReturn([
            $this->sample(weeksAgo: 4, count: 2),
            $this->sample(weeksAgo: 3, count: 2),
            $this->sample(weeksAgo: 2, count: 3),
            $this->sample(weeksAgo: 1, count: 3),
        ]);
        // Bucket rows carry a historical max of 2; observedCount=6 clears the
        // absolute floor (5) but not 2 * 4 = 8, so this must stay Normal.
        $rollupRepository->expects(self::once())->method('allHourlyCountsInWindow')->willReturn($this->lowVolumeSeries(
            currentGapHours: 1,
            bucketGapHoursByWeeksAgo: [4 => 1, 3 => 1, 2 => 1, 1 => 1],
            anchorCount: 100,
            bucketCountsByWeeksAgo: [4 => 0, 3 => 2, 2 => 0, 1 => 1],
        ));

        $report = (new DispersionEvaluator($rollupRepository, $stateRepository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::CATEGORY,
            6,
            $this->now(),
            $this->now()
        );

        self::assertSame(SignalStatus::Normal, $report->status);
    }

    /**
     * Check A's own BASELINE_WEEKS window can never offer more than 3-4
     * bucket-conditioned gap samples (a 4-week window over a 7-day cycle),
     * which is too few for a stable percentile -- confirmed against a real
     * store's history, where exactly this produced thresholds barely above
     * ordinary quiet periods. interArrivalRawStatus() must therefore query
     * its own wider LOW_VOLUME_LOOKBACK_WEEKS window, not reuse Check A's.
     */
    public function testLowVolumeModeQueriesItsOwnWiderLookbackWindowNotChecksAsBaselineWindow(): void
    {
        $stateRepository = $this->createStub(DispersionStateRepository::class);
        $stateRepository->method('get')->willReturn($this->stateWith(SignalStatus::Normal, null, 3));

        $rollupRepository = $this->createMock(RollupRepository::class);
        $rollupRepository->method('hourlyCountsForBucket')->willReturn([
            $this->sample(weeksAgo: 4, count: 2),
            $this->sample(weeksAgo: 3, count: 2),
            $this->sample(weeksAgo: 2, count: 3),
            $this->sample(weeksAgo: 1, count: 3),
        ]);
        // 12 is LOW_VOLUME_LOOKBACK_WEEKS; 4 (Check A's own BASELINE_WEEKS)
        // must NOT be what interArrivalRawStatus() actually requests.
        $rollupRepository->expects(self::once())
            ->method('allHourlyCountsInWindow')
            ->with(self::STORE_VIEW_ID, self::CATEGORY, 12, self::anything())
            ->willReturn($this->lowVolumeSeries(
                currentGapHours: 5,
                bucketGapHoursByWeeksAgo: [4 => 1, 3 => 1, 2 => 1, 1 => 10],
                anchorCount: 100,
            ));

        $report = (new DispersionEvaluator($rollupRepository, $stateRepository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::CATEGORY,
            0,
            $this->now(),
            $this->now()
        );

        self::assertSame(SignalStatus::Normal, $report->status);
    }

    public function testAZeroCountWithAGapExceedingTheThresholdReportsSevereDrop(): void
    {
        $report = $this->evaluateLowVolumeWithConfirmedStatus(
            currentGapHours: 9,
            bucketGapHoursByWeeksAgo: [4 => 1, 3 => 1, 2 => 1, 1 => 10],
            anchorCount: 100, // high volume: >= MIN_VIABLE_DAILY_VOLUME, so the 0.95 default percentile applies.
            confirmed: SignalStatus::SevereDrop
        );

        // distribution [1,1,1,10], 0.95 percentile = 8.65; gap 9 > 8.65.
        self::assertSame(SignalStatus::SevereDrop, $report->status);
    }

    public function testAZeroCountWithAGapWithinTheThresholdReportsNormal(): void
    {
        $report = $this->evaluateLowVolumeWithConfirmedStatus(
            currentGapHours: 5,
            bucketGapHoursByWeeksAgo: [4 => 1, 3 => 1, 2 => 1, 1 => 10],
            anchorCount: 100,
            confirmed: SignalStatus::Normal
        );

        // distribution [1,1,1,10], 0.95 percentile = 8.65; gap 5 <= 8.65.
        self::assertSame(SignalStatus::Normal, $report->status);
    }

    /**
     * Same gap and same historical distribution as the SevereDrop test
     * above, but a low estimated daily volume (anchorCount=1, total 5
     * events over the 28-day window is well under
     * MIN_VIABLE_DAILY_VOLUME) -- proving the threshold VALUE itself
     * changed, not just coincidentally landing on the same classification.
     */
    public function testBelowMinimumViableDailyVolumeUsesTheQuieter99thPercentileNotThe95th(): void
    {
        $report = $this->evaluateLowVolumeWithConfirmedStatus(
            currentGapHours: 9,
            bucketGapHoursByWeeksAgo: [4 => 1, 3 => 1, 2 => 1, 1 => 10],
            anchorCount: 1, // low volume: < MIN_VIABLE_DAILY_VOLUME, so the quieter 0.99 percentile applies.
            confirmed: SignalStatus::Normal
        );

        // distribution [1,1,1,10], 0.99 percentile = 9.73; gap 9 <= 9.73 --
        // the same gap that reported SevereDrop under the 0.95 threshold.
        self::assertSame(SignalStatus::Normal, $report->status);
    }

    public function testInsufficientBucketSamplesWidensToTheStoreWideDistributionRatherThanUsingATooSmallSample(): void
    {
        // Only 2 weeks of same-bucket history (bucketGapDistribution has 2
        // samples, below MIN_HISTORICAL_SAMPLES=3); the store-wide
        // distribution built from the same series has 4 samples including
        // two large cross-week gaps, giving a threshold high enough that
        // gap=5 reports Normal. If the (too-small) bucket distribution
        // [1, 1] were used directly instead, its own 0.95 percentile is 1,
        // and gap=5 would incorrectly report SevereDrop -- so Normal here
        // is only reachable via the documented widen fallback, not a
        // shortcut that skipped straight to INSUFFICIENT_DATA either. This
        // proves the CODE PATH engages correctly; the two ~160+ hour
        // cross-week gaps only exist because lowVolumeSeries() is a sparse
        // fixture (real weeks of anchor/bucket-only rows with nothing in
        // between) -- a dense production series would never contain a gap
        // that large, so this test's specific threshold VALUE is not a
        // claim about realistic production magnitudes, only about which
        // distribution got used.
        $report = $this->evaluateLowVolumeWithConfirmedStatus(
            currentGapHours: 5,
            bucketGapHoursByWeeksAgo: [2 => 1, 1 => 1],
            anchorCount: 100,
            confirmed: SignalStatus::Normal
        );

        self::assertSame(SignalStatus::Normal, $report->status);
    }

    public function testInsufficientSamplesInBothDistributionsReportsInsufficientData(): void
    {
        // A single week of bucket history: bucketGapDistribution has only 1
        // sample, and this sparse a series only ever produces 2 store-wide
        // samples in total -- the series' own first (earliest) row never
        // contributes to either distribution because no gap is defined yet
        // at that point (InterArrivalGapCalculator's running gap starts
        // null and only becomes a real value once the first nonzero hour is
        // seen -- unrelated to the self-pollution exclusion, which is about
        // the EVALUATED hour's own row, not the series' earliest one). Both
        // distributions stay below MIN_HISTORICAL_SAMPLES (3) regardless.
        $report = $this->evaluateLowVolumeWithConfirmedStatus(
            currentGapHours: 5,
            bucketGapHoursByWeeksAgo: [1 => 1],
            anchorCount: 100,
            confirmed: SignalStatus::InsufficientData
        );

        self::assertSame(SignalStatus::InsufficientData, $report->status);
    }

    public function testLowVolumeModeReusesTheSameTwoEvaluationDebounceAsCheckA(): void
    {
        // confirmed=Normal, but this tick's raw inter-arrival status is
        // SevereDrop (gap 9 > the 8.65 threshold for this distribution) --
        // first differing tick must heartbeat the OLD status and set pending,
        // exactly as Check A's own debounce tests prove.
        $state = $this->stateWith(confirmed: SignalStatus::Normal, pending: null, sequence: 5);

        $savedState = null;
        $stateRepository = $this->createMock(DispersionStateRepository::class);
        $stateRepository->method('get')->willReturn($state);
        $stateRepository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $rollupRepository = $this->createStub(RollupRepository::class);
        $rollupRepository->method('allHourlyCountsInWindow')->willReturn($this->lowVolumeSeries(
            currentGapHours: 9,
            bucketGapHoursByWeeksAgo: [4 => 1, 3 => 1, 2 => 1, 1 => 10],
            anchorCount: 100,
        ));

        $report = (new DispersionEvaluator($rollupRepository, $stateRepository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::CATEGORY,
            0,
            $this->now(),
            $this->now()
        );

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertSame(SignalStatus::Normal, $savedState->confirmedStatus);
        self::assertSame(SignalStatus::SevereDrop, $savedState->pendingStatus);
    }

    /**
     * Builds a sparse hourly series: one nonzero "anchor" row plus a zero
     * "bucket" row for each prior Thursday-15:00 occurrence in
     * $bucketGapHoursByWeeksAgo (the gap from anchor to bucket controlled by
     * that value), plus one more anchor row this week and the evaluated
     * hour's own zero row (gap controlled by $currentGapHours).
     * InterArrivalGapCalculator only measures real elapsed time between
     * whichever rows are present, so a sparse series is a faithful, much
     * simpler fixture than a fully dense one.
     *
     * @param int $currentGapHours
     * @param array<int,int> $bucketGapHoursByWeeksAgo weeksAgo => gap hours before that week's bucket
     * @param int $anchorCount the nonzero count each anchor row carries -- also controls estimated daily volume
     * @param array<int,int> $bucketCountsByWeeksAgo weeksAgo => that week's own bucket-row count, for
     *     lowVolumeSpikeStatus()'s historical-maximum comparison; defaults to 0 for every week (the drop-path
     *     tests' fixture), unaffected by this parameter's addition
     * @return HourlyCountSample[]
     */
    private function lowVolumeSeries(
        int $currentGapHours,
        array $bucketGapHoursByWeeksAgo,
        int $anchorCount,
        array $bucketCountsByWeeksAgo = []
    ): array {
        $evaluatedHour = $this->now();
        $samples = [];

        foreach ($bucketGapHoursByWeeksAgo as $weeksAgo => $gapHours) {
            $bucketHour = $evaluatedHour->modify("-{$weeksAgo} weeks");
            $samples[] = new HourlyCountSample($bucketHour->modify("-{$gapHours} hours"), $anchorCount);
            $samples[] = new HourlyCountSample($bucketHour, $bucketCountsByWeeksAgo[$weeksAgo] ?? 0);
        }

        $samples[] = new HourlyCountSample($evaluatedHour->modify("-{$currentGapHours} hours"), $anchorCount);
        $samples[] = new HourlyCountSample($evaluatedHour, 0);

        usort($samples, static fn (HourlyCountSample $a, HourlyCountSample $b) => $a->bucket <=> $b->bucket);

        return $samples;
    }

    /**
     * Same "no change" branch isolation as evaluateWithConfirmedStatus(),
     * and the same reason -- see that helper's own docblock.
     *
     * @param int $currentGapHours
     * @param array<int,int> $bucketGapHoursByWeeksAgo
     * @param int $anchorCount
     * @param SignalStatus $confirmed
     * @return \Watchtower\Connector\Model\Api\MetricReport
     */
    private function evaluateLowVolumeWithConfirmedStatus(
        int $currentGapHours,
        array $bucketGapHoursByWeeksAgo,
        int $anchorCount,
        SignalStatus $confirmed
    ) {
        $state = $this->stateWith(confirmed: $confirmed, pending: null, sequence: 3);

        $savedState = null;
        $stateRepository = $this->createMock(DispersionStateRepository::class);
        $stateRepository->method('get')->willReturn($state);
        $stateRepository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $rollupRepository = $this->createStub(RollupRepository::class);
        $rollupRepository->method('allHourlyCountsInWindow')->willReturn(
            $this->lowVolumeSeries($currentGapHours, $bucketGapHoursByWeeksAgo, $anchorCount)
        );

        $report = (new DispersionEvaluator($rollupRepository, $stateRepository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::CATEGORY,
            0,
            $this->now(),
            $this->now()
        );

        self::assertSame(
            ReportReason::Heartbeat,
            $report->reason,
            'Expected the "no change" branch (raw === confirmed) to fire, not a transition.'
        );
        self::assertNull(
            $savedState->pendingStatus,
            'A non-null pendingStatus means raw actually differed from the confirmed status passed in --'
            . ' this helper only isolates rawStatus() classification when there is no such drift.'
        );

        return $report;
    }

    /**
     * @return HourlyCountSample[]
     */
    private function baselineSamples(): array
    {
        $samples = [];

        foreach (self::BASELINE_COUNTS_BY_WEEKS_AGO as $weeksAgo => $count) {
            $samples[] = $this->sample($weeksAgo, $count);
        }

        return $samples;
    }

    private function sample(int $weeksAgo, int $count): HourlyCountSample
    {
        return new HourlyCountSample($this->now()->modify("-{$weeksAgo} weeks"), $count);
    }

    /**
     * Runs evaluate() against a state whose confirmedStatus is pre-set to
     * the expected classification, so the "no change" branch fires and the
     * report status is a direct read of rawStatus()'s classification.
     *
     * Two independent guards, not one, against a wrong rawStatus() hiding
     * behind a passing status assertion:
     * hourlyCountsForBucket is asserted called at least once for Check A's
     * OWN window specifically (rawStatus() always fetches Check A's own
     * bucket samples first regardless of which path it ultimately takes,
     * so this holds for every caller); and the report's own reason is
     * asserted Heartbeat with a null pendingStatus, which holds ONLY on
     * the genuine "no change" branch (raw === confirmed) -- the "first
     * differing tick" branch also reports the OLD confirmed status as a
     * Heartbeat-reason report, but sets pendingStatus to the (different)
     * raw value, so a caller whose $confirmed argument merely happens to
     * match whatever the code under test actually produces can no longer
     * pass silently.
     *
     * hourlyCountsForBucket is not exclusively Check A's own call: with
     * the ensemble combiner, Check C (TrendAdjustmentEvaluator)
     * calls the SAME repository method for its own trend-lookback-prior
     * window. $samples is therefore only returned for Check A's own exact
     * $now argument (via samplesOnlyForCurrentWindow()) -- any other
     * window (Check C's) gets an empty array, so Check C abstains by
     * default in every test using this helper, exactly as it would in
     * production for a prior window this test never populated. Without
     * this, every classification test in this file would silently start
     * exercising the ensemble combiner with fabricated "prior window"
     * data drawn from Check A's own current-window fixture.
     *
     * @param int $observedCount
     * @param SignalStatus $confirmed
     * @param HourlyCountSample[] $samples
     * @return \Watchtower\Connector\Model\Api\MetricReport
     */
    private function evaluateWithConfirmedStatus(int $observedCount, SignalStatus $confirmed, array $samples)
    {
        $state = $this->stateWith(confirmed: $confirmed, pending: null, sequence: 3);
        $now = $this->now();

        $savedState = null;
        $stateRepository = $this->createMock(DispersionStateRepository::class);
        $stateRepository->method('get')->willReturn($state);
        $stateRepository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $rollupRepository = $this->createMock(RollupRepository::class);
        $rollupRepository->expects(self::atLeastOnce())
            ->method('hourlyCountsForBucket')
            ->willReturnCallback($this->samplesOnlyForCurrentWindow($samples, $now));
        $rollupRepository->method('allHourlyCountsInWindow')->willReturn([]);
        $rollupRepository->method('dailyCountsInWindow')->willReturn([]);

        $report = (new DispersionEvaluator($rollupRepository, $stateRepository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::CATEGORY,
            $observedCount,
            $now,
            $now
        );

        self::assertSame(
            ReportReason::Heartbeat,
            $report->reason,
            'Expected the "no change" branch (raw === confirmed) to fire, not a transition.'
        );
        self::assertNull(
            $savedState->pendingStatus,
            'A non-null pendingStatus means raw actually differed from the confirmed status passed in --'
            . ' this helper only isolates rawStatus() classification when there is no such drift.'
        );

        return $report;
    }

    /**
     * Builds a RollupRepository::hourlyCountsForBucket callback that
     * returns $samples only when called for the exact $forHour window,
     * and an empty array for any other window -- see
     * evaluateWithConfirmedStatus()'s own docblock for why this matters
     * once Check C shares the same repository method for its own,
     * genuinely different, trend-lookback-prior window.
     *
     * @param HourlyCountSample[] $samples
     * @param \DateTimeImmutable $forHour
     * @return \Closure
     */
    private function samplesOnlyForCurrentWindow(array $samples, \DateTimeImmutable $forHour): \Closure
    {
        return static function (
            int $storeViewId,
            string $category,
            int $isoDayOfWeek,
            int $hourOfDay,
            int $weeks,
            \DateTimeImmutable $now
        ) use (
            $samples,
            $forHour
): array {
            return $now == $forHour ? $samples : [];
        };
    }

    private static function captureInto(&$variable): \PHPUnit\Framework\Constraint\Callback
    {
        return self::callback(function (DispersionState $state) use (&$variable) {
            $variable = $state;

            return true;
        });
    }

    private function stateWith(SignalStatus $confirmed, ?SignalStatus $pending, int $sequence): DispersionState
    {
        return new DispersionState(
            storeViewId: self::STORE_VIEW_ID,
            category: self::CATEGORY,
            pendingStatus: $pending,
            confirmedStatus: $confirmed,
            sequenceNumber: $sequence,
        );
    }

    private function freshState(): DispersionState
    {
        return new DispersionState(
            storeViewId: self::STORE_VIEW_ID,
            category: self::CATEGORY,
            pendingStatus: null,
            confirmedStatus: null,
            sequenceNumber: 1,
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW_STRING);
    }
}
