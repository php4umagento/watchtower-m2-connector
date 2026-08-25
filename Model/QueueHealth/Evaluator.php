<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\QueueHealth;

use Watchtower\Connector\Model\Api\MetricReport;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Debounce\TwoEvaluationDebounce;
use Watchtower\Connector\Model\HealthState\HealthState;
use Watchtower\Connector\Model\HealthState\HealthStateRepository;

/**
 * The queue_health state machine, specified in the platform's
 * connector-metrics-spec.md 2.12. One evaluate() call = one tick = one
 * MetricReport, same shape as IndexerHealth\Evaluator.
 *
 * Built on DURATION, not the bare condition: work nothing has picked up yet is
 * ordinary for the minute before cron spawns a consumer.
 *
 * Unlike indexer_health it does keep durable state, and that is forced rather
 * than copied. indexer_state.updated records its own onset; half of this
 * signal's input has no such column, because AMQP reports a consumer count
 * with no message age. lastFailureAt is that memory, written for the AMQP half
 * only.
 */
class Evaluator
{
    public const EVENT_TYPE = 'queue_health';
    public const RULESET_VERSION = '1.0.0';

    /**
     * How long work may sit undrained before it is worth reporting.
     * consumers_runner fires every minute and spawns a consumer whenever there
     * is work, so anything untouched past this is not scheduling latency.
     */
    private const MILD_AFTER_MINUTES = 90;

    /**
     * Past this, stock levels, product URLs and config saves have been
     * unapplied for most of a working half-day.
     */
    private const SEVERE_AFTER_MINUTES = 360;

    /** @var TwoEvaluationDebounce shared two-evaluation debounce, see that class */
    private readonly TwoEvaluationDebounce $debounce;

    /**
     * @param QueueStateObserver $observer
     * @param HealthStateRepository $repository
     * @param TwoEvaluationDebounce|null $debounce stateless, no DI wiring needed
     */
    public function __construct(
        private readonly QueueStateObserver $observer,
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

        $onset = $this->onset($observation, $state, $now);

        // warmsUp: false -- an empty queue is a real, healthy NORMAL on the
        // first tick, not "not enough data yet". rawStatus() never returns
        // INSUFFICIENT_DATA.
        $decision = $this->debounce->decide(
            $this->rawStatus($onset, $now),
            $state->confirmedStatus,
            $state->pendingStatus,
            warmsUp: false
        );

        $this->repository->save(new HealthState(
            eventType: self::EVENT_TYPE,
            lastSuccessAt: $onset === null ? $now : $state->lastSuccessAt,
            // Cleared the moment the condition clears, so a queue that drains
            // and later stalls again starts a fresh clock instead of
            // inheriting the first stall's age and alerting instantly.
            lastFailureAt: $observation->undrainedWithoutOnset ? ($state->lastFailureAt ?? $now) : null,
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
            evaluatedAt: $now,
            reason: $decision->reportReason,
            rulesetVersion: self::RULESET_VERSION,
        );
    }

    /**
     * When the oldest undrained work across both backends started waiting.
     *
     * Oldest wins, so a queue stalled for a day is not masked by one that has
     * only just stalled.
     *
     * @param Observation $observation
     * @param HealthState $state
     * @param \DateTimeImmutable $now
     * @return \DateTimeImmutable|null null when nothing is undrained
     */
    private function onset(
        Observation $observation,
        HealthState $state,
        \DateTimeImmutable $now
    ): ?\DateTimeImmutable {
        $candidates = [];

        if ($observation->undrainedSince !== null) {
            $candidates[] = $observation->undrainedSince;
        }

        if ($observation->undrainedWithoutOnset) {
            // Starting at now on first sight, so a stall is never treated as
            // older than the connector's own evidence for it.
            $candidates[] = $state->lastFailureAt ?? $now;
        }

        return $candidates === [] ? null : min($candidates);
    }

    /**
     * Turns an onset into a status by how long the condition has lasted.
     *
     * @param \DateTimeImmutable|null $onset
     * @param \DateTimeImmutable $now
     * @return SignalStatus
     */
    private function rawStatus(?\DateTimeImmutable $onset, \DateTimeImmutable $now): SignalStatus
    {
        if ($onset === null) {
            return SignalStatus::Normal;
        }

        if ($onset <= $now->modify('-'.self::SEVERE_AFTER_MINUTES.' minutes')) {
            return SignalStatus::SevereDrop;
        }

        if ($onset <= $now->modify('-'.self::MILD_AFTER_MINUTES.' minutes')) {
            return SignalStatus::MildDrop;
        }

        // Waiting, but not longer than a consumer might legitimately take.
        return SignalStatus::Normal;
    }
}
