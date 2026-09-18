<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\ApplicationErrors;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\AdminAuthFailure\InstallEventCounterRepository;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\ApplicationErrors\Evaluator;
use Watchtower\Connector\Model\ApplicationErrors\Handler;
use Watchtower\Connector\Model\Debounce\TwoEvaluationDebounce;
use Watchtower\Connector\Model\HealthState\HealthState;
use Watchtower\Connector\Model\HealthState\HealthStateRepository;
use Watchtower\Connector\Model\Rollup\HourlyCountSample;
use Watchtower\Connector\Model\Rollup\InstallRollupRepository;

class EvaluatorTest extends TestCase
{
    private const EVALUATED_HOUR = '2026-08-23T14:00:00+00:00'; // a Sunday, 14:00 UTC

    public function testBelowMinimumHistoricalSamplesIsInsufficientData(): void
    {
        // Only 2 samples, MIN_HISTORICAL_SAMPLES is 3. confirmed: null --
        // the first-ever evaluation, so warmsUp's seed reports the raw
        // INSUFFICIENT_DATA status directly rather than needing two
        // consecutive ticks to confirm a transition away from an existing
        // confirmed status.
        $report = $this->evaluateWith(
            observedCount: 100,
            samples: [3, 4],
            confirmed: null,
            pending: null,
        );

        self::assertSame(SignalStatus::InsufficientData, $report->status);
    }

    /**
     * Check A: median/MAD z-score once there is enough bucket history and
     * the median clears VOLUME_FLOOR (5).
     */
    public function testClassifiesAboveTheVolumeFloorAgainstTheHistoricalMedian(): void
    {
        // Median 10, MAD-derived scale keeps a huge spike SEVERE, a normal
        // count NORMAL -- mirrors RateSignal\DispersionEvaluatorTest's shape.
        $samples = [8, 9, 10, 10, 11, 12];

        $normal = $this->evaluateWith($samples[2], $samples, confirmed: SignalStatus::Normal, pending: null);
        self::assertSame(SignalStatus::Normal, $normal->status, 'observed count equal to the median must be NORMAL');

        // Second consecutive tick (pending already SevereSpike) so the
        // two-evaluation debounce confirms it -- a single tick only arms
        // pending and heartbeats the old confirmed status, per
        // testASingleBadHourDoesNotTransitionOnItsOwn below.
        $spike = $this->evaluateWith(
            500,
            $samples,
            confirmed: SignalStatus::Normal,
            pending: SignalStatus::SevereSpike
        );
        self::assertSame(SignalStatus::SevereSpike, $spike->status, 'an extreme spike must be a severe spike');
    }

    /**
     * EL1, the central claim of this document: a dropping error rate is
     * never a failure. Unlike RateSignal\DispersionEvaluator, this must
     * never report MILD_DROP/SEVERE_DROP no matter how far below the
     * median the observed count falls.
     */
    public function testADropBelowTheMedianIsAlwaysNormalNeverADrop(): void
    {
        $samples = [40, 42, 45, 48, 50, 55];

        $report = $this->evaluateWith(0, $samples, confirmed: SignalStatus::Normal, pending: null);

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertNotSame(SignalStatus::MildDrop, $report->status);
        self::assertNotSame(SignalStatus::SevereDrop, $report->status);
    }

    /**
     * EL2/EL7/the confirmed shadow-mode decision: below VOLUME_FLOOR, a
     * burst that would meet BURST_THRESHOLD_N is evaluated but forced to
     * NORMAL on the wire, since the threshold itself is uncalibrated (PRD
     * Q1). This is the test that would catch SHADOW_MODE_BURST_ALERTING
     * being flipped on by accident.
     */
    public function testBelowTheVolumeFloorABurstIsSuppressedToNormalInShadowMode(): void
    {
        // Median well under VOLUME_FLOOR (5).
        $samples = [0, 1, 0, 1];

        $report = $this->evaluateWith(999, $samples, confirmed: SignalStatus::Normal, pending: null);

        self::assertSame(
            SignalStatus::Normal,
            $report->status,
            'the burst fallback must stay shadow-suppressed until real data justifies BURST_THRESHOLD_N'
        );
    }

