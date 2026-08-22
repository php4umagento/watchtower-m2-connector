<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

use Watchtower\Connector\Model\Api\MetricReport;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;

/**
 * The integration_health state machine: the same two-evaluation debounce and
 * OK/FAILED/DOWN classification as CronHealth\Evaluator, scoped per store view
 * and source-agnostic -- it only classifies the success/failure timestamps its
 * caller resolved (see Model/ReportingService.php for the dispatch).
 *
 * Deliberately duplicated rather than sharing a base class, so a bug fixed here
 * prompts checking CronHealth\Evaluator and RateSignal\DispersionEvaluator too.
 */
class Evaluator
{
    public const EVENT_TYPE = 'integration_health';

    /** Versioned independently of CronHealth\Evaluator and DispersionEvaluator. */
    public const RULESET_VERSION = '1.0.0';

    /**
     * @param IntegrationHealthStateRepository $repository
     */
    public function __construct(
        private readonly IntegrationHealthStateRepository $repository
    ) {
    }

    /**
     * Runs one debounce tick for one store view and returns the MetricReport to submit for it.
     *
     * @param int $storeViewId
     * @param string $storeViewCode
     * @param \DateTimeImmutable|null $observedSuccessAt latest success observed THIS tick, if any
     * @param \DateTimeImmutable|null $observedFailureAt latest failure observed THIS tick, if any
     * @param int $expectedMaxIntervalMinutes this store view's own configured expected-max-interval
     * @param \DateTimeImmutable $now
     * @return MetricReport
     */
    public function evaluate(
        int $storeViewId,
        string $storeViewCode,
        ?\DateTimeImmutable $observedSuccessAt,
        ?\DateTimeImmutable $observedFailureAt,
        int $expectedMaxIntervalMinutes,
        \DateTimeImmutable $now
    ): MetricReport {
        $state = $this->repository->get($storeViewId);

        // Carried forward across ticks: no source table keeps durable history
        // beyond roughly an hour, so rawStatus() must be able to compare against
        // a success/failure from several ticks ago.
        $lastSuccessAt = $observedSuccessAt ?? $state->lastSuccessAt;
        $lastFailureAt = $observedFailureAt ?? $state->lastFailureAt;
        $rawStatus = $this->rawStatus($lastSuccessAt, $lastFailureAt, $expectedMaxIntervalMinutes, $now);

        // First evaluation for this store view: nothing to debounce against yet.
        if ($state->isFirstEvaluation()) {
            $this->save(
                $storeViewId,
                $lastSuccessAt,
                $lastFailureAt,
                null,
                SignalStatus::InsufficientData,
                $state->sequenceNumber + 1
            );

            return $this->report(
                $storeViewCode,
                SignalStatus::InsufficientData,
                $state->sequenceNumber,
                $now,
                ReportReason::Transition
            );
        }

        if ($rawStatus === $state->confirmedStatus) {
            // No change; clear any stale pending status from a raw blip that never confirmed.
            $this->save(
                $storeViewId,
                $lastSuccessAt,
                $lastFailureAt,
                null,
                $state->confirmedStatus,
                $state->sequenceNumber + 1
            );

            return $this->report(
                $storeViewCode,
                $state->confirmedStatus,
                $state->sequenceNumber,
                $now,
                ReportReason::Heartbeat
            );
        }

        // Second consecutive differing tick: confirm using the current raw status.
        // Requiring the two ticks to match each other would let a status alternating
        // between two non-confirmed values never converge.
        if ($state->pendingStatus !== null) {
            $this->save($storeViewId, $lastSuccessAt, $lastFailureAt, null, $rawStatus, $state->sequenceNumber + 1);

            // Same "warm-up finishing is not a recovery" reasoning as
            // CronHealth\Evaluator -- confirming NORMAL straight out of the
            // INSUFFICIENT_DATA seed on a fresh store view must not report as a
            // transition, or the platform sends a "back to normal" email for an
            // install that was never actually down. An anomalous status
            // confirmed out of the seed is still a genuine first-detected
            // problem and keeps alerting.
            $reason = $state->confirmedStatus === SignalStatus::InsufficientData && $rawStatus === SignalStatus::Normal
                ? ReportReason::Heartbeat
                : ReportReason::Transition;

            return $this->report($storeViewCode, $rawStatus, $state->sequenceNumber, $now, $reason);
        }

        // First differing tick: start the confirmation counter; still report the old confirmed value.
        $this->save(
            $storeViewId,
            $lastSuccessAt,
            $lastFailureAt,
            $rawStatus,
            $state->confirmedStatus,
            $state->sequenceNumber + 1
        );

        return $this->report(
            $storeViewCode,
            $state->confirmedStatus,
            $state->sequenceNumber,
            $now,
            ReportReason::Heartbeat
        );
    }

