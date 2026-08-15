<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\CronHealth;

use Watchtower\Connector\Model\Api\MetricReport;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\HealthState\HealthState;
use Watchtower\Connector\Model\HealthState\HealthStateRepository;

/**
 * The cron_health state machine. One evaluate() call = one tick = one
 * MetricReport: a heartbeat repeating the last confirmed status, or a transition
 * once a differing raw status has held for two consecutive ticks.
 *
 * RateSignal\DispersionEvaluator and IntegrationHealth\Evaluator duplicate this
 * same debounce shape rather than sharing a base class -- a bug fixed here
 * probably exists in those copies too.
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

    /**
     * @param CronScheduleObserver $observer
     * @param HealthStateRepository $repository
     */
    public function __construct(
        private readonly CronScheduleObserver $observer,
        private readonly HealthStateRepository $repository,
    ) {
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

        // First evaluation: nothing to debounce against yet. Seeding
        // INSUFFICIENT_DATA as the confirmed baseline stops a single poll moments
        // after activation from reporting a false DOWN.
        if ($state->isFirstEvaluation()) {
            $this->save(
                $lastSuccessAt,
                $lastFailureAt,
                null,
                SignalStatus::InsufficientData,
                $state->sequenceNumber + 1
            );

            return $this->report(
                SignalStatus::InsufficientData,
                $state->sequenceNumber,
                $now,
                ReportReason::Transition
            );
        }

        if ($rawStatus === $state->confirmedStatus) {
            // No change; clear any stale pending status from a raw blip that never confirmed.
            $this->save($lastSuccessAt, $lastFailureAt, null, $state->confirmedStatus, $state->sequenceNumber + 1);

            return $this->report($state->confirmedStatus, $state->sequenceNumber, $now, ReportReason::Heartbeat);
        }

        // Second consecutive differing tick: confirm using the current raw status,
        // even if it differs from the pending one. Requiring the two to match would
        // let an alternating status (MILD_DROP, SEVERE_DROP, ...) never converge.
        if ($state->pendingStatus !== null) {
            $this->save($lastSuccessAt, $lastFailureAt, null, $rawStatus, $state->sequenceNumber + 1);

            return $this->report($rawStatus, $state->sequenceNumber, $now, ReportReason::Transition);
        }

        // First differing tick: start the confirmation counter; still report the old confirmed value.
        $this->save($lastSuccessAt, $lastFailureAt, $rawStatus, $state->confirmedStatus, $state->sequenceNumber + 1);

        return $this->report($state->confirmedStatus, $state->sequenceNumber, $now, ReportReason::Heartbeat);
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
     * @return void
     */
    private function save(
        ?\DateTimeImmutable $lastSuccessAt,
        ?\DateTimeImmutable $lastFailureAt,
        ?SignalStatus $pendingStatus,
        ?SignalStatus $confirmedStatus,
        int $sequenceNumber,
    ): void {
        $this->repository->save(new HealthState(
            eventType: self::EVENT_TYPE,
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
