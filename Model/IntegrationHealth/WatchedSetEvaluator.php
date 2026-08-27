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
use Watchtower\Connector\Model\CronJobObservation\CadenceEstimator;
use Watchtower\Connector\Model\CronJobObservation\CronJobRunRecorder;
use Watchtower\Connector\Model\CronJobObservation\JobRunObservation;
use Watchtower\Connector\Model\CronJobObservation\JobRunObservationRepository;
use Watchtower\Connector\Model\Debounce\TwoEvaluationDebounce;

/**
 * Rolls the whole watched set up to the single integration_health status per
 * store view the wire contract allows, by taking the worst.
 *
 * No per-job debounce state: each job's evidence already lives durably in
 * watchtower_cron_job_observation, so one rolled-up value feeding the existing
 * per-store-view debounce is enough.
 */
class WatchedSetEvaluator
{
    public const EVENT_TYPE = 'integration_health';

    /** Bumped from the per-source evaluator's 1.0.0: same signal, different input. */
    public const RULESET_VERSION = '2.0.0';

    /** Distinct from the retired per-source types, so an old state row re-seeds. */
    public const SOURCE_TYPE = 'watched_set';

    /** @var TwoEvaluationDebounce shared two-evaluation debounce, see that class */
    private readonly TwoEvaluationDebounce $debounce;

    /**
     * @param IntegrationHealthStateRepository $repository
     * @param JobRunObservationRepository $observationRepository
     * @param CadenceEstimator $cadenceEstimator
     * @param CronJobObserver $cronJobObserver failure evidence, which observations do not carry
     * @param IntegrationHealthEventRepository $eventRepository convention event dispatch history
     * @param TwoEvaluationDebounce|null $debounce stateless, no DI wiring needed
     */
    public function __construct(
        private readonly IntegrationHealthStateRepository $repository,
        private readonly JobRunObservationRepository $observationRepository,
        private readonly CadenceEstimator $cadenceEstimator,
        private readonly CronJobObserver $cronJobObserver,
        private readonly IntegrationHealthEventRepository $eventRepository,
        ?TwoEvaluationDebounce $debounce = null,
    ) {
        $this->debounce = $debounce ?? new TwoEvaluationDebounce();
    }

