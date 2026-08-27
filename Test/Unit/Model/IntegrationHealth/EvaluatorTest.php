<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\IntegrationHealth;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\IntegrationHealth\Evaluator;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfig;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthState;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthStateRepository;

/**
 * Regression coverage for the two-evaluation debounce applied to
 * integration_health's own state machine -- mirrors
 * CronHealth\EvaluatorTest.php's own shape exactly (same debounce sequences,
 * same OK/FAILED/DOWN classification), adapted for per-store-view state and
 * a configurable (not hardcoded) expected-max-interval, and for
 * $observedSuccessAt/$observedFailureAt being passed directly rather than
 * resolved via an internal Observer; see Model/ReportingService.php for which
 * observer feeds it per store view. Also covers the source-change re-seed and
 * the newly-configured-source grace window, neither of which cron_health has.
 */
class EvaluatorTest extends TestCase
{
    private const NOW_STRING = '2026-08-13T15:00:00+00:00';
    private const STORE_VIEW_ID = 7;
    private const STORE_VIEW_CODE = 'default';
    private const THRESHOLD_SECONDS = 1800;
    private const SOURCE_TYPE = IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB;
    private const SOURCE_IDENTIFIER = 'watchtower_example_cron';

    /**
     * Configuring a source for the first time is a source change like any
     * other: it seeds INSUFFICIENT_DATA as a heartbeat, never a transition,
     * which the platform would alert on.
     */
    public function testFirstEvaluationEverSeedsInsufficientDataAsAHeartbeat(): void
    {
        $savedState = null;
        $repository = $this->createMock(IntegrationHealthStateRepository::class);
        $repository->method('get')->willReturn($this->freshState());
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $report = (new Evaluator($repository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            $this->now(),
            null,
            self::THRESHOLD_SECONDS,
            $this->now()
        );

        self::assertSame(SignalStatus::InsufficientData, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertSame(1, $report->sequenceNumber);
        self::assertSame(
            self::STORE_VIEW_CODE,
            $report->storeViewCode,
            'integration_health is store-view-scoped, unlike cron_health.'
        );
        self::assertSame(Evaluator::EVENT_TYPE, $report->eventType);

        self::assertSame(SignalStatus::InsufficientData, $savedState->confirmedStatus);
        self::assertNull($savedState->pendingStatus);
        self::assertSame(2, $savedState->sequenceNumber);
        self::assertSame(self::SOURCE_TYPE, $savedState->sourceType);
        self::assertSame(self::SOURCE_IDENTIFIER, $savedState->sourceIdentifier);
        self::assertEquals($this->now(), $savedState->observingSince);
    }

    public function testUnchangedStatusIsReportedAsAHeartbeatAndSequenceStillAdvances(): void
    {
        $state = $this->stateWith(confirmed: SignalStatus::Normal, pending: null, sequence: 5);

        $savedState = null;
        $repository = $this->createMock(IntegrationHealthStateRepository::class);
        $repository->method('get')->willReturn($state);
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $report = (new Evaluator($repository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            $this->now(),
            null,
            self::THRESHOLD_SECONDS,
            $this->now()
        );

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
        $repository = $this->createMock(IntegrationHealthStateRepository::class);
        $repository->method('get')->willReturn($state);
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        // No success and no failure evidence at all -> DOWN, differs from confirmed Normal.
        $report = (new Evaluator($repository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            null,
            null,
            self::THRESHOLD_SECONDS,
            $this->now()
        );

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
        $repository = $this->createMock(IntegrationHealthStateRepository::class);
        $repository->method('get')->willReturn($state);
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $report = (new Evaluator($repository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            null,
            null,
            self::THRESHOLD_SECONDS,
            $this->now()
        );

        self::assertSame(SignalStatus::SevereDrop, $report->status);
        self::assertSame(ReportReason::Transition, $report->reason);
        self::assertSame(6, $report->sequenceNumber);

        self::assertSame(SignalStatus::SevereDrop, $savedState->confirmedStatus);
        self::assertNull($savedState->pendingStatus);
        self::assertSame(7, $savedState->sequenceNumber);
    }

    /**
     * Regression test for a real bug: a fresh store view's integration_health
     * warms up through INSUFFICIENT_DATA (the source-change seed)
     * before its first real confirmed status. Confirming NORMAL straight out
     * of that seed must NOT report as a transition -- it was never actually
     * down, so this is warm-up finishing, not a recovery. Reporting it as a
     * transition makes the platform send an unconditional "back to normal"
     * email (App\Notifications\StoreViewAlertNotification, watchtower-saas)
     * for a store that never had a problem.
     */
    public function testConfirmingNormalStraightOutOfTheInsufficientDataSeedIsAHeartbeatNotAResolvedTransition(): void
    {
        $state = $this->stateWith(
            confirmed: SignalStatus::InsufficientData,
            pending: SignalStatus::Normal,
            sequence: 3
        );

        $savedState = null;
        $repository = $this->createMock(IntegrationHealthStateRepository::class);
        $repository->method('get')->willReturn($state);
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $report = (new Evaluator($repository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            $this->now(),
            null,
            self::THRESHOLD_SECONDS,
            $this->now()
        );

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertSame(SignalStatus::Normal, $savedState->confirmedStatus);
        self::assertNull($savedState->pendingStatus);
    }

    /**
     * The counterpart to the above: confirming an ANOMALOUS status straight
     * out of the INSUFFICIENT_DATA seed is still a genuine first-detected
     * problem, not a false recovery -- this must stay a transition so it
     * still alerts.
     */
    public function testConfirmingAnAnomalousStatusStraightOutOfTheInsufficientDataSeedIsStillATransition(): void
    {
        $state = $this->stateWith(
            confirmed: SignalStatus::InsufficientData,
            pending: SignalStatus::SevereDrop,
            sequence: 3
        );

        $savedState = null;
        $repository = $this->createMock(IntegrationHealthStateRepository::class);
        $repository->method('get')->willReturn($state);
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $report = (new Evaluator($repository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            null,
            null,
            self::THRESHOLD_SECONDS,
            $this->now()
        );

        self::assertSame(SignalStatus::SevereDrop, $report->status);
        self::assertSame(ReportReason::Transition, $report->reason);
        self::assertSame(SignalStatus::SevereDrop, $savedState->confirmedStatus);
    }

    public function testAlternatingBetweenTwoDifferentAnomalousStatusesStillConverges(): void
    {
        // Tick 1: confirmed=Normal, raw=MildDrop (failure evidence, no success in window).
        $tick1State = $this->stateWith(confirmed: SignalStatus::Normal, pending: null, sequence: 1);
        $repo1 = $this->createStub(IntegrationHealthStateRepository::class);
        $repo1->method('get')->willReturn($tick1State);

        $tick1 = (new Evaluator($repo1))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            null,
            $this->now(),
            self::THRESHOLD_SECONDS,
            $this->now()
        );
        self::assertSame(SignalStatus::Normal, $tick1->status);
        self::assertSame(ReportReason::Heartbeat, $tick1->reason);

        // Tick 2: pending is now set (MildDrop from tick 1), but THIS tick's raw
        // is SevereDrop (no failure evidence either), a different anomalous value.
        $tick2State = $this->stateWith(confirmed: SignalStatus::Normal, pending: SignalStatus::MildDrop, sequence: 2);
        $savedState = null;
        $repo2 = $this->createMock(IntegrationHealthStateRepository::class);
        $repo2->method('get')->willReturn($tick2State);
        $repo2->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $tick2 = (new Evaluator($repo2))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            null,
            null,
            self::THRESHOLD_SECONDS,
            $this->now()
        );

        // Must confirm on this second differing tick, using the CURRENT raw
        // (SevereDrop), not get stuck forever because it doesn't match the
        // stored pending value (MildDrop).
        self::assertSame(SignalStatus::SevereDrop, $tick2->status);
        self::assertSame(ReportReason::Transition, $tick2->reason);
        self::assertSame(SignalStatus::SevereDrop, $savedState->confirmedStatus);
        self::assertNull($savedState->pendingStatus);
    }

    /**
     * A raw status that blipped away from confirmed (setting pendingStatus)
     * and then flips BACK to match confirmed on the very next tick must
     * clear that stale pending value, not leave it sitting around to
     * falsely confirm a transition later on an unrelated future blip. The
     * generic "unchanged status" test above only covers pendingStatus
     * already being null; this specifically starts from a non-null pending
     * to prove it gets cleared, not just left alone.
     */
    public function testARawStatusThatFlipsBackClearsTheStalePendingStatus(): void
    {
        $state = $this->stateWith(confirmed: SignalStatus::Normal, pending: SignalStatus::SevereDrop, sequence: 9);

        $savedState = null;
        $repository = $this->createMock(IntegrationHealthStateRepository::class);
        $repository->method('get')->willReturn($state);
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        // Raw is Normal again (a fresh success right now), matching the
        // still-confirmed Normal -> "no change" branch, which must clear
        // the stale SevereDrop pending value.
        $report = (new Evaluator($repository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            $this->now(),
            null,
            self::THRESHOLD_SECONDS,
            $this->now()
        );

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);

        self::assertSame(SignalStatus::Normal, $savedState->confirmedStatus);
        self::assertNull(
            $savedState->pendingStatus,
            'The stale SevereDrop pending value must be cleared, not carried forward.'
        );
    }

    /**
     * Recovery is not just "DOWN detection" in reverse by accident -- walks
     * a full two-tick recovery from a confirmed SevereDrop back to Normal,
     * mirroring testSecondConsecutiveTickOfTheSameDifferentStatusConfirmsTheTransition's
     * own shape but in the opposite direction, to prove the debounce logic
     * has no asymmetry between "going down" and "coming back up".
     */
    public function testRecoveryToNormalConfirmsAfterTwoConsecutiveTicks(): void
    {
        // Tick 1: confirmed=SevereDrop, a fresh success arrives -> raw=Normal,
        // differs from confirmed -> first differing tick -> heartbeat the
        // OLD confirmed (SevereDrop), set pending=Normal.
        $tick1State = $this->stateWith(confirmed: SignalStatus::SevereDrop, pending: null, sequence: 10);
        $savedTick1 = null;
        $repo1 = $this->createMock(IntegrationHealthStateRepository::class);
        $repo1->method('get')->willReturn($tick1State);
        $repo1->expects(self::once())->method('save')->with(self::captureInto($savedTick1));

        $tick1 = (new Evaluator($repo1))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            $this->now(),
            null,
            self::THRESHOLD_SECONDS,
            $this->now()
        );

        self::assertSame(SignalStatus::SevereDrop, $tick1->status);
        self::assertSame(ReportReason::Heartbeat, $tick1->reason);
        self::assertSame(SignalStatus::SevereDrop, $savedTick1->confirmedStatus);
        self::assertSame(SignalStatus::Normal, $savedTick1->pendingStatus);

        // Tick 2: same fresh success still holds -> raw=Normal again,
        // pending is already set -> second consecutive differing tick ->
        // confirms Normal as a Transition.
        $tick2State = $this->stateWith(
            confirmed: SignalStatus::SevereDrop,
            pending: SignalStatus::Normal,
            sequence: 11
        );
        $savedTick2 = null;
        $repo2 = $this->createMock(IntegrationHealthStateRepository::class);
        $repo2->method('get')->willReturn($tick2State);
        $repo2->expects(self::once())->method('save')->with(self::captureInto($savedTick2));

        $tick2 = (new Evaluator($repo2))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            $this->now(),
            null,
            self::THRESHOLD_SECONDS,
            $this->now()
        );

        self::assertSame(SignalStatus::Normal, $tick2->status);
        self::assertSame(ReportReason::Transition, $tick2->reason);
        self::assertSame(SignalStatus::Normal, $savedTick2->confirmedStatus);
        self::assertNull($savedTick2->pendingStatus);
    }

    public function testFailureSinceLastSuccessIsMildDropNotSevereDrop(): void
    {
        $state = $this->stateWith(confirmed: SignalStatus::MildDrop, pending: null, sequence: 3);

        $repository = $this->createStub(IntegrationHealthStateRepository::class);
        $repository->method('get')->willReturn($state);

        // A failure exists and is more recent than any known success -> FAILED/MildDrop,
        // distinct from DOWN/SevereDrop (no evidence of either).
        $report = (new Evaluator($repository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            null,
            $this->now(),
            self::THRESHOLD_SECONDS,
            $this->now()
        );

        self::assertSame(SignalStatus::MildDrop, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
    }

    /**
     * lastSuccessAt/lastFailureAt carry-forward across ticks: a tick whose
     * OWN observation is empty (both null) must still see whatever the
     * PREVIOUS tick persisted, not treat the signal as if nothing had ever
     * succeeded.
     */
    public function testLastSuccessAtCarriesForwardWhenThisTicksObservationIsEmpty(): void
    {
        $priorSuccess = $this->now()->modify('-10 minutes');
        $state = new IntegrationHealthState(
            storeViewId: self::STORE_VIEW_ID,
            lastSuccessAt: $priorSuccess,
            lastFailureAt: null,
            pendingStatus: null,
            confirmedStatus: SignalStatus::Normal,
            sequenceNumber: 8,
            lastReportedReason: null,
            sourceType: self::SOURCE_TYPE,
            sourceIdentifier: self::SOURCE_IDENTIFIER,
            observingSince: $this->now()->modify('-1 year'),
        );

        $savedState = null;
        $repository = $this->createMock(IntegrationHealthStateRepository::class);
        $repository->method('get')->willReturn($state);
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        // This tick's own observation found nothing new; 10 minutes ago is
        // still well inside the 30-minute expected interval, so this must
        // still read as Normal via the carried-forward timestamp.
        $report = (new Evaluator($repository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            null,
            null,
            self::THRESHOLD_SECONDS,
            $this->now()
        );

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertEquals($priorSuccess, $savedState->lastSuccessAt);
    }

    /**
     * Until enough runs have been measured there is no honest window to judge
     * a source against, so the verdict is withheld rather than guessed at.
     * Reported as a Heartbeat, never a Transition: the platform alerts on any
     * transition, INSUFFICIENT_DATA included, and "still measuring" is not
     * something a merchant can act on.
     */
    public function testAnUnestablishedCadenceReportsInsufficientDataWithoutDebouncing(): void
    {
        $successLongAgo = $this->now()->modify('-3 days');

        $savedState = null;
        $repository = $this->createMock(IntegrationHealthStateRepository::class);
        $repository->method('get')->willReturn(
            $this->stateWith(confirmed: SignalStatus::Normal, pending: null, sequence: 4)
        );
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $report = (new Evaluator($repository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            $successLongAgo,
            null,
            null,
            $this->now()
        );

        self::assertSame(SignalStatus::InsufficientData, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertNull(
            $savedState->pendingStatus,
            'A source with no established cadence must not debounce towards an anomaly.'
        );
        self::assertEquals(
            $successLongAgo,
            $savedState->lastSuccessAt,
            'Evidence must keep accumulating while the cadence is still being learned.'
        );
    }

    /**
     * The threshold is a per-source DERIVED value, not a hardcoded constant
     * like CronHealth\Evaluator's own 30 minutes -- this proves the passed
     * threshold is genuinely respected, not silently ignored, by
     * discriminating on the resulting pendingStatus (only observable via a
     * real save) rather than the debounced report status alone, since a
     * single tick's report never directly exposes the raw classification.
     */
    public function testTheDerivedThresholdIsRespectedNotHardcoded(): void
    {
        $successFifteenMinutesAgo = $this->now()->modify('-15 minutes');
        $confirmedNormal = $this->stateWith(confirmed: SignalStatus::Normal, pending: null, sequence: 4);

        // 15 minutes ago is STALE against a 10-minute threshold ->
        // raw = SevereDrop, differs from confirmed Normal -> first
        // differing tick -> pendingStatus becomes SevereDrop.
        $savedTight = null;
        $repositoryTight = $this->createMock(IntegrationHealthStateRepository::class);
        $repositoryTight->method('get')->willReturn($confirmedNormal);
        $repositoryTight->expects(self::once())->method('save')->with(self::captureInto($savedTight));

        (new Evaluator($repositoryTight))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            $successFifteenMinutesAgo,
            null,
            600,
            $this->now()
        );

        self::assertSame(SignalStatus::SevereDrop, $savedTight->pendingStatus);

        // The SAME success timestamp is FRESH against a 60-minute threshold
        // -> raw = Normal, matches confirmed Normal -> no change
        // -> pendingStatus stays null.
        $savedWide = null;
        $repositoryWide = $this->createMock(IntegrationHealthStateRepository::class);
        $repositoryWide->method('get')->willReturn($confirmedNormal);
        $repositoryWide->expects(self::once())->method('save')->with(self::captureInto($savedWide));

        (new Evaluator($repositoryWide))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            $successFifteenMinutesAgo,
            null,
            3600,
            $this->now()
        );

        self::assertNull(
            $savedWide->pendingStatus,
            'The same success timestamp must classify differently depending on the derived threshold.'
        );
    }

    /**
     * A store view that has never had integration_health evaluated at all
     * has no platform-side (store_view_id, event_type) pair to keep alive
     * yet -- must return null rather than fabricating a report, and must
     * never persist state for a store view that was never really evaluated.
     */
    public function testHeartbeatRetiredReturnsNullForAStoreViewNeverEvaluatedBefore(): void
    {
        $repository = $this->createMock(IntegrationHealthStateRepository::class);
        $repository->method('get')->willReturn($this->freshState());
        $repository->expects(self::never())->method('save');

        $report = (new Evaluator($repository))->heartbeatRetiredIfPreviouslyReported(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            $this->now()
        );

        self::assertNull($report);
    }

    /**
     * A store view that WAS previously evaluated (config since cleared, or
     * source_type no longer recognized) must keep heartbeating its last
     * confirmed status -- never a transition, since there is no new
     * observation to transition on -- and the persisted pendingStatus must
     * be cleared, since there is nothing left actively debouncing.
     */
    public function testHeartbeatRetiredReHeartbeatsTheLastConfirmedStatusForAPreviouslyReportedStoreView(): void
    {
        $state = $this->stateWith(confirmed: SignalStatus::Normal, pending: SignalStatus::SevereDrop, sequence: 12);

        $savedState = null;
        $repository = $this->createMock(IntegrationHealthStateRepository::class);
        $repository->method('get')->willReturn($state);
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $report = (new Evaluator($repository))->heartbeatRetiredIfPreviouslyReported(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            $this->now()
        );

        self::assertNotNull($report);
        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertSame(12, $report->sequenceNumber);

        self::assertSame(SignalStatus::Normal, $savedState->confirmedStatus);
        self::assertNull($savedState->pendingStatus);
        self::assertSame(13, $savedState->sequenceNumber);
    }

    /**
     * Retiring a source while an anomaly is confirmed must not heartbeat DOWN
     * forever: the source is gone, so it can never be observed recovering.
     */
    public function testHeartbeatRetiredDowngradesAnAnomalousConfirmedStatusToInsufficientDataAndKeepsIt(): void
    {
        $savedState = null;
        $repository = $this->createMock(IntegrationHealthStateRepository::class);
        $repository->method('get')->willReturn(
            $this->stateWith(confirmed: SignalStatus::SevereDrop, pending: null, sequence: 12)
        );
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $report = (new Evaluator($repository))->heartbeatRetiredIfPreviouslyReported(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            $this->now()
        );

        self::assertNotNull($report);
        self::assertSame(SignalStatus::InsufficientData, $report->status);
        self::assertSame(
            ReportReason::Heartbeat,
            $report->reason,
            'Retiring a signal must never alert, so the downgrade is not a transition.'
        );
        self::assertSame(SignalStatus::InsufficientData, $savedState->confirmedStatus);
        self::assertSame(13, $savedState->sequenceNumber);

        // The next retired tick heartbeats the downgraded status, not the anomaly again.
        $nextRepository = $this->createStub(IntegrationHealthStateRepository::class);
        $nextRepository->method('get')->willReturn($savedState);

        $nextReport = (new Evaluator($nextRepository))->heartbeatRetiredIfPreviouslyReported(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            $this->now()
        );

        self::assertNotNull($nextReport);
        self::assertSame(SignalStatus::InsufficientData, $nextReport->status);
        self::assertSame(ReportReason::Heartbeat, $nextReport->reason);
        self::assertSame(13, $nextReport->sequenceNumber);
    }

    /**
     * Bug: state is keyed on store view alone, so switching the monitored
     * source carried the old source's last_success_at forward and reported a
     * false OK for up to one expected interval.
     */
    public function testASourceChangeDiscardsTheOldSourcesEvidenceAndStillAdvancesTheSequence(): void
    {
        $state = new IntegrationHealthState(
            storeViewId: self::STORE_VIEW_ID,
            lastSuccessAt: $this->now()->modify('-5 minutes'),
            lastFailureAt: null,
            pendingStatus: SignalStatus::MildDrop,
            confirmedStatus: SignalStatus::Normal,
            sequenceNumber: 20,
            lastReportedReason: ReportReason::Heartbeat,
            sourceType: self::SOURCE_TYPE,
            sourceIdentifier: 'previously_monitored_cron',
            observingSince: $this->now()->modify('-1 year'),
        );

        $savedState = null;
        $repository = $this->createMock(IntegrationHealthStateRepository::class);
        $repository->method('get')->willReturn($state);
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $report = (new Evaluator($repository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            null,
            null,
            self::THRESHOLD_SECONDS,
            $this->now()
        );

        self::assertSame(
            SignalStatus::InsufficientData,
            $report->status,
            'The old source succeeding 5 minutes ago says nothing about the new one.'
        );
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertSame(20, $report->sequenceNumber);

        self::assertNull($savedState->lastSuccessAt);
        self::assertNull($savedState->lastFailureAt);
        self::assertNull($savedState->pendingStatus);
        self::assertSame(SignalStatus::InsufficientData, $savedState->confirmedStatus);
        self::assertSame(self::SOURCE_IDENTIFIER, $savedState->sourceIdentifier);
        self::assertEquals($this->now(), $savedState->observingSince);
        self::assertSame(
            21,
            $savedState->sequenceNumber,
            'The platform rejects a sequence number at or below its high-water mark, so a re-seed must not reset it.'
        );
    }

    /**
     * Switching away from a failing source must not read as the merchant's
     * problem being fixed: the carried-over SEVERE_DROP would otherwise
     * confirm NORMAL off the new source and send a "back to normal" alert.
     */
    public function testASourceChangeAwayFromAFailingSourceNeverReportsARecovery(): void
    {
        $failingState = new IntegrationHealthState(
            storeViewId: self::STORE_VIEW_ID,
            lastSuccessAt: null,
            lastFailureAt: $this->now()->modify('-5 minutes'),
            pendingStatus: null,
            confirmedStatus: SignalStatus::SevereDrop,
            sequenceNumber: 30,
            lastReportedReason: ReportReason::Transition,
            sourceType: self::SOURCE_TYPE,
            sourceIdentifier: 'previously_monitored_cron',
            observingSince: $this->now()->modify('-1 year'),
        );

        $seededState = null;
        $repository = $this->createMock(IntegrationHealthStateRepository::class);
        $repository->method('get')->willReturn($failingState);
        $repository->expects(self::once())->method('save')->with(self::captureInto($seededState));

        $seedTick = (new Evaluator($repository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            null,
            null,
            self::THRESHOLD_SECONDS,
            $this->now()
        );

        self::assertSame(SignalStatus::InsufficientData, $seedTick->status);
        self::assertSame(ReportReason::Heartbeat, $seedTick->reason);

        // The new source then succeeds twice: confirming NORMAL out of the
        // re-seed is warm-up finishing, so it must stay a heartbeat.
        $pendingNormal = new IntegrationHealthState(
            storeViewId: self::STORE_VIEW_ID,
            lastSuccessAt: $this->now(),
            lastFailureAt: null,
            pendingStatus: SignalStatus::Normal,
            confirmedStatus: $seededState->confirmedStatus,
            sequenceNumber: $seededState->sequenceNumber,
            lastReportedReason: ReportReason::Heartbeat,
            sourceType: $seededState->sourceType,
            sourceIdentifier: $seededState->sourceIdentifier,
            observingSince: $seededState->observingSince,
        );
        $confirmRepository = $this->createStub(IntegrationHealthStateRepository::class);
        $confirmRepository->method('get')->willReturn($pendingNormal);

        $confirmTick = (new Evaluator($confirmRepository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            $this->now(),
            null,
            self::THRESHOLD_SECONDS,
            $this->now()
        );

        self::assertSame(SignalStatus::Normal, $confirmTick->status);
        self::assertSame(
            ReportReason::Heartbeat,
            $confirmTick->reason,
            'A source change must not turn into a spurious recovery alert.'
        );
    }

    /**
     * Bug: with no evidence at all the raw status was DOWN regardless of the
     * configured interval, so a freshly configured daily job alerted about
     * two ticks after setup, before it could ever have run.
     */
    public function testAFreshlyConfiguredDailyJobStaysInsufficientDataInsideItsGraceWindow(): void
    {
        $savedState = null;
        $repository = $this->createMock(IntegrationHealthStateRepository::class);
        $repository->method('get')->willReturn($this->stateObservingSince($this->now()->modify('-2 hours')));
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        $report = (new Evaluator($repository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            null,
            null,
            86400,
            $this->now()
        );

        self::assertSame(SignalStatus::InsufficientData, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertNull(
            $savedState->pendingStatus,
            'Nothing may start debouncing towards SEVERE_DROP while the job is not yet due.'
        );
    }

    /**
     * The grace window only defers the DOWN verdict; a source that never runs
     * at all must still be reported.
     */
    public function testSevereDropIsStillReportedOnceTheGraceWindowHasElapsed(): void
    {
        $elapsed = $this->stateObservingSince($this->now()->modify('-1441 minutes'));

        $savedState = null;
        $repository = $this->createMock(IntegrationHealthStateRepository::class);
        $repository->method('get')->willReturn($elapsed);
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        (new Evaluator($repository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            null,
            null,
            1440,
            $this->now()
        );

        self::assertSame(SignalStatus::SevereDrop, $savedState->pendingStatus);

        $confirmRepository = $this->createStub(IntegrationHealthStateRepository::class);
        $confirmRepository->method('get')->willReturn($savedState);

        $confirmTick = (new Evaluator($confirmRepository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            null,
            null,
            1440,
            $this->now()
        );

        self::assertSame(SignalStatus::SevereDrop, $confirmTick->status);
        self::assertSame(ReportReason::Transition, $confirmTick->reason);
    }

    /**
     * A row written before observing_since existed must keep classifying as
     * it did, rather than going quiet on installs that upgrade.
     */
    public function testARowWithNoObservingSinceBehavesAsIfTheGraceWindowHadElapsed(): void
    {
        $savedState = null;
        $repository = $this->createMock(IntegrationHealthStateRepository::class);
        $repository->method('get')->willReturn($this->stateObservingSince(null));
        $repository->expects(self::once())->method('save')->with(self::captureInto($savedState));

        (new Evaluator($repository))->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            self::SOURCE_TYPE,
            self::SOURCE_IDENTIFIER,
            null,
            null,
            1440,
            $this->now()
        );

        self::assertSame(SignalStatus::SevereDrop, $savedState->pendingStatus);
    }

    private static function captureInto(&$variable): \PHPUnit\Framework\Constraint\Callback
    {
        return self::callback(function (IntegrationHealthState $state) use (&$variable) {
            $variable = $state;

            return true;
        });
    }

    /**
     * A state already observing the source under test, past its grace window,
     * so these cases exercise the debounce rather than the re-seed path.
     */
    private function stateWith(SignalStatus $confirmed, ?SignalStatus $pending, int $sequence): IntegrationHealthState
    {
        return new IntegrationHealthState(
            storeViewId: self::STORE_VIEW_ID,
            lastSuccessAt: null,
            lastFailureAt: null,
            pendingStatus: $pending,
            confirmedStatus: $confirmed,
            sequenceNumber: $sequence,
            lastReportedReason: null,
            sourceType: self::SOURCE_TYPE,
            sourceIdentifier: self::SOURCE_IDENTIFIER,
            observingSince: $this->now()->modify('-1 year'),
        );
    }

    /**
     * A seeded state for the source under test with no evidence yet, so the
     * grace window is the only thing separating INSUFFICIENT_DATA from DOWN.
     */
    private function stateObservingSince(?\DateTimeImmutable $observingSince): IntegrationHealthState
    {
        return new IntegrationHealthState(
            storeViewId: self::STORE_VIEW_ID,
            lastSuccessAt: null,
            lastFailureAt: null,
            pendingStatus: null,
            confirmedStatus: SignalStatus::InsufficientData,
            sequenceNumber: 4,
            lastReportedReason: ReportReason::Heartbeat,
            sourceType: self::SOURCE_TYPE,
            sourceIdentifier: self::SOURCE_IDENTIFIER,
            observingSince: $observingSince,
        );
    }

    private function freshState(): IntegrationHealthState
    {
        return new IntegrationHealthState(
            storeViewId: self::STORE_VIEW_ID,
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
