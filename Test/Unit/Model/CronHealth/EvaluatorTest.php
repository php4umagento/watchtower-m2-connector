<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\CronHealth;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\CronHealth\CronScheduleObserver;
use Watchtower\Connector\Model\CronHealth\Evaluator;
use Watchtower\Connector\Model\CronHealth\Observation;
use Watchtower\Connector\Model\HealthState\HealthState;
use Watchtower\Connector\Model\HealthState\HealthStateRepository;

/**
 * Regression coverage for the two-evaluation debounce, applied to
 * cron_health's state machine instead of a rate-based baseline.
 * Each test hand-builds the HealthState a
 * prior tick would have left behind, so the debounce sequence itself is
 * exercised one transition at a time, AND asserts the exact HealthState
 * persisted afterward. sequence_number monotonicity is the one thing the
 * platform hard-rejects on, so every branch checks it, not just the first.
 */
class EvaluatorTest extends TestCase
{
    private const NOW_STRING = '2026-08-13T15:00:00+00:00';

    public function testFirstEvaluationEverReportsInsufficientDataAsATransition(): void
    {
        $savedState = null;
        $repository = $this->createMock(HealthStateRepository::class);
        $repository->method('get')->willReturn($this->freshState());
        $repository->expects(self::once())
            ->method('save')
            ->with(self::captureInto($savedState));

        $observer = $this->createStub(CronScheduleObserver::class);
        $observer->method('observe')->willReturn(new Observation($this->now(), null));

        $report = (new Evaluator($observer, $repository))->evaluate($this->now());

        self::assertSame(SignalStatus::InsufficientData, $report->status);
        self::assertSame(ReportReason::Transition, $report->reason);
        self::assertSame(1, $report->sequenceNumber);
        self::assertNull($report->storeViewCode);
        self::assertSame(Evaluator::EVENT_TYPE, $report->eventType);

        self::assertSame(SignalStatus::InsufficientData, $savedState->confirmedStatus);
        self::assertNull($savedState->pendingStatus);
        self::assertSame(2, $savedState->sequenceNumber);
    }

    public function testUnchangedStatusIsReportedAsAHeartbeatAndSequenceStillAdvances(): void
    {
        $state = $this->stateWith(confirmed: SignalStatus::Normal, pending: null, sequence: 5);

        $savedState = null;
        $repository = $this->createMock(HealthStateRepository::class);
        $repository->method('get')->willReturn($state);
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $observer = $this->createStub(CronScheduleObserver::class);
        $observer->method('observe')->willReturn(new Observation($this->now(), null));

        $report = (new Evaluator($observer, $repository))->evaluate($this->now());

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertSame(5, $report->sequenceNumber);

        self::assertSame(SignalStatus::Normal, $savedState->confirmedStatus);
        self::assertNull($savedState->pendingStatus);
        self::assertSame(
            6,
            $savedState->sequenceNumber,
            'Sequence must advance on a heartbeat too, not only on a transition.'
        );
    }

    public function testFirstTickOfADifferentRawStatusHeartbeatsTheOldStatusAndSetsPending(): void
    {
        $state = $this->stateWith(confirmed: SignalStatus::Normal, pending: null, sequence: 5);

        $savedState = null;
        $repository = $this->createMock(HealthStateRepository::class);
        $repository->method('get')->willReturn($state);
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        // No success and no failure evidence at all -> DOWN, differs from confirmed Normal.
        $observer = $this->createStub(CronScheduleObserver::class);
        $observer->method('observe')->willReturn(new Observation(null, null));

        $report = (new Evaluator($observer, $repository))->evaluate($this->now());

        // Still reports the OLD confirmed status, not yet confirmed.
        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertSame(5, $report->sequenceNumber);

        self::assertSame(SignalStatus::Normal, $savedState->confirmedStatus);
        self::assertSame(SignalStatus::SevereDrop, $savedState->pendingStatus);
        self::assertSame(6, $savedState->sequenceNumber);
    }

    public function testSecondConsecutiveTickOfTheSameDifferentStatusConfirmsTheTransition(): void
    {
        $state = $this->stateWith(confirmed: SignalStatus::Normal, pending: SignalStatus::SevereDrop, sequence: 6);

        $savedState = null;
        $repository = $this->createMock(HealthStateRepository::class);
        $repository->method('get')->willReturn($state);
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $observer = $this->createStub(CronScheduleObserver::class);
        $observer->method('observe')->willReturn(new Observation(null, null));

        $report = (new Evaluator($observer, $repository))->evaluate($this->now());

        self::assertSame(SignalStatus::SevereDrop, $report->status);
        self::assertSame(ReportReason::Transition, $report->reason);
        self::assertSame(6, $report->sequenceNumber);

        self::assertSame(SignalStatus::SevereDrop, $savedState->confirmedStatus);
        self::assertNull($savedState->pendingStatus);
        self::assertSame(7, $savedState->sequenceNumber);
    }

