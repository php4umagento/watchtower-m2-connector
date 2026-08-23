<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\CronHealth;

use Watchtower\Connector\Model\Api\MetricReport;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Debounce\TwoEvaluationDebounce;
use Watchtower\Connector\Model\HealthState\HealthState;
use Watchtower\Connector\Model\HealthState\HealthStateRepository;

/**
 * The cron_health state machine. One evaluate() call = one tick = one
 * MetricReport: a heartbeat repeating the last confirmed status, or a transition
 * once a differing raw status has held for two consecutive ticks.
 *
 * The debounce itself lives in Debounce\TwoEvaluationDebounce, shared with
 * every other evaluator. It used to be copied into each of them, and this
 * docblock used to warn that a bug fixed here probably existed in those
 * copies too; that is no longer the case.
 */
class Evaluator
{
    public const EVENT_TYPE = 'cron_health';
    public const RULESET_VERSION = '1.0.1';

    /**
     * How far back a success must fall to still count as "OK". Wider than Magento's
     * default schedule_generate_every of 15 minutes, so an ordinary gap between
     * generation bursts is never misread as an outage.
     */
    private const EXPECTED_MAX_INTERVAL_MINUTES = 30;

    /** @var TwoEvaluationDebounce shared two-evaluation debounce, see that class */
    private readonly TwoEvaluationDebounce $debounce;

    /**
     * @param CronScheduleObserver $observer
     * @param HealthStateRepository $repository
     * @param TwoEvaluationDebounce|null $debounce stateless, no DI wiring needed
     */
    public function __construct(
        private readonly CronScheduleObserver $observer,
        private readonly HealthStateRepository $repository,
        ?TwoEvaluationDebounce $debounce = null,
    ) {
        $this->debounce = $debounce ?? new TwoEvaluationDebounce();
    }

    /**
     * Runs one debounce tick and returns the MetricReport to submit for it.
     *
     * @param \DateTimeImmutable $now
     * @return MetricReport
     */
    public function evaluate(\DateTimeImmutable $now): MetricReport
    {
        $state = $this->repository->get(self::EVENT_TYPE);
        $observation = $this->observer->observe($now);

        // Carried forward across ticks: cron_schedule purges aggressively, so a
        // single observation can be empty even when the scheduler is healthy.
        $lastSuccessAt = $observation->latestSuccessAt ?? $state->lastSuccessAt;
        $lastFailureAt = $observation->latestFailureAt ?? $state->lastFailureAt;
        $rawStatus = $this->rawStatus($lastSuccessAt, $lastFailureAt, $now);

        // warmsUp: false -- cron health reads the scheduler's own success/
        // failure record, so a recent run is a real, healthy NORMAL from the
        // first tick with no baseline to build. It never reports a "Warming up"
        // seed (rawStatus() never returns INSUFFICIENT_DATA).
        $decision = $this->debounce->decide($rawStatus, $state->confirmedStatus, $state->pendingStatus, warmsUp: false);

        $this->save(
            $lastSuccessAt,
            $lastFailureAt,
            $decision->nextPendingStatus,
            $decision->nextConfirmedStatus,
            $state->sequenceNumber + 1,
            $decision->reportReason
        );

        return $this->report(
            $decision->reportStatus,
            $state->sequenceNumber,
            $now,
            $decision->reportReason
        );
    }

    /**
     * Classifies the persisted, purge-surviving timestamps: a success within the
     * expected interval is OK; a failure more recent than the last success is
     * FAILED (attempting work, not succeeding); silence on both counts is DOWN.
     *
     * @param \DateTimeImmutable|null $lastSuccessAt
     * @param \DateTimeImmutable|null $lastFailureAt
     * @param \DateTimeImmutable $now
     * @return SignalStatus
     */
    private function rawStatus(
        ?\DateTimeImmutable $lastSuccessAt,
        ?\DateTimeImmutable $lastFailureAt,
        \DateTimeImmutable $now
    ): SignalStatus {
        $window = $now->modify('-'.self::EXPECTED_MAX_INTERVAL_MINUTES.' minutes');

        if ($lastSuccessAt !== null && $lastSuccessAt >= $window) {
            return SignalStatus::Normal;
        }

        if ($lastFailureAt !== null && ($lastSuccessAt === null || $lastFailureAt > $lastSuccessAt)) {
            return SignalStatus::MildDrop;
        }

        return SignalStatus::SevereDrop;
    }

    /**
     * Persists the updated HealthState for this tick.
     *
     * @param \DateTimeImmutable|null $lastSuccessAt
     * @param \DateTimeImmutable|null $lastFailureAt
     * @param SignalStatus|null $pendingStatus
     * @param SignalStatus|null $confirmedStatus
     * @param int $sequenceNumber
     * @param ReportReason $reason the reason for the report this tick actually produces
     * @return void
     */
    private function save(
        ?\DateTimeImmutable $lastSuccessAt,
        ?\DateTimeImmutable $lastFailureAt,
        ?SignalStatus $pendingStatus,
        ?SignalStatus $confirmedStatus,
        int $sequenceNumber,
        ReportReason $reason,
    ): void {
        $this->repository->save(new HealthState(
            eventType: self::EVENT_TYPE,
            lastSuccessAt: $lastSuccessAt,
            lastFailureAt: $lastFailureAt,
            pendingStatus: $pendingStatus,
            confirmedStatus: $confirmedStatus,
            sequenceNumber: $sequenceNumber,
            lastReportedReason: $reason,
        ));
    }

    /**
     * Builds the MetricReport to submit for this tick.
     *
     * @param SignalStatus $status
     * @param int $sequenceNumber
     * @param \DateTimeImmutable $now
     * @param ReportReason $reason
     * @return MetricReport
     */
    private function report(
        SignalStatus $status,
        int $sequenceNumber,
        \DateTimeImmutable $now,
        ReportReason $reason
    ): MetricReport {
        return new MetricReport(
            storeViewCode: null,
            eventType: self::EVENT_TYPE,
            status: $status,
            sequenceNumber: $sequenceNumber,
            evaluatedAt: $now,
            reason: $reason,
            rulesetVersion: self::RULESET_VERSION,
        );
    }
}
