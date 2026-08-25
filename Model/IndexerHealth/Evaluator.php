<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\IndexerHealth;

use Watchtower\Connector\Model\Api\MetricReport;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Debounce\TwoEvaluationDebounce;
use Watchtower\Connector\Model\HealthState\HealthState;
use Watchtower\Connector\Model\HealthState\HealthStateRepository;

/**
 * The indexer_health state machine, specified in the platform's
 * connector-metrics-spec.md 2.10. One evaluate() call = one tick = one
 * MetricReport, same shape as CronHealth\Evaluator.
 *
 * The signal is built on DURATION, not on the bare status. An indexer sitting
 * `invalid` is completely ordinary for the minutes after a product import or
 * between cron reindexes; what is not ordinary is one that stays that way, a
 * reindex that never finishes, or a changelog backlog nobody is draining.
 * Alerting on the raw status would page somebody after every import.
 *
 * Unlike cron_health this keeps NO durable observation of its own. That is a
 * deliberate reduction rather than an oversight: cron_health must snapshot
 * because Magento purges cron_schedule within the hour, whereas indexer_state
 * and mview_state are fixed-size tables whose `updated` column already carries
 * the onset. The persisted HealthState here is debounce and sequence
 * bookkeeping only, which is why lastSuccessAt/lastFailureAt are written null.
 */
class Evaluator
{
    public const EVENT_TYPE = 'indexer_health';
    public const RULESET_VERSION = '1.0.0';

    /**
     * How long an unhealthy condition may persist before it is worth
     * reporting at all. Comfortably past a routine reindex: Magento's
     * indexer_reindex_all_invalid job runs every minute, and a large
     * catalogue's full reindex is minutes to tens of minutes, so anything
     * still wrong after this has stopped being ordinary catch-up.
     */
    private const MILD_AFTER_MINUTES = 90;

    /**
     * When it has gone on long enough to be serving genuinely stale data
     * rather than lagging. Past this the storefront has had wrong prices or
     * missing category pages for most of a working half-day.
     */
    private const SEVERE_AFTER_MINUTES = 360;

    /** @var TwoEvaluationDebounce shared two-evaluation debounce, see that class */
    private readonly TwoEvaluationDebounce $debounce;

    /**
     * @param IndexerStateObserver $observer
     * @param HealthStateRepository $repository
     * @param TwoEvaluationDebounce|null $debounce stateless, no DI wiring needed
     */
    public function __construct(
        private readonly IndexerStateObserver $observer,
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
        $rawStatus = $this->rawStatus($this->observer->observe($now), $now);

        // warmsUp: false -- the state tables answer the question on the first
        // tick, so there is no baseline to build and this signal never reports
        // INSUFFICIENT_DATA. rawStatus() never returns it.
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
            evaluatedAt: $now,
            reason: $decision->reportReason,
            rulesetVersion: self::RULESET_VERSION,
        );
    }

    /**
     * Turns one poll into a status by how long the condition has lasted.
     *
     * A suspended view short-circuits the duration windows entirely. Suspension
     * is not a slow condition that might resolve itself on the next cron run:
     * the view is not draining and will not resume without someone acting, so
     * waiting out a window before saying so only delays the alert.
     *
     * @param Observation $observation
     * @param \DateTimeImmutable $now
     * @return SignalStatus
     */
    private function rawStatus(Observation $observation, \DateTimeImmutable $now): SignalStatus
    {
        if ($observation->suspended) {
            return SignalStatus::SevereDrop;
        }

        if ($observation->unhealthySince === null) {
            return SignalStatus::Normal;
        }

        if ($observation->unhealthySince <= $now->modify('-'.self::SEVERE_AFTER_MINUTES.' minutes')) {
            return SignalStatus::SevereDrop;
        }

        if ($observation->unhealthySince <= $now->modify('-'.self::MILD_AFTER_MINUTES.' minutes')) {
            return SignalStatus::MildDrop;
        }

        // Recently invalid, so still plausibly a reindex in progress.
        return SignalStatus::Normal;
    }
}