    /**
     * The recovery direction, not just the down direction; the spec's own
     * debounce worked example walks both, and this was previously verified
     * only by hand against the live platform, not by a regression test.
     */
    public function testRecoveryFromSevereDropToNormalGoesThroughTheSameTwoTickConfirmation(): void
    {
        $pendingRecovery = $this->stateWith(confirmed: SignalStatus::SevereDrop, pending: null, sequence: 10);

        $repository1 = $this->createStub(HealthStateRepository::class);
        $repository1->method('get')->willReturn($pendingRecovery);
        $observerRecovered = $this->createStub(CronScheduleObserver::class);
        $observerRecovered->method('observe')->willReturn(new Observation($this->now(), null));

        $firstTick = (new Evaluator($observerRecovered, $repository1))->evaluate($this->now());
        self::assertSame(SignalStatus::SevereDrop, $firstTick->status);
        self::assertSame(ReportReason::Heartbeat, $firstTick->reason);

        $confirming = $this->stateWith(
            confirmed: SignalStatus::SevereDrop,
            pending: SignalStatus::Normal,
            sequence: 11
        );

        $savedState = null;
        $repository2 = $this->createMock(HealthStateRepository::class);
        $repository2->method('get')->willReturn($confirming);
        $repository2->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $secondTick = (new Evaluator($observerRecovered, $repository2))->evaluate($this->now());

        self::assertSame(SignalStatus::Normal, $secondTick->status);
        self::assertSame(ReportReason::Transition, $secondTick->reason);
        self::assertSame(SignalStatus::Normal, $savedState->confirmedStatus);
    }

    /**
     * Regression test for a real bug caught in review: a raw status
     * alternating between two DIFFERENT non-confirmed values (e.g. MILD_DROP
     * then SEVERE_DROP) must still converge within two ticks; comparing
     * raw to a stored pending VALUE (rather than just "was something
     * pending") would let this loop forever, silently heartbeating a status
     * everyone already knows is stale.
     */
    public function testAlternatingBetweenTwoDifferentAnomalousStatusesStillConverges(): void
    {
        // Tick 1: confirmed=Normal, raw=MildDrop (failure evidence, no success in window).
        $tick1State = $this->stateWith(confirmed: SignalStatus::Normal, pending: null, sequence: 1);
        $repo1 = $this->createStub(HealthStateRepository::class);
        $repo1->method('get')->willReturn($tick1State);
        $observer1 = $this->createStub(CronScheduleObserver::class);
        $observer1->method('observe')->willReturn(new Observation(null, $this->now()));

        $tick1 = (new Evaluator($observer1, $repo1))->evaluate($this->now());
        self::assertSame(SignalStatus::Normal, $tick1->status);
        self::assertSame(ReportReason::Heartbeat, $tick1->reason);

        // Tick 2: pending is now set (MildDrop from tick 1), but THIS tick's raw
        // is SevereDrop (no failure evidence either), a different anomalous value.
        $tick2State = $this->stateWith(confirmed: SignalStatus::Normal, pending: SignalStatus::MildDrop, sequence: 2);
        $savedState = null;
        $repo2 = $this->createMock(HealthStateRepository::class);
        $repo2->method('get')->willReturn($tick2State);
        $repo2->expects(self::once())->method('save')->with(self::captureInto($savedState));
        $observer2 = $this->createStub(CronScheduleObserver::class);
        $observer2->method('observe')->willReturn(new Observation(null, null));

        $tick2 = (new Evaluator($observer2, $repo2))->evaluate($this->now());

        // Must confirm on this second differing tick, using the CURRENT raw
        // (SevereDrop), not get stuck forever because it doesn't match the
        // stored pending value (MildDrop).
        self::assertSame(SignalStatus::SevereDrop, $tick2->status);
        self::assertSame(ReportReason::Transition, $tick2->reason);
        self::assertSame(SignalStatus::SevereDrop, $savedState->confirmedStatus);
        self::assertNull($savedState->pendingStatus);
    }

