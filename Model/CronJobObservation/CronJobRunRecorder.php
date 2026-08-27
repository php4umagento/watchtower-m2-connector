<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\CronJobObservation;

use Magento\Cron\Model\Schedule;
use Magento\Framework\App\ResourceConnection;
use Watchtower\Connector\Model\Config;

/**
 * Records every cron job's successful runs each tick, building the gap
 * history CadenceEstimator measures a job's rhythm from.
 *
 * Runs on every 5-minute tick rather than once per evaluation cycle, for the
 * same reason ReportingService::snapshotIntegrationHealthEvidence() does:
 * Magento prunes succeeded cron_schedule rows about an hour after they
 * finish (history_success_lifetime), so evidence sampled hourly is evidence
 * already partly deleted.
 *
 * Gated on isEnabled() but deliberately NOT on isConfigured(): a store that
 * has installed and enabled the module but not yet entered an API key should
 * still be accumulating cadence data, so the admin picker has real numbers to
 * show the moment they do configure it. A merchant who *disabled* the module
 * still expects it to stop writing, which is the rule ConventionEventObserver
 * follows too.
 */
class CronJobRunRecorder
{
    /**
     * Generous relative to Magento's own ~60 minute history_success_lifetime,
     * so an install whose host cron ticks irregularly still catches every
     * success Magento has kept. Costs nothing extra when the table has been
     * pruned normally, since there is simply nothing older to find.
     */
    private const LOOKBACK_MINUTES = 240;

    /**
     * Gaps retained per job. Twenty is enough for a stable median and p95
     * while keeping the row small, and it lets a job that genuinely changes
     * schedule converge on its new rhythm within a day rather than being
     * anchored to months of stale history.
     */
    public const MAX_GAP_SAMPLES = 20;

    /**
     * @param ResourceConnection $resourceConnection
     * @param JobRunObservationRepository $repository
     * @param Config $config
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly JobRunObservationRepository $repository,
        private readonly Config $config
    ) {
    }

    /**
     * Records any successes that have happened since the last time this ran.
     *
     * @param \DateTimeImmutable $now
     * @return void
     */
    public function record(\DateTimeImmutable $now): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $successesByJob = $this->fetchSuccesses($now);

        if ($successesByJob === []) {
            return;
        }

        $existing = $this->repository->getAll();
        $updated = [];

        foreach ($successesByJob as $jobCode => $successes) {
            $observation = $this->apply($jobCode, $existing[$jobCode] ?? null, $successes);

            if ($observation !== null) {
                $updated[] = $observation;
            }
        }

        $this->repository->saveAll($updated);
    }

    /**
     * Folds this tick's newly-seen successes into a job's existing observation.
     *
     * Returns null when every success fetched was already recorded, so an
     * unchanged job costs no write. Every success is walked, not just the
     * freshest: recording only the newest one per tick would measure a
     * once-a-minute job as running every five minutes, which is the tick
     * period rather than the job's own.
     *
     * @param string $jobCode
     * @param JobRunObservation|null $existing
     * @param \DateTimeImmutable[] $successes ascending
     * @return JobRunObservation|null
     */
    private function apply(string $jobCode, ?JobRunObservation $existing, array $successes): ?JobRunObservation
    {
        $lastSuccessAt = $existing?->lastSuccessAt;
        $gaps = $existing?->gapSamples ?? [];
        $runCount = $existing?->observedRunCount ?? 0;
        $recorded = 0;

        foreach ($successes as $successAt) {
            // Already folded in on an earlier tick. The lookback window
            // deliberately overlaps previous ticks, so most rows land here.
            if ($lastSuccessAt !== null && $successAt <= $lastSuccessAt) {
                continue;
            }

            if ($lastSuccessAt !== null) {
                $gap = $successAt->getTimestamp() - $lastSuccessAt->getTimestamp();

                // Two jobs finishing within the same second would otherwise
                // contribute a zero gap and drag the median toward nonsense.
                if ($gap > 0) {
                    $gaps[] = $gap;
                }
            }

            $lastSuccessAt = $successAt;
            $runCount++;
            $recorded++;
        }

        if ($recorded === 0) {
            return null;
        }

        return new JobRunObservation(
            jobCode: $jobCode,
            // On a job's first sighting the window usually holds several past
            // successes, so anchoring to the earliest of them rather than to
            // now keeps the learning grace honest about how long this job has
            // really been watched.
            firstObservedAt: $existing?->firstObservedAt ?? $successes[0],
            lastSuccessAt: $lastSuccessAt,
            observedRunCount: $runCount,
            gapSamples: array_slice($gaps, -self::MAX_GAP_SAMPLES),
        );
    }

    /**
     * Every successful run in the lookback window, grouped by job code, ascending within each.
     *
     * One query for all jobs rather than one per job: the caller needs the
     * whole picture each tick anyway, and cron_schedule is small because
     * Magento prunes it.
     *
     * @param \DateTimeImmutable $now
     * @return array<string,\DateTimeImmutable[]>
     */
    private function fetchSuccesses(\DateTimeImmutable $now): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('cron_schedule');
        $lookbackValue = $now->modify('-' . self::LOOKBACK_MINUTES . ' minutes')->format('Y-m-d H:i:s');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['job_code', 'finished_at'])
                ->where('status = ?', Schedule::STATUS_SUCCESS)
                ->where('finished_at >= ?', $lookbackValue)
                // Monitoring this module's own jobs as an integration is
                // circular, so they are never offered and never measured.
                ->where('job_code NOT LIKE ?', 'watchtower\_%')
                ->order('job_code ASC')
                ->order('finished_at ASC')
        );

        $successes = [];

        foreach ($rows as $row) {
            if ($row['finished_at'] === null) {
                continue;
            }

            $successes[(string) $row['job_code']][] = new \DateTimeImmutable(
                (string) $row['finished_at'],
                new \DateTimeZone('UTC')
            );
        }

        return $successes;
    }
}
