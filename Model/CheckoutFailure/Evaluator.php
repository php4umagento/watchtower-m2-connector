<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\CheckoutFailure;

use Watchtower\Connector\Model\Api\MetricReport;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Debounce\TwoEvaluationDebounce;
use Watchtower\Connector\Model\EventCounter\CheckoutFailureObserver;
use Watchtower\Connector\Model\EventCounter\EventCounterRepository;
use Watchtower\Connector\Model\RateSignal\DispersionState;
use Watchtower\Connector\Model\RateSignal\DispersionStateRepository;

/**
 * The checkout_failure signal: what share of this hour's order-placement
 * attempts failed (connector-metrics-spec.md v2.7, "Ratio-Based Signals").
 *
 * A FOURTH computation shape, not a sixth rate-based signal, and the reason is
 * arithmetic rather than taste. Failures sit at or near zero in a healthy
 * hour, so a median/MAD baseline over that series has MAD = 0 and the modified
 * z-score DispersionEvaluator relies on is undefined or saturated. The
 * dispersion detector cannot run on this data at all.
 *
 * Expressed as a proportion of attempts it needs no baseline, which buys the
 * property that actually matters: this signal is live in its first hour, on a
 * store of any size. checkout's drop detection needs a full baseline window
 * and is documented as unvalidated below roughly 20 orders/day
 * (connector-baseline-seasonality.md), so on a small store a dead payment
 * gateway is currently close to undetectable. Here it is three failed attempts.
 *
 * NEVER SEEDED, NEVER WARMS UP. HistorySeeder does not know about this
 * category and must not learn: there is no history to read, and none is
 * needed. INSUFFICIENT_DATA here means "too few attempts this hour to judge a
 * proportion", never "still collecting history", which is why the platform's
 * TrackedMetricEvent::hasBaseline() returns false for it and no completion
 * date is ever projected.
 */
class Evaluator
{
    public const EVENT_TYPE = 'checkout_failure';
    public const RULESET_VERSION = '1.0.0';

    /**
     * Failure ratios at or above these report MILD_DROP and SEVERE_DROP.
     *
     * UNCALIBRATED, deliberately conservative, and the open question this
     * signal ships with (see connector-failure-signals-prd.md Q1). A healthy
     * store's failure ratio is NOT zero: 3D Secure abandonment, genuine card
     * declines surfaced as exceptions, and address-validation rejections all
     * land in the numerator. Published card-not-present decline rates sit in
     * the low tens of percent, and we have no real store's data to fit
     * against yet.
     *
     * So these are set well above plausible background noise rather than at
     * the most sensitive value that could work. That follows the metrics
     * spec's own stated preference for this class of signal: a false "checkout
     * is down" alert destroys merchant trust faster than a slower real
     * detection does. A total outage is 100% and clears both of these with
     * enormous margin; the cost of the conservatism is only in partial
     * degradations, and the consecutive-failure rule below still catches the
     * total ones on any store.
     *
     * Revisit against real ingested data before treating either as settled.
     */
    private const MILD_FAILURE_RATIO = 0.25;
    private const SEVERE_FAILURE_RATIO = 0.50;

    /**
     * Below this many attempts in the hour, a ratio is meaningless: one
     * attempt that failed is 100%. Mirrors DispersionEvaluator::VOLUME_FLOOR,
     * which draws the same line for the same reason.
     */
    private const MIN_ATTEMPTS_FOR_RATIO = 5;

    /**
     * Consecutive failures with zero successes that emit SEVERE_DROP even
     * below the attempt floor.
     *
     * This is what makes the signal work on a low-volume store, and it is the
     * direct analogue of the inter-arrival substitution D6 uses for checkout:
     * change the statistic rather than widen the window. Three attempts in an
     * hour that ALL failed, with nothing succeeding, is not plausible noise on
     * any store; three is low enough to fire inside one evaluation cycle and
     * high enough that a single unlucky decline pair cannot trigger it.
     */
    private const CONSECUTIVE_FAILURES_FOR_OUTAGE = 3;

