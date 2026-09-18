<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Rollup;

use Magento\Framework\App\ResourceConnection;

/**
 * CRUD for watchtower_install_rollup_hourly: install-scoped hourly counts,
 * retained ~90 days, keyed by event_name instead of RollupRepository's
 * (store_view_id, category) -- the same split that table has from
 * watchtower_install_event_counter, install-scoped (see that table's
 * comment in etc/db_schema.xml). application_errors (docs/connector-error-log-signal-prd.md)
 * is the first install-scoped signal that needs a dispersion baseline, so
 * this table/class exists to back it.
 *
 * Deliberately hourly-only: unlike RollupRepository, there is no daily
 * table or rollupAndPrune() daily-roll step here. application_errors uses
 * Check A (median/MAD) only, never the seasonal/trend ensemble (Check
 * B/C), which is the only consumer of daily-granularity history in this
 * module -- see Model/ApplicationErrors/Evaluator.php's own docblock for
 * why. If a future need justifies daily rollups here, add them then rather
 * than building for a consumer that does not exist yet.
 *
 * A thin repository over ResourceConnection rather than a full
 * Model/ResourceModel/Collection triad, matching RollupRepository: no admin
 * grid, no EAV.
 */
class InstallRollupRepository
{
    private const HOURLY_TABLE = 'watchtower_install_rollup_hourly';

    /** Matches RollupRepository::HOURLY_RETENTION_DAYS -- same rationale, same table shape. */
    public const HOURLY_RETENTION_DAYS = 90;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Records the observed count for one event name/hour.
     *
     * Upserts: an in-progress hour is recorded repeatedly and the latest
     * count wins (matches RollupRepository::recordHourlyCount() -- this is
     * the completed hour's total, not an incremental add, unlike
     * InstallEventCounterRepository::increment()).
     *
     * @param string $eventName
     * @param \DateTimeImmutable $hourBucket any instant within the hour; only its UTC top-of-hour is stored
     * @param int $count
     * @return void
     */
    public function recordHourlyCount(string $eventName, \DateTimeImmutable $hourBucket, int $count): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::HOURLY_TABLE);

        $connection->insertOnDuplicate(
            $table,
            [
                'event_name' => $eventName,
                'hour_bucket' => $this->formatUtcHour($hourBucket),
                'count' => $count,
            ],
            ['count']
        );
    }

    /**
     * Fetches historical hourly counts for one bucket over the last N weeks.
     *
     * Bucket = (event name, hour-of-day, day-of-week).
     *
     * @param string $eventName
     * @param int $isoDayOfWeek day of week, 1 (Monday) through 7 (Sunday), matching DateTimeImmutable::format('N')
     * @param int $hourOfDay hour of day, 0 through 23
     * @param int $weeks how many weeks of history to look back
     * @param \DateTimeImmutable $now
     * @return HourlyCountSample[] oldest first
     */
    public function hourlyCountsForBucket(
        string $eventName,
        int $isoDayOfWeek,
        int $hourOfDay,
        int $weeks,
        \DateTimeImmutable $now
    ): array {
        if ($isoDayOfWeek < 1 || $isoDayOfWeek > 7) {
            throw new \InvalidArgumentException('isoDayOfWeek must be 1 (Monday) through 7 (Sunday).');
        }

        if ($hourOfDay < 0 || $hourOfDay > 23) {
            throw new \InvalidArgumentException('hourOfDay must be 0 through 23.');
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::HOURLY_TABLE);

        // MySQL's WEEKDAY() is 0-based (Monday), one less than the ISO parameter.
        $mysqlWeekday = $isoDayOfWeek - 1;

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['hour_bucket', 'count'])
                ->where('event_name = ?', $eventName)
                ->where('HOUR(hour_bucket) = ?', $hourOfDay)
                ->where('WEEKDAY(hour_bucket) = ?', $mysqlWeekday)
                ->where('hour_bucket >= ?', $this->utcDate($now, -($weeks * 7)))
                ->where('hour_bucket <= ?', $this->formatUtcHour($now))
                ->order('hour_bucket ASC')
        );

        return array_map($this->toHourlyCountSample(...), $rows);
    }

    /**
     * Deletes rows older than HOURLY_RETENTION_DAYS, returning the number removed.
     *
     * @param \DateTimeImmutable $now
     * @return int
     */
    public function prune(\DateTimeImmutable $now): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::HOURLY_TABLE);
        $cutoff = $this->utcDate($now, -self::HOURLY_RETENTION_DAYS);

        return (int) $connection->delete($table, ['hour_bucket < ?' => $cutoff]);
    }

    /**
     * Formats an instant as its UTC top-of-hour string.
     *
     * @param \DateTimeImmutable $dateTime
     * @return string
     */
    private function formatUtcHour(\DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:00:00');
    }

    /**
     * Formats an instant, offset by a number of days, as a UTC date/time string.
     *
     * @param \DateTimeImmutable $dateTime
     * @param int $offsetDays positive or negative number of days to offset by
     * @return string
     */
    private function utcDate(\DateTimeImmutable $dateTime, int $offsetDays): string
    {
        return $dateTime->setTimezone(new \DateTimeZone('UTC'))
            ->modify(sprintf('%+d days', $offsetDays))
            ->format('Y-m-d H:i:s');
    }

    /**
     * Maps a raw hourly row to its typed domain object.
     *
     * @param array $row
     * @return HourlyCountSample
     */
    private function toHourlyCountSample(array $row): HourlyCountSample
    {
        return new HourlyCountSample(
            bucket: new \DateTimeImmutable($row['hour_bucket'], new \DateTimeZone('UTC')),
            count: (int) $row['count'],
        );
    }
}
