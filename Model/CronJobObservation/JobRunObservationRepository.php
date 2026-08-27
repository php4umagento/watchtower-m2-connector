<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\CronJobObservation;

use Magento\Framework\App\ResourceConnection;

/**
 * CRUD for watchtower_cron_job_observation, the per-job-code run history
 * CadenceEstimator reads.
 *
 * Reads are whole-table by design: the row count is bounded by the number of
 * declared cron jobs (68 on a stock 2.4.8), and both callers, the recorder
 * and the admin picker, want every row anyway. One query beats one per job.
 */
class JobRunObservationRepository
{
    private const TABLE = 'watchtower_cron_job_observation';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Every recorded observation, keyed by job code.
     *
     * @return array<string,JobRunObservation>
     */
    public function getAll(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $observations = [];

        foreach ($connection->fetchAll($connection->select()->from($table)) as $row) {
            $jobCode = (string) $row['job_code'];
            $observations[$jobCode] = $this->hydrate($row);
        }

        return $observations;
    }

    /**
     * One job's observation, or null when it has never been recorded.
     *
     * @param string $jobCode
     * @return JobRunObservation|null
     */
    public function get(string $jobCode): ?JobRunObservation
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('job_code = ?', $jobCode)
        );

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Upserts observations in one statement.
     *
     * The first_observed_at column is deliberately absent from the update
     * list: it anchors the learning-cadence grace, so it must survive every
     * later write rather than being pushed forward each tick.
     *
     * @param JobRunObservation[] $observations
     * @return void
     */
    public function saveAll(array $observations): void
    {
        if ($observations === []) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $rows = [];

        foreach ($observations as $observation) {
            $rows[] = [
                'job_code' => $observation->jobCode,
                'first_observed_at' => $observation->firstObservedAt->format('Y-m-d H:i:s'),
                'last_success_at' => $observation->lastSuccessAt?->format('Y-m-d H:i:s'),
                'observed_run_count' => $observation->observedRunCount,
                'gap_samples' => json_encode(array_values($observation->gapSamples)),
                'median_gap_seconds' => $observation->medianGapSeconds(),
            ];
        }

        $connection->insertOnDuplicate(
            $table,
            $rows,
            ['last_success_at', 'observed_run_count', 'gap_samples', 'median_gap_seconds']
        );
    }

    /**
     * Builds an observation from one database row.
     *
     * @param array $row
     * @return JobRunObservation
     */
    private function hydrate(array $row): JobRunObservation
    {
        return new JobRunObservation(
            jobCode: (string) $row['job_code'],
            firstObservedAt: new \DateTimeImmutable((string) $row['first_observed_at'], new \DateTimeZone('UTC')),
            lastSuccessAt: $this->toDateTime($row['last_success_at'] ?? null),
            observedRunCount: (int) $row['observed_run_count'],
            gapSamples: $this->decodeGapSamples($row['gap_samples'] ?? null),
        );
    }

    /**
     * Decodes the stored gap array, tolerating a null or malformed column.
     *
     * Malformed is treated as "no samples yet" rather than thrown: this runs
     * inside the cron tick, and a single unreadable row must not stop every
     * other job's cadence from being recorded.
     *
     * @param mixed $value
     * @return int[]
     */
    private function decodeGapSamples(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_map('intval', array_filter($decoded, 'is_numeric')));
    }

    /**
     * Converts a nullable stored datetime string to a DateTimeImmutable.
     *
     * Explicit UTC, not the bare single-arg form: stored datetimes are always
     * UTC, and parsing them without saying so falls back to PHP's default
     * timezone.
     *
     * @param mixed $value
     * @return \DateTimeImmutable|null
     */
    private function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        return $value !== null ? new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')) : null;
    }
}