    /**
     * @param EventCounterRepository $eventCounterRepository
     * @param DispersionStateRepository $repository reused: keyed (store view, category), which fits exactly
     * @param TwoEvaluationDebounce $debounce
     */
    public function __construct(
        private readonly EventCounterRepository $eventCounterRepository,
        private readonly DispersionStateRepository $repository,
        private readonly TwoEvaluationDebounce $debounce,
    ) {
    }

    /**
     * Runs one debounce tick for one store view and returns the report to submit.
     *
     * @param int $storeViewId
     * @param string $storeViewCode
     * @param int $orderCount the same hour's successful order count, from the checkout signal's own reader
     * @param \DateTimeImmutable $evaluatedHour top-of-hour instant of the completed hour being evaluated
     * @param \DateTimeImmutable $evaluatedAt wall-clock instant this evaluation ran
     * @return MetricReport
     */
    public function evaluate(
        int $storeViewId,
        string $storeViewCode,
        int $orderCount,
        \DateTimeImmutable $evaluatedHour,
        \DateTimeImmutable $evaluatedAt
    ): MetricReport {
        $state = $this->repository->get($storeViewId, self::EVENT_TYPE);
        $failureCount = $this->eventCounterRepository->countFor(
            $storeViewId,
            CheckoutFailureObserver::EVENT_NAME,
            $evaluatedHour
        );

        $rawStatus = $this->rawStatus($failureCount, $orderCount);
        $decision = $this->debounce->decide($rawStatus, $state->confirmedStatus, $state->pendingStatus);

        $this->repository->save(new DispersionState(
            storeViewId: $storeViewId,
            category: self::EVENT_TYPE,
            pendingStatus: $decision->nextPendingStatus,
            confirmedStatus: $decision->nextConfirmedStatus,
            sequenceNumber: $state->sequenceNumber + 1,
            lastReportedReason: $decision->reportReason,
        ));

        return new MetricReport(
            storeViewCode: $storeViewCode,
            eventType: self::EVENT_TYPE,
            status: $decision->reportStatus,
            sequenceNumber: $state->sequenceNumber,
            evaluatedAt: $evaluatedAt,
            reason: $decision->reportReason,
            rulesetVersion: self::RULESET_VERSION,
        );
    }

    /**
     * Classifies one hour's failures against that hour's total attempts.
     *
     * Polarity is deliberately the DROP pair rather than a spike: the business
     * meaning of a rising failure ratio is "checkout is degrading", and the
     * platform's alert copy is written around drops. Introducing an inverted
     * polarity would be a wire change for no gain.
     *
     * @param int $failureCount
     * @param int $orderCount
     * @return SignalStatus
     */
    private function rawStatus(int $failureCount, int $orderCount): SignalStatus
    {
        $attempts = $failureCount + $orderCount;

        if ($attempts === 0) {
            return SignalStatus::InsufficientData;
        }

        if ($attempts < self::MIN_ATTEMPTS_FOR_RATIO) {
            // Too thin for a proportion, but an all-failed hour is still
            // unambiguous. Requires zero successes: one order getting through
            // means checkout is not down, whatever else failed.
            $isTotalFailure = $orderCount === 0 && $failureCount >= self::CONSECUTIVE_FAILURES_FOR_OUTAGE;

            return $isTotalFailure ? SignalStatus::SevereDrop : SignalStatus::InsufficientData;
        }

        $ratio = $failureCount / $attempts;

        if ($ratio >= self::SEVERE_FAILURE_RATIO) {
            return SignalStatus::SevereDrop;
        }

        if ($ratio >= self::MILD_FAILURE_RATIO) {
            return SignalStatus::MildDrop;
        }

        return SignalStatus::Normal;
    }
}
