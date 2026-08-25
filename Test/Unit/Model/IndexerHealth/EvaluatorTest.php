<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\IndexerHealth;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\HealthState\HealthState;
use Watchtower\Connector\Model\HealthState\HealthStateRepository;
use Watchtower\Connector\Model\IndexerHealth\Evaluator;
use Watchtower\Connector\Model\IndexerHealth\IndexerStateObserver;
use Watchtower\Connector\Model\IndexerHealth\Observation;

/**
 * indexer_health's state machine, in the same shape as CronHealth's own
 * EvaluatorTest: each test hand-builds the HealthState a prior tick would have
 * left, so the debounce is exercised one transition at a time.
 *
 * The distinctive thing being pinned here is that this signal judges DURATION,
 * not status. An indexer invalid for a few minutes after an import is normal
 * and must not alert; the same indexer still invalid hours later must.
 */
class EvaluatorTest extends TestCase
{
    private const NOW_STRING = '2026-08-13T15:00:00+00:00';

    /**
     * The whole reason this signal is duration-based. Magento invalidates
     * indexers constantly during ordinary catalogue work, and reindex runs on
     * the next cron tick, so a recently-invalid indexer is a store working
     * normally. Alerting on the bare status would page after every import.
     */
    public function testAnIndexerInvalidOnlyMinutesIsNormal(): void
    {
        $report = $this->evaluate(
            $this->observation($this->now()->modify('-10 minutes')),
            $this->stateWith(SignalStatus::Normal, null, 5)
        );

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
    }

    /**
     * Past the mild window the condition has stopped being ordinary catch-up,
     * but the first tick that sees it only stages the change: the two
     * evaluation debounce means a transition is reported on the SECOND
     * consecutive tick, not this one.
     */
    public function testAProlongedInvalidIndexerStagesRatherThanReportsOnItsFirstTick(): void
    {
        $savedState = null;
        $report = $this->evaluate(
            $this->observation($this->now()->modify('-2 hours')),
            $this->stateWith(SignalStatus::Normal, null, 5),
            $savedState
        );

        self::assertSame(SignalStatus::Normal, $report->status, 'still reporting the confirmed status');
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertSame(SignalStatus::MildDrop, $savedState->pendingStatus, 'staged for confirmation');
        self::assertSame(SignalStatus::Normal, $savedState->confirmedStatus);
        self::assertSame(6, $savedState->sequenceNumber);
    }

    /**
     * Second consecutive tick with the same raw status: now it transitions.
     */
    public function testASecondConsecutiveProlongedTickReportsTheTransition(): void
    {
        $savedState = null;
        $report = $this->evaluate(
            $this->observation($this->now()->modify('-2 hours')),
            $this->stateWith(SignalStatus::Normal, SignalStatus::MildDrop, 6),
            $savedState
        );

        self::assertSame(SignalStatus::MildDrop, $report->status);
        self::assertSame(ReportReason::Transition, $report->reason);
        self::assertSame(SignalStatus::MildDrop, $savedState->confirmedStatus);
        self::assertSame(7, $savedState->sequenceNumber);
    }

    /**
     * Long enough that the storefront has been serving stale data for most of
     * a working half-day, which is a different severity from lagging.
     */
    public function testAnIndexerInvalidPastTheSevereWindowEscalates(): void
    {
        $report = $this->evaluate(
            $this->observation($this->now()->modify('-8 hours')),
            $this->stateWith(SignalStatus::Normal, SignalStatus::SevereDrop, 6)
        );

        self::assertSame(SignalStatus::SevereDrop, $report->status);
        self::assertSame(ReportReason::Transition, $report->reason);
    }

    /**
     * Suspension deliberately skips the duration windows. A suspended view is
     * not draining and will not resume on its own, so waiting out a window
     * before saying so only delays the alert. It still debounces, like
     * everything else.
     */
    public function testASuspendedViewIsSevereRegardlessOfDuration(): void
    {
        $report = $this->evaluate(
            new Observation(unhealthySince: null, suspended: true),
            $this->stateWith(SignalStatus::Normal, SignalStatus::SevereDrop, 6)
        );

        self::assertSame(SignalStatus::SevereDrop, $report->status);
    }

    /**
     * The signal has no baseline to build, so its first tick ever on a healthy
     * store is a real NORMAL rather than a "Warming up" seed. This is the same
     * regression admin_auth_failure hit, and both share one debounce, so it is
     * worth pinning per signal.
     */
    public function testFirstEvaluationEverOfAHealthyStoreReportsNormalNotInsufficientData(): void
    {
        $report = $this->evaluate(
            new Observation(unhealthySince: null, suspended: false),
            $this->freshState()
        );

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertNotSame(SignalStatus::InsufficientData, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
    }

    /**
     * The wire shape. storeViewCode must be null because indexers are one set
     * per Magento installation: the platform routes this to
     * install_health_reports precisely on that being absent.
     */
    public function testTheReportIsInstallScopedAndCarriesNoStoreViewCode(): void
    {
        $report = $this->evaluate(
            new Observation(unhealthySince: null, suspended: false),
            $this->freshState()
        );

        self::assertNull($report->storeViewCode);
        self::assertSame('indexer_health', $report->eventType);
        self::assertSame(Evaluator::RULESET_VERSION, $report->rulesetVersion);
    }

    /**
     * Unlike cron_health, this evaluator persists no observation of its own:
     * indexer_state and mview_state are not purged, so their `updated` column
     * is the durable record and duplicating it here would be dead state that
     * could drift. Pinned so nobody "fixes" the nulls by reintroducing a
     * snapshot the spec says is unnecessary.
     */
    public function testItPersistsNoObservationOfItsOwn(): void
    {
        $savedState = null;
        $this->evaluate(
            $this->observation($this->now()->modify('-8 hours')),
            $this->freshState(),
            $savedState
        );

        self::assertNull($savedState->lastSuccessAt);
        self::assertNull($savedState->lastFailureAt);
    }

    /**
     * Runs one tick, optionally capturing the persisted HealthState.
     *
     * @param Observation $observation
     * @param HealthState $state
     * @param HealthState|null $savedState
     * @return \Watchtower\Connector\Model\Api\MetricReport
     */
    private function evaluate(Observation $observation, HealthState $state, &$savedState = null)
    {
        $repository = $this->createMock(HealthStateRepository::class);
        $repository->method('get')->willReturn($state);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(function (HealthState $saved) use (&$savedState) {
                $savedState = $saved;

                return true;
            }));

        $observer = $this->createStub(IndexerStateObserver::class);
        $observer->method('observe')->willReturn($observation);

        return (new Evaluator($observer, $repository))->evaluate($this->now());
    }

    /**
     * @param \DateTimeImmutable $since
     * @return Observation
     */
    private function observation(\DateTimeImmutable $since): Observation
    {
        return new Observation(unhealthySince: $since, suspended: false);
    }

    /**
     * @param SignalStatus $confirmed
     * @param SignalStatus|null $pending
     * @param int $sequence
     * @return HealthState
     */
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

    /**
     * @return HealthState
     */
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

    /**
     * @return \DateTimeImmutable
     */
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW_STRING);
    }
}
