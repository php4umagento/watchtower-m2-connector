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
use Watchtower\Connector\Model\Debounce\TwoEvaluationDebounce;

/**
 * The integration_health state machine: the same two-evaluation debounce and
 * OK/FAILED/DOWN classification as CronHealth\Evaluator, scoped per store view.
 * It classifies the success/failure timestamps its caller resolved (see
 * Model/ReportingService.php for the dispatch) and takes the source's identity
 * only to notice when the state describes a source the merchant has replaced.
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
    /** @var TwoEvaluationDebounce shared two-evaluation debounce, see that class */
    private readonly TwoEvaluationDebounce $debounce;

    /**
     * @param IntegrationHealthStateRepository $repository
     * @param TwoEvaluationDebounce|null $debounce stateless, no DI wiring needed
     */
    public function __construct(
        private readonly IntegrationHealthStateRepository $repository,
        ?TwoEvaluationDebounce $debounce = null,
    ) {
        $this->debounce = $debounce ?? new TwoEvaluationDebounce();
    }

    /**
     * Runs one debounce tick for one store view and returns the MetricReport to submit for it.
     *
     * @param int $storeViewId
     * @param string $storeViewCode
     * @param string $sourceType the source currently configured for this store view
     * @param string $sourceIdentifier the source currently configured for this store view
     * @param \DateTimeImmutable|null $observedSuccessAt latest success observed THIS tick, if any
     * @param \DateTimeImmutable|null $observedFailureAt latest failure observed THIS tick, if any
     * @param int|null $thresholdSeconds derived from observed cadence; null while it is still being learned
     * @param \DateTimeImmutable $now
     * @return MetricReport
     */
    public function evaluate(
        int $storeViewId,
        string $storeViewCode,
        string $sourceType,
        string $sourceIdentifier,
        ?\DateTimeImmutable $observedSuccessAt,
        ?\DateTimeImmutable $observedFailureAt,
        ?int $thresholdSeconds,
        \DateTimeImmutable $now
    ): MetricReport {
        $state = $this->repository->get($storeViewId);

        // Every timestamp and status in the state describes the OLD source, so a
        // source change (first configuration included) re-seeds from scratch.
        // Detected here rather than on config write so no writer can bypass it.
        if (!$state->describesSource($sourceType, $sourceIdentifier)) {
            return $this->seedForSource($storeViewId, $storeViewCode, $sourceType, $sourceIdentifier, $state, $now);
        }

        // Carried forward across ticks: no source table keeps durable history
        // beyond roughly an hour, so rawStatus() must be able to compare against
        // a success/failure from several ticks ago.
        $lastSuccessAt = $observedSuccessAt ?? $state->lastSuccessAt;
        $lastFailureAt = $observedFailureAt ?? $state->lastFailureAt;

        // Too few runs measured to know this source's rhythm, so there is no
        // honest window to judge it against yet. Evidence keeps accumulating;
        // only the verdict is withheld.
        if ($thresholdSeconds === null) {
            return $this->reportLearning($storeViewId, $storeViewCode, $state, $lastSuccessAt, $lastFailureAt, $now);
        }

        $rawStatus = $this->rawStatus(
            $lastSuccessAt,
            $lastFailureAt,
            $state->observingSince,
            $thresholdSeconds,
            $now
        );

        // Reached only once the state already describes this source, so
        // confirmedStatus is never null here: seedForSource() above owns the
        // first-evaluation case for this signal, where the other evaluators
        // let the shared debounce seed it.
        $decision = $this->debounce->decide($rawStatus, $state->confirmedStatus, $state->pendingStatus);

        $this->save(
            $storeViewId,
            $lastSuccessAt,
            $lastFailureAt,
            $decision->nextPendingStatus,
            $decision->nextConfirmedStatus,
            $state->sequenceNumber + 1,
            $decision->reportReason,
            $sourceType,
            $sourceIdentifier,
            $state->observingSince
        );

        return $this->report(
            $storeViewCode,
            $decision->reportStatus,
            $state->sequenceNumber,
            $now,
            $decision->reportReason
        );
    }

    /**
     * Re-seeds this store view for a newly configured source and reports the seed tick.
     *
     * Heartbeat, never Transition: the platform alerts on any transition,
     * INSUFFICIENT_DATA included, and a merchant changing their own source
     * has nothing to be alerted about.
     *
     * @param int $storeViewId
     * @param string $storeViewCode
     * @param string $sourceType
     * @param string $sourceIdentifier
     * @param IntegrationHealthState $state
     * @param \DateTimeImmutable $now
     * @return MetricReport
     */
    private function seedForSource(
        int $storeViewId,
        string $storeViewCode,
        string $sourceType,
        string $sourceIdentifier,
        IntegrationHealthState $state,
        \DateTimeImmutable $now
    ): MetricReport {
        $this->save(
            $storeViewId,
            null,
            null,
            null,
            SignalStatus::InsufficientData,
            $state->sequenceNumber + 1,
            ReportReason::Heartbeat,
            $sourceType,
            $sourceIdentifier,
            $now
        );

        return $this->report(
            $storeViewCode,
            SignalStatus::InsufficientData,
            $state->sequenceNumber,
            $now,
            ReportReason::Heartbeat
        );
    }

    /**
     * Reports INSUFFICIENT_DATA for a source whose cadence has not been established yet.
     *
     * Heartbeat rather than Transition, for the same reason seedForSource()
     * is: the platform alerts on any transition, INSUFFICIENT_DATA included,
     * and "still measuring" is not something a merchant can act on.
     *
     * Evidence timestamps are still carried forward, so that the moment
     * enough runs accumulate the first real evaluation has the full history
     * behind it rather than starting blind.
     *
     * @param int $storeViewId
     * @param string $storeViewCode
     * @param IntegrationHealthState $state
     * @param \DateTimeImmutable|null $lastSuccessAt
     * @param \DateTimeImmutable|null $lastFailureAt
     * @param \DateTimeImmutable $now
     * @return MetricReport
     */
    private function reportLearning(
        int $storeViewId,
        string $storeViewCode,
        IntegrationHealthState $state,
        ?\DateTimeImmutable $lastSuccessAt,
        ?\DateTimeImmutable $lastFailureAt,
        \DateTimeImmutable $now
    ): MetricReport {
        $this->save(
            $storeViewId,
            $lastSuccessAt,
            $lastFailureAt,
            null,
            SignalStatus::InsufficientData,
            $state->sequenceNumber + 1,
            ReportReason::Heartbeat,
            $state->sourceType,
            $state->sourceIdentifier,
            $state->observingSince
        );

        return $this->report(
            $storeViewCode,
            SignalStatus::InsufficientData,
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
     * alert forever; heartbeating keeps the pair fresh until normal ticks
     * resume. An anomalous status is downgraded to INSUFFICIENT_DATA first,
     * since a retired source can no longer be observed to recover.
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
        $confirmedStatus = $state->confirmedStatus;

        if ($confirmedStatus === null) {
            return null;
        }

        $retiredStatus = $this->isAnomalous($confirmedStatus) ? SignalStatus::InsufficientData : $confirmedStatus;

        // Fingerprint cleared so re-configuring the same source later re-seeds
        // rather than resuming on evidence describing a since-retired source.
        $this->save(
            $storeViewId,
            $state->lastSuccessAt,
            $state->lastFailureAt,
            null,
            $retiredStatus,
            $state->sequenceNumber + 1,
            ReportReason::Heartbeat,
            null,
            null,
            null
        );

        return $this->report(
            $storeViewCode,
            $retiredStatus,
            $state->sequenceNumber,
            $now,
            ReportReason::Heartbeat
        );
    }

    /**
     * Whether a status is one integration_health raises as a problem.
     *
     * @param SignalStatus $status
     * @return bool
     */
    private function isAnomalous(SignalStatus $status): bool
    {
        return $status === SignalStatus::MildDrop || $status === SignalStatus::SevereDrop;
    }

    /**
     * Success within the expected interval -> OK; else a failure newer than the
     * last success -> FAILED (attempting work, not succeeding); else DOWN (no
     * evidence of activity at all).
     *
     * @param \DateTimeImmutable|null $lastSuccessAt
     * @param \DateTimeImmutable|null $lastFailureAt
     * @param \DateTimeImmutable|null $observingSince null on a pre-fingerprint row: grace already elapsed
     * @param int $thresholdSeconds
     * @param \DateTimeImmutable $now
     * @return SignalStatus
     */
    private function rawStatus(
        ?\DateTimeImmutable $lastSuccessAt,
        ?\DateTimeImmutable $lastFailureAt,
        ?\DateTimeImmutable $observingSince,
        int $thresholdSeconds,
        \DateTimeImmutable $now
    ): SignalStatus {
        $window = $now->modify('-' . $thresholdSeconds . ' seconds');

        if ($lastSuccessAt !== null && $lastSuccessAt >= $window) {
            return SignalStatus::Normal;
        }

        if ($lastFailureAt !== null && ($lastSuccessAt === null || $lastFailureAt > $lastSuccessAt)) {
            return SignalStatus::MildDrop;
        }

        // No evidence yet, and the source has not been observed for a full
        // expected interval: a daily job would otherwise report DOWN within
        // minutes of being configured.
        $hasNoEvidenceAtAll = $lastSuccessAt === null && $lastFailureAt === null;

        if ($hasNoEvidenceAtAll && $observingSince !== null && $observingSince > $window) {
            return SignalStatus::InsufficientData;
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
     * @param ReportReason $reason the reason for the report this tick actually produces
     * @param string|null $sourceType
     * @param string|null $sourceIdentifier
     * @param \DateTimeImmutable|null $observingSince
     * @return void
     */
    private function save(
        int $storeViewId,
        ?\DateTimeImmutable $lastSuccessAt,
        ?\DateTimeImmutable $lastFailureAt,
        ?SignalStatus $pendingStatus,
        ?SignalStatus $confirmedStatus,
        int $sequenceNumber,
        ReportReason $reason,
        ?string $sourceType,
        ?string $sourceIdentifier,
        ?\DateTimeImmutable $observingSince,
    ): void {
        $this->repository->save(new IntegrationHealthState(
            storeViewId: $storeViewId,
            lastSuccessAt: $lastSuccessAt,
            lastFailureAt: $lastFailureAt,
            pendingStatus: $pendingStatus,
            confirmedStatus: $confirmedStatus,
            sequenceNumber: $sequenceNumber,
            lastReportedReason: $reason,
            sourceType: $sourceType,
            sourceIdentifier: $sourceIdentifier,
            observingSince: $observingSince,
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
