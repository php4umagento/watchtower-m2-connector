<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Debounce;

use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;

/**
 * The two-evaluation debounce every signal shares, as one pure function.
 *
 * A raw status must hold for two consecutive ticks before it is reported as a
 * transition. Until then the previous confirmed status keeps going out as a
 * heartbeat. This is what stops a single anomalous hour from paging a
 * merchant.
 *
 * WHY THIS EXISTS AS A CLASS. CronHealth\Evaluator, RateSignal\DispersionEvaluator
 * and IntegrationHealth\Evaluator each hold their own copy of this state
 * machine, and CronHealth\Evaluator's docblock has warned for some time that
 * "a bug fixed here probably exists in those copies too". CheckoutFailure
 * would have been the fourth copy. It uses this instead.
 *
 * The three older evaluators have NOT been migrated onto it: they are on a
 * live alerting path with heavy test coverage built around their current
 * shape, and moving them is a change worth making deliberately rather than as
 * a side effect of adding a signal. Until that happens the duplication warning
 * still stands, and this class is the destination when someone takes it on.
 */
class TwoEvaluationDebounce
{
    /**
     * Decides one tick.
     *
     * @param SignalStatus $rawStatus this tick's freshly computed status
     * @param SignalStatus|null $confirmedStatus last confirmed status; null means no evaluation has ever run
     * @param SignalStatus|null $pendingStatus status awaiting a second consecutive confirmation
     * @return DebounceDecision
     */
    public function decide(
        SignalStatus $rawStatus,
        ?SignalStatus $confirmedStatus,
        ?SignalStatus $pendingStatus
    ): DebounceDecision {
        // First evaluation: nothing to debounce against. Seeding
        // INSUFFICIENT_DATA as the confirmed baseline stops the very first
        // tick after activation from reporting a false anomaly.
        if ($confirmedStatus === null) {
            return new DebounceDecision(
                SignalStatus::InsufficientData,
                ReportReason::Transition,
                null,
                SignalStatus::InsufficientData
            );
        }

        // No change. Clear any stale pending status left by a raw blip that
        // never confirmed, so two non-consecutive odd hours cannot add up to
        // a transition.
        if ($rawStatus === $confirmedStatus) {
            return new DebounceDecision(
                $confirmedStatus,
                ReportReason::Heartbeat,
                null,
                $confirmedStatus
            );
        }

        // Second consecutive differing tick: confirm the CURRENT raw status,
        // even where it differs from the pending one. Requiring the two to
        // match would let an alternating status (MILD_DROP, SEVERE_DROP, ...)
        // never converge.
        if ($pendingStatus !== null) {
            return new DebounceDecision(
                $rawStatus,
                $this->confirmationReason($confirmedStatus, $rawStatus),
                null,
                $rawStatus
            );
        }

        // First differing tick: start the confirmation counter, keep
        // reporting the old confirmed value.
        return new DebounceDecision(
            $confirmedStatus,
            ReportReason::Heartbeat,
            $rawStatus,
            $confirmedStatus
        );
    }

    /**
     * Whether confirming this status counts as a transition worth alerting on.
     *
     * Confirming NORMAL straight out of the INSUFFICIENT_DATA seed is warm-up
     * finishing on a fresh install, not a recovery: nothing was ever wrong.
     * Reporting it as a transition makes the platform send an unconditional
     * "back to normal" notification to an account that never had a problem.
     * Confirming an anomalous status out of the seed is a genuine
     * first detection, so that stays a transition and still alerts.
     *
     * @param SignalStatus $confirmedStatus
     * @param SignalStatus $rawStatus
     * @return ReportReason
     */
    private function confirmationReason(SignalStatus $confirmedStatus, SignalStatus $rawStatus): ReportReason
    {
        $isWarmUpCompleting = $confirmedStatus === SignalStatus::InsufficientData
            && $rawStatus === SignalStatus::Normal;

        return $isWarmUpCompleting ? ReportReason::Heartbeat : ReportReason::Transition;
    }
}