    /**
     * Keeps a previously-reported store view's integration_health pair alive after its source is cleared.
     *
     * Null when it never reported at all. The platform's staleness sweep has
     * no concept of a deliberately retired signal, so going silent would
     * alert forever; re-heartbeating the last confirmed status keeps the
     * pair fresh until normal ticks resume.
     *
     * @param int $storeViewId
     * @param string $storeViewCode
     * @param \DateTimeImmutable $now
     * @return MetricReport|null
     */
    public function heartbeatRetiredIfPreviouslyReported(
        int $storeViewId,
        string $storeViewCode,
        \DateTimeImmutable $now
    ): ?MetricReport {
        $state = $this->repository->get($storeViewId);

        if ($state->isFirstEvaluation()) {
            return null;
        }

        $this->save(
            $storeViewId,
            $state->lastSuccessAt,
            $state->lastFailureAt,
            null,
            $state->confirmedStatus,
            $state->sequenceNumber + 1
        );

        return $this->report(
            $storeViewCode,
            $state->confirmedStatus,
            $state->sequenceNumber,
            $now,
            ReportReason::Heartbeat
        );
    }

    /**
     * Success within the expected interval -> OK; else a failure newer than the
     * last success -> FAILED (attempting work, not succeeding); else DOWN (no
     * evidence of activity at all).
     *
     * @param \DateTimeImmutable|null $lastSuccessAt
     * @param \DateTimeImmutable|null $lastFailureAt
     * @param int $expectedMaxIntervalMinutes
     * @param \DateTimeImmutable $now
     * @return SignalStatus
     */
    private function rawStatus(
        ?\DateTimeImmutable $lastSuccessAt,
        ?\DateTimeImmutable $lastFailureAt,
        int $expectedMaxIntervalMinutes,
        \DateTimeImmutable $now
    ): SignalStatus {
        $window = $now->modify('-' . $expectedMaxIntervalMinutes . ' minutes');

        if ($lastSuccessAt !== null && $lastSuccessAt >= $window) {
            return SignalStatus::Normal;
        }

        if ($lastFailureAt !== null && ($lastSuccessAt === null || $lastFailureAt > $lastSuccessAt)) {
            return SignalStatus::MildDrop;
        }

        return SignalStatus::SevereDrop;
    }

    /**
     * Persists the updated IntegrationHealthState for this tick.
     *
     * @param int $storeViewId
     * @param \DateTimeImmutable|null $lastSuccessAt
     * @param \DateTimeImmutable|null $lastFailureAt
     * @param SignalStatus|null $pendingStatus
     * @param SignalStatus|null $confirmedStatus
     * @param int $sequenceNumber
     * @return void
     */
    private function save(
        int $storeViewId,
        ?\DateTimeImmutable $lastSuccessAt,
        ?\DateTimeImmutable $lastFailureAt,
        ?SignalStatus $pendingStatus,
        ?SignalStatus $confirmedStatus,
        int $sequenceNumber,
    ): void {
        $this->repository->save(new IntegrationHealthState(
            storeViewId: $storeViewId,
            lastSuccessAt: $lastSuccessAt,
            lastFailureAt: $lastFailureAt,
            pendingStatus: $pendingStatus,
            confirmedStatus: $confirmedStatus,
            sequenceNumber: $sequenceNumber,
        ));
    }

    /**
     * Builds the MetricReport to submit for this tick.
     *
     * @param string $storeViewCode
     * @param SignalStatus $status
     * @param int $sequenceNumber
     * @param \DateTimeImmutable $now
     * @param ReportReason $reason
     * @return MetricReport
     */
    private function report(
        string $storeViewCode,
        SignalStatus $status,
        int $sequenceNumber,
        \DateTimeImmutable $now,
        ReportReason $reason
    ): MetricReport {
        return new MetricReport(
            storeViewCode: $storeViewCode,
            eventType: self::EVENT_TYPE,
            status: $status,
            sequenceNumber: $sequenceNumber,
            evaluatedAt: $now,
            reason: $reason,
            rulesetVersion: self::RULESET_VERSION,
        );
    }
}
