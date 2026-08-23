<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\AdminAuthFailure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\AdminAuthFailure\AdminAuthFailureObserver;
use Watchtower\Connector\Model\AdminAuthFailure\Evaluator;
use Watchtower\Connector\Model\AdminAuthFailure\InstallEventCounterRepository;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Debounce\TwoEvaluationDebounce;
use Watchtower\Connector\Model\HealthState\HealthState;
use Watchtower\Connector\Model\HealthState\HealthStateRepository;

class EvaluatorTest extends TestCase
{
    #[DataProvider('countProvider')]
    public function testClassifiesTheFailureCountAgainstTheFixedThresholds(int $count, SignalStatus $expected): void
    {
        $report = $this->evaluateWith($count, confirmed: $expected, pending: $expected);

        self::assertSame($expected, $report->status);
    }

    /**
     * @return array<string, array{0: int, 1: SignalStatus}>
     */
    public static function countProvider(): array
    {
        return [
            'zero failures' => [0, SignalStatus::Normal],
            'a couple of mistypes' => [2, SignalStatus::Normal],
            'just under the mild threshold' => [9, SignalStatus::Normal],
            'exactly at the mild threshold' => [10, SignalStatus::MildDrop],
            'between the two thresholds' => [17, SignalStatus::MildDrop],
            'exactly at the severe threshold' => [25, SignalStatus::SevereDrop],
            'a real credential-stuffing volume' => [200, SignalStatus::SevereDrop],
        ];
    }

    /**
     * Unlike CheckoutFailure\Evaluator, a quiet hour (zero failures) is a
     * real, healthy NORMAL, never INSUFFICIENT_DATA -- a count needs no
     * denominator to be meaningful.
     */
    public function testZeroFailuresIsNormalNotInsufficientData(): void
    {
        $report = $this->evaluateWith(0, confirmed: SignalStatus::Normal, pending: null);

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertNotSame(SignalStatus::InsufficientData, $report->status);
    }

    /**
     * The production regression: on a fresh install the first evaluation of a
     * quiet hour must report NORMAL, never INSUFFICIENT_DATA. This signal's
     * raw status is trustworthy from the first tick (zero failures is a real
     * healthy reading), so it must not inherit the warm-up seed the ratio
     * signals need -- Avalon Guns's owner was paged "Warming up" the first
     * hour admin_auth_failure reported, which this pins shut.
     */
    public function testTheFirstEvaluationOfAQuietInstallIsNormalNotWarmingUp(): void
    {
        $report = $this->evaluateWith(0, confirmed: null, pending: null);

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
    }

    /**
     * The other half: a burst of failures in the very first evaluated hour
     * still must not page on a single hour. It arms the pending status against
     * the NORMAL seed and heartbeats, so a second consecutive bad hour is what
     * confirms -- the same two-tick guarantee every later hour gets.
     */
    public function testTheFirstEvaluationWithFailuresArmsPendingWithoutPaging(): void
    {
        $saved = null;
        $report = $this->evaluateWith(50, confirmed: null, pending: null, saved: $saved);

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertSame(SignalStatus::SevereDrop, $saved->pendingStatus);
        self::assertSame(SignalStatus::Normal, $saved->confirmedStatus);
    }

    public function testASingleBadHourDoesNotTransitionOnItsOwn(): void
    {
        $saved = null;
        $report = $this->evaluateWith(50, confirmed: SignalStatus::Normal, pending: null, saved: $saved);

        self::assertSame(SignalStatus::Normal, $report->status);
        self::assertSame(ReportReason::Heartbeat, $report->reason);
        self::assertSame(SignalStatus::SevereDrop, $saved->pendingStatus);
    }

    public function testASecondConsecutiveBadHourConfirmsTheTransition(): void
    {
        $report = $this->evaluateWith(50, confirmed: SignalStatus::Normal, pending: SignalStatus::SevereDrop);

        self::assertSame(SignalStatus::SevereDrop, $report->status);
        self::assertSame(ReportReason::Transition, $report->reason);
    }

    public function testTheReportIsInstallScopedAndCarriesItsOwnRulesetVersion(): void
    {
        $report = $this->evaluateWith(0, confirmed: SignalStatus::Normal, pending: null);

        self::assertNull($report->storeViewCode);
        self::assertSame('admin_auth_failure', $report->eventType);
        self::assertSame(Evaluator::RULESET_VERSION, $report->rulesetVersion);
    }

    /**
     * HealthState's lastSuccessAt/lastFailureAt are cron_health's concept,
     * not this signal's -- pinned as always null on save, so a future
     * refactor cannot accidentally start writing meaning into fields this
     * evaluator does not use.
     */
    public function testNeverPersistsSuccessOrFailureTimestamps(): void
    {
        $saved = null;
        $this->evaluateWith(0, confirmed: SignalStatus::Normal, pending: null, saved: $saved);

        self::assertNull($saved->lastSuccessAt);
        self::assertNull($saved->lastFailureAt);
    }

    public function testTheSequenceNumberAdvancesOnEveryTick(): void
    {
        $saved = null;
        $report = $this->evaluateWith(
            0,
            confirmed: SignalStatus::Normal,
            pending: null,
            sequenceNumber: 7,
            saved: $saved
        );

        self::assertSame(7, $report->sequenceNumber);
        self::assertSame(8, $saved->sequenceNumber);
    }

    /**
     * The failure count must be read for the hour being evaluated, not
     * whatever hour happens to be current when evaluate() runs.
     */
    public function testFailuresAreCountedForTheEvaluatedHour(): void
    {
        $evaluatedHour = new \DateTimeImmutable('2026-08-23T14:00:00+00:00');
        $requestedHours = [];

        $counter = $this->createStub(InstallEventCounterRepository::class);
        $counter->method('countFor')->willReturnCallback(
            static function (string $eventName, \DateTimeImmutable $hour) use (&$requestedHours): int {
                $requestedHours[] = [$eventName, $hour->format('Y-m-d H:i:s')];

                return 0;
            }
        );

        $this->evaluator($counter, $this->stateRepository(SignalStatus::Normal, null, 1, $saved))
            ->evaluate($evaluatedHour, $evaluatedHour->modify('+1 hour'));

        self::assertSame([[AdminAuthFailureObserver::EVENT_NAME, '2026-08-23 14:00:00']], $requestedHours);
    }

    private function evaluateWith(
        int $count,
        ?SignalStatus $confirmed,
        ?SignalStatus $pending,
        int $sequenceNumber = 1,
        ?HealthState &$saved = null
    ) {
        $counter = $this->createStub(InstallEventCounterRepository::class);
        $counter->method('countFor')->willReturn($count);

        $repository = $this->stateRepository($confirmed, $pending, $sequenceNumber, $saved);

        return $this->evaluator($counter, $repository)->evaluate(
            new \DateTimeImmutable('2026-08-23T14:00:00+00:00'),
            new \DateTimeImmutable('2026-08-23T15:00:00+00:00')
        );
    }

    private function evaluator(InstallEventCounterRepository $counter, HealthStateRepository $repository): Evaluator
    {
        return new Evaluator($counter, $repository, new TwoEvaluationDebounce());
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