    public function testBelowTheVolumeFloorAQuietHourIsNormal(): void
    {
        $samples = [0, 1, 0, 1];

        $report = $this->evaluateWith(0, $samples, confirmed: SignalStatus::Normal, pending: null);

        self::assertSame(SignalStatus::Normal, $report->status);
    }

    public function testASingleBadHourDoesNotTransitionOnItsOwn(): void
    {
        $samples = [8, 9, 10, 10, 11, 12];
        $saved = null;

        $report = $this->evaluateWith(500, $samples, confirmed: SignalStatus::Normal, pending: null, saved: $saved);

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertSame(SignalStatus::SevereSpike, $saved->pendingStatus);
    }

    public function testASecondConsecutiveBadHourConfirmsTheTransition(): void
    {
        $samples = [8, 9, 10, 10, 11, 12];

        $report = $this->evaluateWith(
            500,
            $samples,
            confirmed: SignalStatus::Normal,
            pending: SignalStatus::SevereSpike
        );

        self::assertSame(SignalStatus::SevereSpike, $report->status);
        self::assertSame(ReportReason::Transition, $report->reason);
    }

    /**
     * warmsUp: true -- unlike admin_auth_failure's fixed threshold, this
     * signal needs real history before its verdict is trustworthy, so the
     * very first evaluation on a fresh install seeds INSUFFICIENT_DATA
     * regardless of this tick's raw status (EL7).
     */
    public function testTheFirstEvaluationEverSeedsInsufficientDataRegardlessOfRawStatus(): void
    {
        $samples = [8, 9, 10, 10, 11, 12];

        $report = $this->evaluateWith(500, $samples, confirmed: null, pending: null);

        self::assertSame(SignalStatus::InsufficientData, $report->status);
    }

    public function testTheReportIsInstallScopedAndCarriesItsOwnRulesetVersion(): void
    {
        $report = $this->evaluateWith(0, [0, 1, 0], confirmed: SignalStatus::Normal, pending: null);

        self::assertNull($report->storeViewCode);
        self::assertSame('application_errors', $report->eventType);
        self::assertSame(Evaluator::RULESET_VERSION, $report->rulesetVersion);
    }

    public function testNeverPersistsSuccessOrFailureTimestamps(): void
    {
        $saved = null;
        $this->evaluateWith(0, [0, 1, 0], confirmed: SignalStatus::Normal, pending: null, saved: $saved);

        self::assertNull($saved->lastSuccessAt);
        self::assertNull($saved->lastFailureAt);
    }

    public function testTheSequenceNumberAdvancesOnEveryTick(): void
    {
        $saved = null;
        $report = $this->evaluateWith(
            0,
            [0, 1, 0],
            confirmed: SignalStatus::Normal,
            pending: null,
            sequenceNumber: 7,
            saved: $saved
        );

        self::assertSame(7, $report->sequenceNumber);
        self::assertSame(8, $saved->sequenceNumber);
    }

    /**
     * Every evaluation records the completed hour's count into the rollup
     * table regardless of classification outcome -- this is the dataset a
     * future review reads to calibrate BURST_THRESHOLD_N (PRD Q1), so it
     * must be written even when the tick is suppressed to NORMAL.
     */
    public function testRecordsTheObservedCountIntoTheRollupRegardlessOfOutcome(): void
    {
        $evaluatedHour = new \DateTimeImmutable(self::EVALUATED_HOUR);
        $recorded = [];

        $counter = $this->createStub(InstallEventCounterRepository::class);
        $counter->method('countFor')->willReturn(42);

        $rollupRepository = $this->createStub(InstallRollupRepository::class);
        $rollupRepository->method('hourlyCountsForBucket')->willReturn([]);
        $rollupRepository->method('recordHourlyCount')->willReturnCallback(
            static function (string $eventName, \DateTimeImmutable $hour, int $count) use (&$recorded): void {
                $recorded[] = [$eventName, $hour->format('Y-m-d H:i:s'), $count];
            }
        );

        $this->evaluator($counter, $rollupRepository, $this->stateRepository(SignalStatus::Normal, null, 1, $saved))
            ->evaluate($evaluatedHour, $evaluatedHour->modify('+1 hour'));

        self::assertSame([[Handler::EVENT_NAME, '2026-08-23 14:00:00', 42]], $recorded);
    }