    /**
     * Runs one debounce tick for a store view over the whole watched set.
     *
     * @param int $storeViewId
     * @param string $storeViewCode
     * @param string[] $jobCodes every watched job, already expanded from modules
     * @param string[] $eventLabels watched convention event labels, judged per store view
     * @param \DateTimeImmutable $now
     * @return MetricReport
     */
    public function evaluate(
        int $storeViewId,
        string $storeViewCode,
        array $jobCodes,
        array $eventLabels,
        \DateTimeImmutable $now
    ): MetricReport {
        $state = $this->repository->get($storeViewId);
        $fingerprint = $this->fingerprint(array_merge($jobCodes, $eventLabels));

        // The stored verdict describes different jobs, so re-seed. Heartbeat
        // rather than Transition: editing your own selection is not an alert.
        if (!$state->describesSource(self::SOURCE_TYPE, $fingerprint)) {
            $this->save($storeViewId, null, SignalStatus::InsufficientData, $state->sequenceNumber + 1, $fingerprint);

            return $this->report($storeViewCode, SignalStatus::InsufficientData, $state->sequenceNumber, $now);
        }

        $rawStatus = $this->worstOf($jobCodes, $eventLabels, $storeViewId, $now);
        $decision = $this->debounce->decide($rawStatus, $state->confirmedStatus, $state->pendingStatus);

        $this->save(
            $storeViewId,
            $decision->nextPendingStatus,
            $decision->nextConfirmedStatus,
            $state->sequenceNumber + 1,
            $fingerprint
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
     * The watched jobs currently judged unhealthy, for watchtower:status only.
     *
     * Never crosses the wire: the platform gets one rolled-up status, so this
     * is the only way a merchant learns which integration is at fault.
     *
     * @param string[] $jobCodes
     * @param \DateTimeImmutable $now
     * @return string[]
     */
    public function unhealthyJobCodes(array $jobCodes, \DateTimeImmutable $now): array
    {
        $unhealthy = [];

        foreach ($jobCodes as $jobCode) {
            if ($this->isAnomalous($this->statusFor($jobCode, $now))) {
                $unhealthy[] = $jobCode;
            }
        }

        return $unhealthy;
    }

    /**
     * The watched event labels currently judged unhealthy for one store view.
     *
     * Store-view-scoped, unlike unhealthyJobCodes above, because a dispatch
     * carries a store id and the same label can be healthy in one store view
     * and stalled in another.
     *
     * @param string[] $eventLabels
     * @param int $storeViewId
     * @param \DateTimeImmutable $now
     * @return string[]
     */
    public function unhealthyEventLabels(array $eventLabels, int $storeViewId, \DateTimeImmutable $now): array
    {
        $unhealthy = [];

        foreach ($eventLabels as $eventLabel) {
            if ($this->isAnomalous($this->eventStatusFor($storeViewId, $eventLabel, $now))) {
                $unhealthy[] = $eventLabel;
            }
        }

        return $unhealthy;
    }

    /**
     * The worst status across the watched set.
     *
     * INSUFFICIENT_DATA only wins when it is all there is: a set with one
     * learning job and one healthy job is fine so far, not unknown.
     *
     * @param string[] $jobCodes
     * @param string[] $eventLabels
     * @param int $storeViewId
     * @param \DateTimeImmutable $now
     * @return SignalStatus
     */
    private function worstOf(
        array $jobCodes,
        array $eventLabels,
        int $storeViewId,
        \DateTimeImmutable $now
    ): SignalStatus {
        $seen = [];

        foreach ($jobCodes as $jobCode) {
            $seen[$this->statusFor($jobCode, $now)->value] = true;
        }

        foreach ($eventLabels as $eventLabel) {
            $seen[$this->eventStatusFor($storeViewId, $eventLabel, $now)->value] = true;
        }

        foreach ([SignalStatus::SevereDrop, SignalStatus::MildDrop, SignalStatus::Normal] as $status) {
            if (isset($seen[$status->value])) {
                return $status;
            }
        }

        return SignalStatus::InsufficientData;
    }

    /**
     * One watched job's raw status, before any debounce.
     *
     * Failure evidence has to come from cron_schedule because observations
     * record successes only, and a job that fails every run would otherwise
     * look healthy on cadence alone.
     *
     * @param string $jobCode
     * @param \DateTimeImmutable $now
     * @return SignalStatus
     */
    private function statusFor(string $jobCode, \DateTimeImmutable $now): SignalStatus
    {
        $observation = $this->observationRepository->get($jobCode);
        $threshold = $this->cadenceEstimator->estimate($observation)->thresholdSeconds;

        if ($threshold === null) {
            return SignalStatus::InsufficientData;
        }

        $observed = $this->cronJobObserver->observe($jobCode, $now);

        return $this->classify(
            $threshold,
            $this->later($observed->latestSuccessAt, $observation?->lastSuccessAt),
            $observed->latestFailureAt,
            $now
        );
    }

    /**
     * One watched convention event label's raw status for this store view.
     *
     * Store-view-scoped, unlike a cron job: a dispatch carries a store id. Its
     * window is measured from the recorded dispatch history, so an event needs
     * no typed interval either.
     *
     * @param int $storeViewId
     * @param string $eventLabel
     * @param \DateTimeImmutable $now
     * @return SignalStatus
     */
    private function eventStatusFor(int $storeViewId, string $eventLabel, \DateTimeImmutable $now): SignalStatus
    {
        $gaps = $this->eventRepository->successGapSeconds(
            $storeViewId,
            $eventLabel,
            CronJobRunRecorder::MAX_GAP_SAMPLES
        );

        // The estimator only reads the gaps and the run count, so the dispatch
        // history is fed through the same rule the cron side uses.
        $threshold = $this->cadenceEstimator->estimate(new JobRunObservation(
            jobCode: $eventLabel,
            firstObservedAt: $now,
            lastSuccessAt: null,
            observedRunCount: count($gaps) + 1,
            gapSamples: $gaps,
        ))->thresholdSeconds;

        if ($threshold === null) {
            return SignalStatus::InsufficientData;
        }

        $observed = $this->eventRepository->latestObservation($storeViewId, $eventLabel, $now);

        return $this->classify($threshold, $observed->latestSuccessAt, $observed->latestFailureAt, $now);
    }

    /**
     * Turns one source's evidence into a status.
     *
     * @param int $thresholdSeconds
     * @param \DateTimeImmutable|null $lastSuccessAt
     * @param \DateTimeImmutable|null $lastFailureAt
     * @param \DateTimeImmutable $now
     * @return SignalStatus
     */
    private function classify(
        int $thresholdSeconds,
        ?\DateTimeImmutable $lastSuccessAt,
        ?\DateTimeImmutable $lastFailureAt,
        \DateTimeImmutable $now
    ): SignalStatus {
        if ($lastSuccessAt !== null && $lastSuccessAt >= $now->modify('-' . $thresholdSeconds . ' seconds')) {
            return SignalStatus::Normal;
        }

        // Attempting work and failing is milder than no evidence of activity
        // at all, matching the per-source evaluator.
        if ($lastFailureAt !== null && ($lastSuccessAt === null || $lastFailureAt > $lastSuccessAt)) {
            return SignalStatus::MildDrop;
        }

        return SignalStatus::SevereDrop;
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
     * The later of two nullable timestamps.
     *
     * @param \DateTimeImmutable|null $a
     * @param \DateTimeImmutable|null $b
     * @return \DateTimeImmutable|null
     */
    private function later(?\DateTimeImmutable $a, ?\DateTimeImmutable $b): ?\DateTimeImmutable
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a >= $b ? $a : $b;
    }

    /**
     * A stable identity for the watched set, so editing it re-seeds the state.
     *
     * Hashed because the column is 255 characters and a merchant may watch
     * more job codes than fit.
     *
     * @param string[] $jobCodes
     * @return string
     */
    private function fingerprint(array $jobCodes): string
    {
        $sorted = array_values(array_unique($jobCodes));
        sort($sorted, SORT_STRING);

        return sha1(implode("\n", $sorted));
    }

    /**
     * Persists this tick's state.
     *
     * The evidence columns stay null: the per-job observation table owns that
     * now.
     *
     * @param int $storeViewId
     * @param SignalStatus|null $pendingStatus
     * @param SignalStatus|null $confirmedStatus
     * @param int $sequenceNumber
     * @param string $fingerprint
     * @return void
     */
    private function save(
        int $storeViewId,
        ?SignalStatus $pendingStatus,
        ?SignalStatus $confirmedStatus,
        int $sequenceNumber,
        string $fingerprint
    ): void {
        $this->repository->save(new IntegrationHealthState(
            storeViewId: $storeViewId,
            lastSuccessAt: null,
            lastFailureAt: null,
            pendingStatus: $pendingStatus,
            confirmedStatus: $confirmedStatus,
            sequenceNumber: $sequenceNumber,
            lastReportedReason: ReportReason::Heartbeat,
            sourceType: self::SOURCE_TYPE,
            sourceIdentifier: $fingerprint,
            observingSince: null,
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
        ReportReason $reason = ReportReason::Heartbeat
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
