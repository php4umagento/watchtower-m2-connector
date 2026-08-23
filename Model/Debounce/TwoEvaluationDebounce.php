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
 * and IntegrationHealth\Evaluator once each held their own copy of this state
 * machine, and CronHealth\Evaluator's docblock warned that "a bug fixed here
 * probably exists in those copies too". That is now a single copy: every
 * evaluator that debounces -- CronHealth, RateSignal\DispersionEvaluator,
 * IntegrationHealth, CheckoutFailure and AdminAuthFailure -- calls decide()
 * here, so a fix like the first-evaluation seed below lands in one place for
 * all of them.
 */
class TwoEvaluationDebounce
{
    /**
     * Decides one tick.
     *
     * @param SignalStatus $rawStatus this tick's freshly computed status
     * @param SignalStatus|null $confirmedStatus last confirmed status; null means no evaluation has ever run
     * @param SignalStatus|null $pendingStatus status awaiting a second consecutive confirmation
     * @param bool $warmsUp whether this signal needs a baseline before its verdict is trustworthy;
     *     true (the default) for rate/ratio signals, false for threshold/state signals whose raw
     *     status is meaningful from the very first tick
     * @return DebounceDecision
     */
    public function decide(
        SignalStatus $rawStatus,
        ?SignalStatus $confirmedStatus,
        ?SignalStatus $pendingStatus,
        bool $warmsUp = true
    ): DebounceDecision {
        // First evaluation: nothing to debounce against. How to seed depends on
        // whether the signal warms up.
        if ($confirmedStatus === null) {
            // A warm-up signal (rate/ratio, integration health) needs a
            // baseline before any verdict can be trusted, so it seeds
            // INSUFFICIENT_DATA regardless of this tick's raw status. That
            // stops the very first tick after activation from reporting a false
            // anomaly, and confirming NORMAL out of the seed later is treated as
            // warm-up finishing, not a recovery (see confirmationReason()).
            if ($warmsUp) {
                return new DebounceDecision(
                    SignalStatus::InsufficientData,
                    ReportReason::Transition,
                    null,
                    SignalStatus::InsufficientData
                );
            }

            // A non-warm-up signal (admin_auth_failure, cron_health) has a
            // trustworthy reading immediately: zero failures is a real, healthy
            // NORMAL, not "not enough data yet". Seeding INSUFFICIENT_DATA there
            // would make it report a "Warming up" status its own rawStatus()
            // never produces and its spec promises it never emits -- the
            // production regression where admin_auth_failure paged "Warming up"
            // its first hour on a fresh install. Treat NORMAL as the seeded
            // baseline and fall through, so a healthy first hour reports NORMAL
            // as a heartbeat, while a first-hour anomaly still needs a second
            // consecutive tick to confirm rather than alerting on a single hour.
            $confirmedStatus = SignalStatus::Normal;
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