    public function testARawStatusThatFlipsBackClearsTheStalePendingStatus(): void
    {
        $state = $this->stateWith(confirmed: SignalStatus::Normal, pending: SignalStatus::SevereDrop, sequence: 7);

        $savedState = null;
        $repository = $this->createMock(HealthStateRepository::class);
        $repository->method('get')->willReturn($state);
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $observer = $this->createStub(CronScheduleObserver::class);
        $observer->method('observe')->willReturn(new Observation($this->now(), null));

        $report = (new Evaluator($observer, $repository))->evaluate($this->now());

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertNull($savedState->pendingStatus);
        self::assertSame(SignalStatus::Normal, $savedState->confirmedStatus);
    }

    public function testFailureSinceLastSuccessIsMildDropNotSevereDrop(): void
    {
        $state = $this->stateWith(confirmed: SignalStatus::MildDrop, pending: null, sequence: 3);

        $repository = $this->createStub(HealthStateRepository::class);
        $repository->method('get')->willReturn($state);

        // A failure exists and is more recent than any known success -> FAILED/MildDrop,
        // distinct from DOWN/SevereDrop (no evidence of either).
        $observer = $this->createStub(CronScheduleObserver::class);
        $observer->method('observe')->willReturn(new Observation(null, $this->now()));

        $report = (new Evaluator($observer, $repository))->evaluate($this->now());

        self::assertSame(SignalStatus::MildDrop, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
    }

    /**
     * lastSuccessAt/lastFailureAt carry-forward across ticks: a tick whose
     * OWN observation is empty (both null, e.g. mid-generation-burst-gap)
     * must still see whatever the PREVIOUS tick persisted, not treat the
     * signal as if nothing had ever succeeded.
     */
    public function testLastSuccessAtCarriesForwardWhenThisTicksObservationIsEmpty(): void
    {
        $priorSuccess = $this->now()->modify('-10 minutes');
        $state = new HealthState(
            eventType: Evaluator::EVENT_TYPE,
            lastSuccessAt: $priorSuccess,
            lastFailureAt: null,
            pendingStatus: null,
            confirmedStatus: SignalStatus::Normal,
            sequenceNumber: 8,
        );

        $savedState = null;
        $repository = $this->createMock(HealthStateRepository::class);
        $repository->method('get')->willReturn($state);
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        // This tick's own observation found nothing new; 10 minutes ago is
        // still well inside the 30-minute expected interval, so this must
        // still read as Normal via the carried-forward timestamp.
        $observer = $this->createStub(CronScheduleObserver::class);
        $observer->method('observe')->willReturn(new Observation(null, null));

        $report = (new Evaluator($observer, $repository))->evaluate($this->now());

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertEquals($priorSuccess, $savedState->lastSuccessAt);
    }

    public function testASuccessOlderThanTheExpectedIntervalNoLongerCountsAsOk(): void
    {
        $staleSuccess = $this->now()->modify('-45 minutes');
        $state = new HealthState(
            eventType: Evaluator::EVENT_TYPE,
            lastSuccessAt: $staleSuccess,
            lastFailureAt: null,
            pendingStatus: null,
            confirmedStatus: SignalStatus::Normal,
            sequenceNumber: 9,
        );

        $repository = $this->createStub(HealthStateRepository::class);
        $repository->method('get')->willReturn($state);

        $observer = $this->createStub(CronScheduleObserver::class);
        $observer->method('observe')->willReturn(new Observation(null, null));

        $report = (new Evaluator($observer, $repository))->evaluate($this->now());

        // Raw status has moved to SevereDrop, but confirmed is still Normal
        // for one more tick (debounce); the report this cycle is still a
        // heartbeat, proving the OLD success no longer masks the new gap.
        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
    }

    private static function captureInto(&$variable): \PHPUnit\Framework\Constraint\Callback
    {
        return self::callback(function (HealthState $state) use (&$variable) {
            $variable = $state;

            return true;
        });
    }

    private function stateWith(SignalStatus $confirmed, ?SignalStatus $pending, int $sequence): HealthState
    {
        return new HealthState(
            eventType: Evaluator::EVENT_TYPE,
            lastSuccessAt: null,
            lastFailureAt: null,
            pendingStatus: $pending,
            confirmedStatus: $confirmed,
            sequenceNumber: $sequence,
        );
    }

    private function freshState(): HealthState
    {
        return new HealthState(
            eventType: Evaluator::EVENT_TYPE,
            lastSuccessAt: null,
            lastFailureAt: null,
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
