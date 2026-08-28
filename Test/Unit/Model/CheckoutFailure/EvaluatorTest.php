<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\CheckoutFailure;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\CheckoutFailure\Evaluator;
use Watchtower\Connector\Model\CheckoutFailure\RatioHistory;
use Watchtower\Connector\Model\Debounce\TwoEvaluationDebounce;
use Watchtower\Connector\Model\EventCounter\EventCounterRepository;
use Watchtower\Connector\Model\RateSignal\DispersionState;
use Watchtower\Connector\Model\RateSignal\DispersionStateRepository;
use Watchtower\Connector\Model\Threshold\LearnedThresholdCalculator;

class EvaluatorTest extends TestCase
{
    private const STORE_VIEW_ID = 7;
    private const STORE_VIEW_CODE = 'default';

    /**
     * The whole point of the signal: a total outage is unambiguous even on a
     * store far too small for checkout's drop detection to say anything.
     */
    public function testThreeFailuresAndNoOrdersIsASevereDropEvenBelowTheAttemptFloor(): void
    {
        $report = $this->evaluateWith(
            failures: 3,
            orders: 0,
            confirmed: SignalStatus::Normal,
            pending: SignalStatus::Normal
        );

        self::assertSame(SignalStatus::SevereDrop, $report->status);
    }

    /**
     * One order getting through means checkout is not down, whatever else
     * failed. Without this the signal would page on a couple of ordinary
     * declines during a quiet hour.
     */
    public function testASingleSuccessfulOrderPreventsTheLowVolumeOutageRule(): void
    {
        $report = $this->evaluateWith(
            failures: 3,
            orders: 1,
            confirmed: SignalStatus::Normal,
            pending: SignalStatus::Normal
        );

        self::assertSame(SignalStatus::InsufficientData, $report->status);
    }

    public function testTwoFailuresAloneIsNotYetAnOutage(): void
    {
        $report = $this->evaluateWith(
            failures: 2,
            orders: 0,
            confirmed: SignalStatus::Normal,
            pending: SignalStatus::Normal
        );

        self::assertSame(SignalStatus::InsufficientData, $report->status);
    }

    /**
     * A quiet hour with nothing happening at all must not read as healthy;
     * "no attempts" is genuinely no information.
     */
    public function testAnHourWithNoAttemptsAtAllIsInsufficientDataNotNormal(): void
    {
        $report = $this->evaluateWith(
            failures: 0,
            orders: 0,
            confirmed: SignalStatus::Normal,
            pending: SignalStatus::Normal
        );

        self::assertSame(SignalStatus::InsufficientData, $report->status);
    }

    /**
     * Cases are looped rather than fed through a data provider, so this suite
     * runs on the PHPUnit shipping with 2.4.7 and 2.4.8 as well as 2.4.9.
     * Attribute providers need PHPUnit 10+, annotation providers were removed
     * in 12, so neither syntax spans all three.
     */
    public function testClassifiesTheFailureRatioOnceThereAreEnoughAttempts(): void
    {
        foreach (self::ratioCases() as $case => [$failures, $orders, $expected]) {
            $report = $this->evaluateWith($failures, $orders, confirmed: $expected, pending: $expected);

            self::assertSame($expected, $report->status, $case);
        }
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: SignalStatus}>
     */
    private static function ratioCases(): array
    {
        return [
            // Background decline noise must stay quiet: the thresholds are
            // deliberately set above plausible card-not-present decline rates.
            '1 of 10 attempts, ordinary declines' => [1, 9, SignalStatus::Normal],
            '2 of 10 attempts' => [2, 8, SignalStatus::Normal],
            'exactly at the mild threshold' => [25, 75, SignalStatus::MildDrop],
            '3 of 10 attempts' => [3, 7, SignalStatus::MildDrop],
            'exactly at the severe threshold' => [5, 5, SignalStatus::SevereDrop],
            'total outage with volume' => [40, 0, SignalStatus::SevereDrop],
            'healthy busy hour' => [0, 200, SignalStatus::Normal],
        ];
    }

