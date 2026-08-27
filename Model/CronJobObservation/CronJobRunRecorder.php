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
 * Every tick rather than once per cycle, because Magento prunes succeeded
 * cron_schedule rows within about an hour. Gated on isEnabled but not
 * isConfigured, so cadence accumulates before an API key is ever entered.
 */
class CronJobRunRecorder
{
    /** Generous against Magento's ~60 minute history_success_lifetime, for hosts whose cron ticks irregularly. */
    private const LOOKBACK_MINUTES = 240;

    /**
     * Enough for a stable median and p95, while letting a job that changes
     * schedule converge on its new rhythm within a day.
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
     * Every success is walked, not just the freshest: taking only the newest
     * would measure a once-a-minute job at the five minute tick period.
     * Returns null when nothing was new, so an unchanged job costs no write.
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
            // The window usually holds past successes on a first sighting, so
            // anchoring to the earliest keeps the learning grace honest.
            firstObservedAt: $existing?->firstObservedAt ?? $successes[0],
            lastSuccessAt: $lastSuccessAt,
            observedRunCount: $runCount,
            gapSamples: array_slice($gaps, -self::MAX_GAP_SAMPLES),
        );
    }

    /**
     * Every successful run in the lookback window, grouped by job code, ascending within each.
     *
     * One query for all jobs: the caller needs the whole picture each tick.
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
