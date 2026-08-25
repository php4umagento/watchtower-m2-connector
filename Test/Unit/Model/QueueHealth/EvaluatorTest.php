<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\QueueHealth;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\HealthState\HealthState;
use Watchtower\Connector\Model\HealthState\HealthStateRepository;
use Watchtower\Connector\Model\QueueHealth\Evaluator;
use Watchtower\Connector\Model\QueueHealth\Observation;
use Watchtower\Connector\Model\QueueHealth\QueueStateObserver;

/**
 * queue_health's state machine, in the same shape as IndexerHealth's own
 * EvaluatorTest: each test hand-builds the HealthState a prior tick would have
 * left, so the debounce is exercised one transition at a time.
 *
 * Two things distinguish this signal and are pinned here. It judges DURATION
 * of undrained work rather than queue depth, so a deep queue that is moving
 * must never alert. And its onset comes from two different places depending on
 * backend: MySQL supplies a real timestamp, AMQP supplies only "undrained
 * right now" and has to have its onset carried across ticks.
 */
class EvaluatorTest extends TestCase
{
    private const NOW_STRING = '2026-08-13T15:00:00+00:00';

    /**
     * The whole reason this signal is duration-based rather than depth-based.
     * Magento's consumers_runner spawns a consumer on the next cron minute, so
     * work that has only just been queued is a store working normally.
     */
    public function testWorkQueuedOnlyMinutesAgoIsNormal(): void
    {
        $report = $this->evaluate(
            $this->mysqlObservation($this->now()->modify('-10 minutes')),
            $this->stateWith(SignalStatus::Normal, null, 5)
        );

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
    }

    /**
     * Past the mild window the work has stopped being ordinary scheduling
     * latency, but the first tick that sees it only stages the change.
     */
    public function testProlongedUndrainedWorkStagesRatherThanReportsOnItsFirstTick(): void
    {
        $savedState = null;
        $report = $this->evaluate(
            $this->mysqlObservation($this->now()->modify('-2 hours')),
            $this->stateWith(SignalStatus::Normal, null, 5),
            $savedState
        );

        self::assertSame(SignalStatus::Normal, $report->status, 'still reporting the confirmed status');
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertSame(SignalStatus::MildDrop, $savedState->pendingStatus, 'staged for confirmation');
        self::assertSame(6, $savedState->sequenceNumber);
    }

    /**
     * Second consecutive tick with the same raw status: now it transitions.
     */
    public function testASecondConsecutiveProlongedTickReportsTheTransition(): void
    {
        $report = $this->evaluate(
            $this->mysqlObservation($this->now()->modify('-2 hours')),
            $this->stateWith(SignalStatus::Normal, SignalStatus::MildDrop, 6)
        );

        self::assertSame(SignalStatus::MildDrop, $report->status);
        self::assertSame(ReportReason::Transition, $report->reason);
    }

    /**
     * Long enough that stock levels, product URLs and config saves have been
     * unapplied for most of a working half-day.
     */
    public function testUndrainedWorkPastTheSevereWindowEscalates(): void
    {
        $report = $this->evaluate(
            $this->mysqlObservation($this->now()->modify('-8 hours')),
            $this->stateWith(SignalStatus::Normal, SignalStatus::SevereDrop, 6)
        );

        self::assertSame(SignalStatus::SevereDrop, $report->status);
        self::assertSame(ReportReason::Transition, $report->reason);
    }

    /**
     * The AMQP half has no onset of its own, so the first tick that sees an
     * unconsumed queue must start the clock at now rather than treating the
     * condition as already old. Getting this wrong would alert instantly on
     * the first poll after any connector install that happens to catch a queue
     * mid-spawn.
     */
    public function testAnAmqpStallStartsItsClockAtTheFirstTickThatSeesIt(): void
    {
        $savedState = null;
        $report = $this->evaluate(
            $this->amqpObservation(),
            $this->stateWith(SignalStatus::Normal, null, 5),
            $savedState
        );

        self::assertSame(SignalStatus::Normal, $report->status, 'nothing is known to be old yet');
        self::assertEquals($this->now(), $savedState->lastFailureAt, 'clock started');
    }

    /**
     * ...and once carried forward far enough, the same condition is old enough
     * to report. This is the half of the signal that cannot work without
     * persistence, which is why this evaluator keeps state where
     * indexer_health deliberately does not.
     */
    public function testACarriedForwardAmqpStallEventuallyReportsAsUnhealthy(): void
    {
        $state = new HealthState(
            eventType: Evaluator::EVENT_TYPE,
            lastSuccessAt: null,
            lastFailureAt: $this->now()->modify('-8 hours'),
            pendingStatus: SignalStatus::SevereDrop,
            confirmedStatus: SignalStatus::Normal,
            sequenceNumber: 6,
        );

        $report = $this->evaluate($this->amqpObservation(), $state);

        self::assertSame(SignalStatus::SevereDrop, $report->status);
        self::assertSame(ReportReason::Transition, $report->reason);
    }

