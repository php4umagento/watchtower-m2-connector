<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Debounce;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Debounce\TwoEvaluationDebounce;

/**
 * The debounce state machine on its own, with no signal, no storage and no
 * clock. Every evaluator now shares this one copy, so testing it directly is
 * what proves the behaviour they all inherit -- most importantly what each
 * one does on its very first evaluation.
 */
class TwoEvaluationDebounceTest extends TestCase
{
    /**
     * A warm-up signal (the default: rate/ratio signals, integration health)
     * needs a baseline before its verdict can be trusted, so its first
     * evaluation seeds INSUFFICIENT_DATA regardless of the raw status -- even a
     * raw anomaly is withheld until a baseline exists. Confirming out of the
     * seed is handled by the warm-up tests further down.
     */
    public function testTheFirstEvaluationOfAWarmUpSignalSeedsInsufficientData(): void
    {
        $decision = (new TwoEvaluationDebounce())->decide(SignalStatus::SevereDrop, null, null);

        self::assertSame(SignalStatus::InsufficientData, $decision->reportStatus);
        self::assertSame(ReportReason::Transition, $decision->reportReason);
        self::assertSame(SignalStatus::InsufficientData, $decision->nextConfirmedStatus);
        self::assertNull($decision->nextPendingStatus);
    }

    /**
     * A non-warm-up signal (admin_auth_failure, cron_health) has a trustworthy
     * reading immediately: a healthy first hour is a real NORMAL, not "Warming
     * up". With warmsUp: false it must NOT seed INSUFFICIENT_DATA -- the
     * production regression where admin_auth_failure paged "Warming up" its
     * first hour on a fresh install.
     */
    public function testTheFirstEvaluationOfAHealthyNonWarmUpSignalSeedsNormalAsAHeartbeat(): void
    {
        $decision = (new TwoEvaluationDebounce())->decide(SignalStatus::Normal, null, null, warmsUp: false);

        self::assertSame(SignalStatus::Normal, $decision->reportStatus);
        self::assertSame(ReportReason::Heartbeat, $decision->reportReason);
        self::assertSame(SignalStatus::Normal, $decision->nextConfirmedStatus);
        self::assertNull($decision->nextPendingStatus);
    }

    /**
     * The other half: an anomaly on a non-warm-up signal's first tick still
     * must not page on a single hour. It arms the pending status against a
     * NORMAL seed and reports NORMAL as a heartbeat, so a second consecutive
     * anomalous tick is what confirms and alerts.
     */
    public function testTheFirstEvaluationOfAnAnomalousNonWarmUpSignalArmsPendingAgainstANormalSeed(): void
    {
        $decision = (new TwoEvaluationDebounce())->decide(SignalStatus::SevereDrop, null, null, warmsUp: false);

        self::assertSame(SignalStatus::Normal, $decision->reportStatus);
        self::assertSame(ReportReason::Heartbeat, $decision->reportReason);
        self::assertSame(SignalStatus::SevereDrop, $decision->nextPendingStatus);
        self::assertSame(SignalStatus::Normal, $decision->nextConfirmedStatus);
    }

    public function testAnUnchangedStatusIsAHeartbeat(): void
    {
        $decision = (new TwoEvaluationDebounce())->decide(
            SignalStatus::Normal,
            SignalStatus::Normal,
            null
        );

        self::assertSame(SignalStatus::Normal, $decision->reportStatus);
        self::assertSame(ReportReason::Heartbeat, $decision->reportReason);
    }

    /**
     * Two non-consecutive odd hours must not add up to a transition, so an
     * unchanged tick clears whatever was pending.
     */
    public function testAnUnchangedStatusClearsAStalePendingBlip(): void
    {
        $decision = (new TwoEvaluationDebounce())->decide(
            SignalStatus::Normal,
            SignalStatus::Normal,
            SignalStatus::SevereDrop
        );

        self::assertNull($decision->nextPendingStatus);
        self::assertSame(SignalStatus::Normal, $decision->nextConfirmedStatus);
    }

    public function testAFirstDifferingTickArmsThePendingStatusAndKeepsReportingTheOldOne(): void
    {
        $decision = (new TwoEvaluationDebounce())->decide(
            SignalStatus::SevereDrop,
            SignalStatus::Normal,
            null
        );

        self::assertSame(SignalStatus::Normal, $decision->reportStatus);
        self::assertSame(ReportReason::Heartbeat, $decision->reportReason);
        self::assertSame(SignalStatus::SevereDrop, $decision->nextPendingStatus);
        self::assertSame(SignalStatus::Normal, $decision->nextConfirmedStatus);
    }

    public function testASecondDifferingTickConfirmsTheTransition(): void
    {
        $decision = (new TwoEvaluationDebounce())->decide(
            SignalStatus::SevereDrop,
            SignalStatus::Normal,
            SignalStatus::SevereDrop
        );

        self::assertSame(SignalStatus::SevereDrop, $decision->reportStatus);
        self::assertSame(ReportReason::Transition, $decision->reportReason);
        self::assertNull($decision->nextPendingStatus);
    }

    /**
     * Confirming uses the CURRENT raw status, not the pending one. If it
     * required the two to match, a status alternating between MILD_DROP and
     * SEVERE_DROP would arm and re-arm forever and never report anything.
     */
    public function testAnAlternatingStatusStillConvergesRatherThanNeverConfirming(): void
    {
        $decision = (new TwoEvaluationDebounce())->decide(
            SignalStatus::MildDrop,
            SignalStatus::Normal,
            SignalStatus::SevereDrop
        );

        self::assertSame(SignalStatus::MildDrop, $decision->reportStatus);
        self::assertSame(ReportReason::Transition, $decision->reportReason);
    }

    /**
     * Warm-up finishing on a fresh install is not a recovery. Reporting it as
     * a transition makes the platform email "back to normal" to an account
     * that never had a problem.
     */
    public function testConfirmingNormalOutOfTheInsufficientDataSeedIsAHeartbeatNotARecovery(): void
    {
        $decision = (new TwoEvaluationDebounce())->decide(
            SignalStatus::Normal,
            SignalStatus::InsufficientData,
            SignalStatus::Normal
        );

        self::assertSame(SignalStatus::Normal, $decision->reportStatus);
        self::assertSame(ReportReason::Heartbeat, $decision->reportReason);
    }

    /**
     * The other half of that rule: a first-ever detection of a real problem
     * must still alert, even though it also comes out of the seed.
     */
    public function testConfirmingAnAnomalyOutOfTheSeedIsStillATransition(): void
    {
        $decision = (new TwoEvaluationDebounce())->decide(
            SignalStatus::SevereDrop,
            SignalStatus::InsufficientData,
            SignalStatus::SevereDrop
        );

        self::assertSame(SignalStatus::SevereDrop, $decision->reportStatus);
        self::assertSame(ReportReason::Transition, $decision->reportReason);
    }
}
