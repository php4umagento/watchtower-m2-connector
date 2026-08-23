<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\AdminAuthFailure;

use Watchtower\Connector\Model\Api\MetricReport;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Debounce\TwoEvaluationDebounce;
use Watchtower\Connector\Model\HealthState\HealthState;
use Watchtower\Connector\Model\HealthState\HealthStateRepository;

/**
 * The admin_auth_failure signal: how many Magento admin sign-in attempts
 * failed in the evaluated hour, install-wide (connector-metrics-spec.md
 * v2.8, "Threshold-Based Signals").
 *
 * A count, not a ratio. checkout_failure divides by attempts because a
 * healthy denominator is meaningful there; here it is not. An administrator
 * who mistypes a password twice and signs in on the third try is entirely
 * ordinary, and as a ratio that is 67% failed. An absolute count set above
 * plausible human error does not have that problem, and it needs no
 * denominator at all -- no "successful admin login" term to look up.
 *
 * INSTALL-SCOPED. Reuses HealthState\HealthStateRepository, the same
 * storage CronHealth\Evaluator uses, rather than RateSignal\DispersionState
 * (keyed by store view) which does not fit: there is one Magento admin per
 * install, not one per store view. HealthState's lastSuccessAt/lastFailureAt
 * fields are cron_health's concept of state, not this signal's -- this
 * evaluator always persists them as null. They exist on the shared value
 * object, not because this signal needs them.
 *
 * NEVER SEEDED, NEVER WARMS UP. Same reasoning as CheckoutFailure\Evaluator:
 * a fixed threshold needs no history, so this signal is live in its first
 * hour. Unlike checkout_failure, INSUFFICIENT_DATA is never reported here at
 * all -- see rawStatus().
 */
class Evaluator
{
    public const EVENT_TYPE = 'admin_auth_failure';
    public const RULESET_VERSION = '1.0.0';

    /**
     * Failure counts at or above these report MILD_DROP and SEVERE_DROP.
     *
     * UNCALIBRATED, deliberately conservative, and open the same way
     * CheckoutFailure\Evaluator's ratio thresholds are (see
     * connector-failure-signals-prd.md, Q1). No real install's admin
     * sign-in traffic has been observed to fit these against. Automated
     * credential-stuffing attempts against a real target typically run to
     * dozens or hundreds of attempts an hour; a frustrated legitimate admin
     * rarely exceeds single digits. These sit between the two, favoring
     * fewer false alerts over faster detection, matching the metrics spec's
     * stated preference for this class of signal.
     *
     * Revisit against real ingested data before treating either as settled.
     *
     * Kept FIXED, unlike checkout_failure, which learns a tighter per-store
     * threshold (LearnedThresholdCalculator). That mechanism's median
     * statistic does not transfer here: admin sign-in failures are sparse and
     * bursty, so essentially every install's median hourly failure count is
     * zero and a learned threshold would collapse onto a single floor,
     * differentiating no install from any other. Detecting a burst against a
     * quiet baseline needs a high-percentile statistic rather than a median;
     * that is a separate mechanism, deferred until there is real data to
     * design and calibrate it against.
     */
    private const MILD_FAILURE_THRESHOLD = 10;
    private const SEVERE_FAILURE_THRESHOLD = 25;

    /**
     * @param InstallEventCounterRepository $eventCounterRepository
     * @param HealthStateRepository $repository reused: keyed by event_type alone,
     *     which fits an install-scoped signal exactly
     * @param TwoEvaluationDebounce $debounce
     */
    public function __construct(
        private readonly InstallEventCounterRepository $eventCounterRepository,
        private readonly HealthStateRepository $repository,
        private readonly TwoEvaluationDebounce $debounce,
    ) {
    }

    /**
     * Runs one debounce tick and returns the report to submit.
     *
     * @param \DateTimeImmutable $evaluatedHour top-of-hour instant of the completed hour being evaluated
     * @param \DateTimeImmutable $evaluatedAt wall-clock instant this evaluation ran
     * @return MetricReport
     */
    public function evaluate(\DateTimeImmutable $evaluatedHour, \DateTimeImmutable $evaluatedAt): MetricReport
    {
        $state = $this->repository->get(self::EVENT_TYPE);
        $failureCount = $this->eventCounterRepository->countFor(
            AdminAuthFailureObserver::EVENT_NAME,
            $evaluatedHour
        );

        $rawStatus = $this->rawStatus($failureCount);
        // warmsUp: false -- a fixed failure-count threshold needs no baseline,
        // so this signal is live in its first hour and never reports a
        // "Warming up" seed (see rawStatus(), which never returns
        // INSUFFICIENT_DATA).
        $decision = $this->debounce->decide($rawStatus, $state->confirmedStatus, $state->pendingStatus, warmsUp: false);

        $this->repository->save(new HealthState(
            eventType: self::EVENT_TYPE,
            lastSuccessAt: null,
            lastFailureAt: null,
            pendingStatus: $decision->nextPendingStatus,
            confirmedStatus: $decision->nextConfirmedStatus,
            sequenceNumber: $state->sequenceNumber + 1,
            lastReportedReason: $decision->reportReason,
        ));

        return new MetricReport(
            storeViewCode: null,
            eventType: self::EVENT_TYPE,
            status: $decision->reportStatus,
            sequenceNumber: $state->sequenceNumber,
            evaluatedAt: $evaluatedAt,
            reason: $decision->reportReason,
            rulesetVersion: self::RULESET_VERSION,
        );
    }

    /**
     * Classifies one hour's failure count against the fixed thresholds.
     *
     * Never INSUFFICIENT_DATA, unlike CheckoutFailure\Evaluator: a count
     * needs no denominator to be meaningful, so zero failures in an hour is
     * a real, healthy NORMAL observation, not an absence of information.
     *
     * @param int $failureCount
     * @return SignalStatus
     */
    private function rawStatus(int $failureCount): SignalStatus
    {
        if ($failureCount >= self::SEVERE_FAILURE_THRESHOLD) {
            return SignalStatus::SevereDrop;
        }

        if ($failureCount >= self::MILD_FAILURE_THRESHOLD) {
            return SignalStatus::MildDrop;
        }

        return SignalStatus::Normal;
    }
}