    /**
     * The value being classified must not pollute its own baseline --
     * mirrors RateSignal\DispersionEvaluatorTest's equivalent guard.
     */
    public function testTheEvaluatedHourIsExcludedFromItsOwnHistoricalSamples(): void
    {
        $evaluatedHour = new \DateTimeImmutable(self::EVALUATED_HOUR);

        $counter = $this->createStub(InstallEventCounterRepository::class);
        $counter->method('countFor')->willReturn(10);

        $rollupRepository = $this->createStub(InstallRollupRepository::class);
        $rollupRepository->method('hourlyCountsForBucket')->willReturn([
            new HourlyCountSample(bucket: $evaluatedHour->modify('-1 week'), count: 8),
            new HourlyCountSample(bucket: $evaluatedHour->modify('-2 weeks'), count: 9),
            new HourlyCountSample(bucket: $evaluatedHour, count: 999), // the evaluated hour's own row
        ]);

        // confirmed: null -- the first-ever evaluation, so warmsUp's seed
        // reports the raw INSUFFICIENT_DATA status directly rather than
        // needing two consecutive ticks to confirm a transition away from
        // an existing confirmed status.
        $report = $this->evaluator(
            $counter,
            $rollupRepository,
            $this->stateRepository(null, null, 1, $saved)
        )->evaluate($evaluatedHour, $evaluatedHour->modify('+1 hour'));

        // Only 2 real historical samples once the evaluated hour's own row is
        // excluded -- below MIN_HISTORICAL_SAMPLES (3), so INSUFFICIENT_DATA,
        // not a classification against a baseline the 999 count itself set.
        self::assertSame(SignalStatus::InsufficientData, $report->status);
    }

    private function evaluateWith(
        int $observedCount,
        array $samples,
        ?SignalStatus $confirmed,
        ?SignalStatus $pending,
        int $sequenceNumber = 1,
        ?HealthState &$saved = null
    ) {
        $evaluatedHour = new \DateTimeImmutable(self::EVALUATED_HOUR);

        $counter = $this->createStub(InstallEventCounterRepository::class);
        $counter->method('countFor')->willReturn($observedCount);

        $rollupRepository = $this->createStub(InstallRollupRepository::class);
        $rollupRepository->method('hourlyCountsForBucket')->willReturn(array_map(
            static fn (int $count, int $i): HourlyCountSample => new HourlyCountSample(
                bucket: $evaluatedHour->modify(sprintf('-%d weeks', $i + 1)),
                count: $count,
            ),
            $samples,
            array_keys($samples)
        ));

        $repository = $this->stateRepository($confirmed, $pending, $sequenceNumber, $saved);

        return $this->evaluator($counter, $rollupRepository, $repository)->evaluate(
            $evaluatedHour,
            $evaluatedHour->modify('+1 hour')
        );
    }

    private function evaluator(
        InstallEventCounterRepository $counter,
        InstallRollupRepository $rollupRepository,
        HealthStateRepository $repository
    ): Evaluator {
        return new Evaluator($counter, $rollupRepository, $repository, new TwoEvaluationDebounce());
    }

    private function stateRepository(
        ?SignalStatus $confirmed,
        ?SignalStatus $pending,
        int $sequenceNumber,
        ?HealthState &$saved
    ): HealthStateRepository {
        $repository = $this->createStub(HealthStateRepository::class);
        $repository->method('get')->willReturn(new HealthState(
            eventType: Evaluator::EVENT_TYPE,
            lastSuccessAt: null,
            lastFailureAt: null,
            pendingStatus: $pending,
            confirmedStatus: $confirmed,
            sequenceNumber: $sequenceNumber,
        ));
        $repository->method('save')->willReturnCallback(
            static function (HealthState $state) use (&$saved): void {
                $saved = $state;
            }
        );

        return $repository;
    }
}