    /**
     * A queue that drains and later stalls again must start a fresh clock. If
     * lastFailureAt survived the healthy tick in between, the next stall would
     * inherit the first one's age and alert immediately instead of debouncing.
     */
    public function testAHealthyTickClearsTheCarriedForwardOnset(): void
    {
        $savedState = null;
        $this->evaluate(
            new Observation(undrainedSince: null, undrainedWithoutOnset: false),
            new HealthState(
                eventType: Evaluator::EVENT_TYPE,
                lastSuccessAt: null,
                lastFailureAt: $this->now()->modify('-8 hours'),
                pendingStatus: null,
                confirmedStatus: SignalStatus::Normal,
                sequenceNumber: 6,
            ),
            $savedState
        );

        self::assertNull($savedState->lastFailureAt);
        self::assertEquals($this->now(), $savedState->lastSuccessAt);
    }

    /**
     * Both backends stalled at once: the OLDER onset wins, so a queue stuck
     * for hours is not masked by one that only just stopped.
     */
    public function testTheOldestOnsetAcrossBothBackendsWins(): void
    {
        $state = new HealthState(
            eventType: Evaluator::EVENT_TYPE,
            lastSuccessAt: null,
            lastFailureAt: $this->now()->modify('-10 minutes'),
            pendingStatus: SignalStatus::SevereDrop,
            confirmedStatus: SignalStatus::Normal,
            sequenceNumber: 6,
        );

        $report = $this->evaluate(
            new Observation(
                undrainedSince: $this->now()->modify('-8 hours'),
                undrainedWithoutOnset: true,
            ),
            $state
        );

        self::assertSame(SignalStatus::SevereDrop, $report->status);
    }

    /**
     * The signal has no baseline to build, so its first tick ever on a healthy
     * store is a real NORMAL rather than a "Warming up" seed. Same regression
     * admin_auth_failure hit; pinned per signal because they share a debounce.
     */
    public function testFirstEvaluationEverOfAHealthyStoreReportsNormalNotInsufficientData(): void
    {
        $report = $this->evaluate(
            new Observation(undrainedSince: null, undrainedWithoutOnset: false),
            $this->freshState()
        );

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertNotSame(SignalStatus::InsufficientData, $report->status);
    }

    /**
     * The wire shape. storeViewCode must be null because queues are declared
     * per Magento application instance: the platform routes this to
     * install_health_reports precisely on that being absent.
     */
    public function testTheReportIsInstallScopedAndCarriesNoStoreViewCode(): void
    {
        $report = $this->evaluate(
            new Observation(undrainedSince: null, undrainedWithoutOnset: false),
            $this->freshState()
        );

        self::assertNull($report->storeViewCode);
        self::assertSame('queue_health', $report->eventType);
        self::assertSame(Evaluator::RULESET_VERSION, $report->rulesetVersion);
    }

    /**
     * The affected queue names are collected for the merchant's own admin and
     * CLI, and must never reach the wire: which queue is backed up implies
     * which part of the business is busy. MetricReport has no field for them,
     * and this pins that nothing smuggles them into one that exists.
     *
     * Companion to LeakTest, which asserts the same thing over the whole
     * submitted payload rather than this one report.
     */
    public function testAffectedQueueNamesNeverReachTheReport(): void
    {
        $report = $this->evaluate(
            new Observation(
                undrainedSince: $this->now()->modify('-8 hours'),
                undrainedWithoutOnset: false,
                affectedQueues: ['async.operations.all', 'inventory.indexer.stock'],
            ),
            $this->stateWith(SignalStatus::Normal, SignalStatus::SevereDrop, 6)
        );

        foreach (get_object_vars($report) as $value) {
            self::assertStringNotContainsString('async.operations.all', (string) json_encode($value));
            self::assertStringNotContainsString('inventory.indexer.stock', (string) json_encode($value));
        }
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

        $observer = $this->createStub(QueueStateObserver::class);
        $observer->method('observe')->willReturn($observation);

        return (new Evaluator($observer, $repository))->evaluate($this->now());
    }

    /**
     * An observation from the MySQL backend, which supplies a real onset.
     *
     * @param \DateTimeImmutable $since
     * @return Observation
     */
    private function mysqlObservation(\DateTimeImmutable $since): Observation
    {
        return new Observation(undrainedSince: $since, undrainedWithoutOnset: false);
    }

    /**
     * An observation from the AMQP backend, which supplies no onset at all.
     *
     * @return Observation
     */
    private function amqpObservation(): Observation
    {
        return new Observation(undrainedSince: null, undrainedWithoutOnset: true);
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