    /**
     * The signal is live in its first hour, so a merchant must never be shown
     * a warm-up story for it. This pins that the evaluator reaches a real
     * verdict off nothing but the current hour, with no history of any kind.
     */
    public function testReachesARealVerdictWithNoHistoryWhatsoever(): void
    {
        // Second tick (a confirmed status exists) but zero stored history:
        // no rollups, no baseline, no seeding.
        $report = $this->evaluateWith(
            failures: 8,
            orders: 2,
            confirmed: SignalStatus::SevereDrop,
            pending: null
        );

        self::assertSame(SignalStatus::SevereDrop, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
    }

    public function testTheFirstEvaluationReportsInsufficientDataRatherThanAVerdict(): void
    {
        $report = $this->evaluateWith(
            failures: 10,
            orders: 0,
            confirmed: null,
            pending: null
        );

        self::assertSame(SignalStatus::InsufficientData, $report->status);
        self::assertSame(ReportReason::Transition, $report->reason);
    }

    /**
     * Two-evaluation debounce: one bad hour reports the old status and only
     * arms the transition.
     */
    public function testASingleBadHourDoesNotTransitionOnItsOwn(): void
    {
        $saved = null;
        $report = $this->evaluateWith(
            failures: 10,
            orders: 0,
            confirmed: SignalStatus::Normal,
            pending: null,
            saved: $saved
        );

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertSame(SignalStatus::SevereDrop, $saved->pendingStatus);
    }

    public function testASecondConsecutiveBadHourConfirmsTheTransition(): void
    {
        $report = $this->evaluateWith(
            failures: 10,
            orders: 0,
            confirmed: SignalStatus::Normal,
            pending: SignalStatus::SevereDrop
        );

        self::assertSame(SignalStatus::SevereDrop, $report->status);
        self::assertSame(ReportReason::Transition, $report->reason);
    }

    public function testTheReportIsStoreViewScopedAndCarriesItsOwnRulesetVersion(): void
    {
        $report = $this->evaluateWith(
            failures: 0,
            orders: 10,
            confirmed: SignalStatus::Normal,
            pending: null
        );

        self::assertSame(self::STORE_VIEW_CODE, $report->storeViewCode);
        self::assertSame('checkout_failure', $report->eventType);
        self::assertSame(Evaluator::RULESET_VERSION, $report->rulesetVersion);
    }

    public function testTheSequenceNumberAdvancesOnEveryTick(): void
    {
        $saved = null;
        $report = $this->evaluateWith(
            failures: 0,
            orders: 10,
            confirmed: SignalStatus::Normal,
            pending: null,
            sequenceNumber: 41,
            saved: $saved
        );

        self::assertSame(41, $report->sequenceNumber);
        self::assertSame(42, $saved->sequenceNumber);
    }

    /**
     * The numerator must come from the hour being evaluated, not from now.
     * Reading the wrong bucket would pair this hour's failures with last
     * hour's orders.
     */
    public function testFailuresAreReadForTheEvaluatedHourNotTheCurrentOne(): void
    {
        $evaluatedHour = new \DateTimeImmutable('2026-08-23T14:00:00+00:00');
        $requestedHours = [];

        $counter = $this->createStub(EventCounterRepository::class);
        $counter->method('countFor')->willReturnCallback(
            static function (int $id, string $name, \DateTimeImmutable $hour) use (&$requestedHours): int {
                $requestedHours[] = $hour->format('Y-m-d H:i:s');

                return 0;
            }
        );

        $this->evaluator($counter, $this->stateRepository(SignalStatus::Normal, null, 1, $saved))
            ->evaluate(
                self::STORE_VIEW_ID,
                self::STORE_VIEW_CODE,
                10,
                $evaluatedHour,
                $evaluatedHour->modify('+1 hour')
            );

        self::assertSame(['2026-08-23 14:00:00'], $requestedHours);
    }

    /**
     * The learned threshold in action: a store whose own history is all
     * clean hours tightens its severe threshold onto the floor (0.15), so a
     * 20% failure hour that the conservative fixed default (0.50) would call
     * NORMAL is now SEVERE_DROP. One failure in five attempts is a 20% ratio.
     */
    public function testACleanStoresLearnedThresholdTightensBelowTheFixedDefault(): void
    {
        $history = $this->ratioHistoryReturning(array_fill(0, 120, 0.0));

        $report = $this->evaluateWith(
            failures: 1,
            orders: 4,
            confirmed: SignalStatus::SevereDrop,
            pending: SignalStatus::SevereDrop,
            ratioHistory: $history
        );

        self::assertSame(SignalStatus::SevereDrop, $report->status);
    }

    /**
     * The control for the test above: the identical 20% hour, but with no
     * history, falls back to the fixed default and is NORMAL. This is what
     * proves the SEVERE_DROP above came from the learned threshold and not
     * from something else.
     */
    public function testTheSameHourIsNormalUnderTheFixedDefaultWithNoHistory(): void
    {
        $report = $this->evaluateWith(
            failures: 1,
            orders: 4,
            confirmed: SignalStatus::Normal,
            pending: SignalStatus::Normal
        );

        self::assertSame(SignalStatus::Normal, $report->status);
    }

    /**
     * The sample gate: below the learning minimum the fixed defaults still
     * apply, so the same 20% hour is NORMAL despite the (too short) clean
     * history. A store cannot tighten its threshold off a handful of hours.
     */
    public function testBelowTheLearningSampleFloorTheFixedDefaultsApply(): void
    {
        $history = $this->ratioHistoryReturning(array_fill(0, 50, 0.0));

        $report = $this->evaluateWith(
            failures: 1,
            orders: 4,
            confirmed: SignalStatus::Normal,
            pending: SignalStatus::Normal,
            ratioHistory: $history
        );

        self::assertSame(SignalStatus::Normal, $report->status);
    }

    /**
     * The clamp's safety half: a store whose own normal is high (median 30%)
     * would, unclamped, earn a very permissive threshold. Clamped to the
     * fixed default, a 40% hour is still MILD_DROP, never NORMAL -- learning
     * can only ever make the signal MORE sensitive than its conservative
     * default, never less.
     */
    public function testALearnedThresholdNeverLoosensPastTheFixedDefault(): void
    {
        $history = $this->ratioHistoryReturning(array_fill(0, 120, 0.30));

        $report = $this->evaluateWith(
            failures: 40,
            orders: 60,
            confirmed: SignalStatus::MildDrop,
            pending: SignalStatus::MildDrop,
            ratioHistory: $history
        );

        self::assertSame(SignalStatus::MildDrop, $report->status);
    }

    /**
     * @param int $failures
     * @param int $orders
     * @param SignalStatus|null $confirmed
     * @param SignalStatus|null $pending
     * @param int $sequenceNumber
     * @param DispersionState|null $saved
     * @param RatioHistory|null $ratioHistory
     * @return \Watchtower\Connector\Model\Api\MetricReport
     */
    private function evaluateWith(
        int $failures,
        int $orders,
        ?SignalStatus $confirmed,
        ?SignalStatus $pending,
        int $sequenceNumber = 1,
        ?DispersionState &$saved = null,
        ?RatioHistory $ratioHistory = null
    ) {
        $counter = $this->createStub(EventCounterRepository::class);
        $counter->method('countFor')->willReturn($failures);

        $repository = $this->stateRepository($confirmed, $pending, $sequenceNumber, $saved);

        return $this->evaluator($counter, $repository, $ratioHistory)->evaluate(
            self::STORE_VIEW_ID,
            self::STORE_VIEW_CODE,
            $orders,
            new \DateTimeImmutable('2026-08-23T14:00:00+00:00'),
            new \DateTimeImmutable('2026-08-23T15:00:00+00:00')
        );
    }

    private function evaluator(
        EventCounterRepository $counter,
        DispersionStateRepository $repository,
        ?RatioHistory $ratioHistory = null
    ): Evaluator {
        // Default: no history, so the calculator returns the fixed defaults
        // unchanged and every test below that predates the learned thresholds
        // keeps asserting against 0.25 / 0.50 exactly as before.
        if ($ratioHistory === null) {
            $ratioHistory = $this->createStub(RatioHistory::class);
            $ratioHistory->method('qualifyingRatios')->willReturn([]);
        }

        return new Evaluator(
            $counter,
            $repository,
            new TwoEvaluationDebounce(),
            $ratioHistory,
            new LearnedThresholdCalculator()
        );
    }

    /**
     * A RatioHistory stub returning a fixed series of past ratios, so the
     * learned-threshold tests can drive the calculator without touching the
     * database.
     *
     * @param float[] $ratios
     * @return RatioHistory
     */
    private function ratioHistoryReturning(array $ratios): RatioHistory
    {
        $history = $this->createStub(RatioHistory::class);
        $history->method('qualifyingRatios')->willReturn($ratios);

        return $history;
    }

    private function stateRepository(
        ?SignalStatus $confirmed,
        ?SignalStatus $pending,
        int $sequenceNumber,
        ?DispersionState &$saved
    ): DispersionStateRepository {
        $repository = $this->createStub(DispersionStateRepository::class);
        $repository->method('get')->willReturn(new DispersionState(
            storeViewId: self::STORE_VIEW_ID,
            category: 'checkout_failure',
            pendingStatus: $pending,
            confirmedStatus: $confirmed,
            sequenceNumber: $sequenceNumber,
        ));
        $repository->method('save')->willReturnCallback(
            static function (DispersionState $state) use (&$saved): void {
                $saved = $state;
            }
        );

        return $repository;
    }
}
