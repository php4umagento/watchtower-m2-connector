<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Rollup;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * CRUD for the connector's local historical retention store:
 * watchtower_rollup_hourly and watchtower_rollup_daily. Backs the
 * same-hour-of-day baseline and the year-over-year seasonal index, which both
 * need more history than the raw per-request counter tables retain.
 *
 * A thin repository over ResourceConnection rather than a full
 * Model/ResourceModel/Collection triad: no admin grid, no EAV.
 */
class RollupRepository
{
    private const HOURLY_TABLE = 'watchtower_rollup_hourly';
    private const DAILY_TABLE = 'watchtower_rollup_daily';

    /**
     * 400 days covers a leap year plus margin for evaluation-date drift. Public
     * because the seasonal and trend evaluators size their lookback windows
     * against these ceilings -- overrunning one silently returns a short series.
     */
    public const HOURLY_RETENTION_DAYS = 90;
    public const DAILY_RETENTION_DAYS = 400;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Records the observed count for one store view/category/hour.
     *
     * Upserts: an in-progress hour is recorded repeatedly and the latest count wins.
     *
     * @param int $storeViewId
     * @param string $category
     * @param \DateTimeImmutable $hourBucket any instant within the hour; only its UTC top-of-hour is stored
     * @param int $count
     * @return void
     */
    public function recordHourlyCount(
        int $storeViewId,
        string $category,
        \DateTimeImmutable $hourBucket,
        int $count
    ): void {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::HOURLY_TABLE);

        $connection->insertOnDuplicate(
            $table,
            [
                'store_view_id' => $storeViewId,
                'category' => $category,
                'hour_bucket' => $this->formatUtcHour($hourBucket),
                'count' => $count,
            ],
            ['count']
        );
    }

    /**
     * Rolls aged hourly rows into the daily table, then prunes both past their retention windows.
     *
     * Each (store view, category, day) group moves in its own transaction, so a
     * mid-run failure leaves only that group unfinished and retryable. The cutoff
     * is a calendar-day boundary: a day is never summed from a partial set of its
     * hours and rolled again later with the remainder, which would double-count.
     *
     * @param \DateTimeImmutable $now
     * @return RollupPruneResult
     */
    public function rollupAndPrune(\DateTimeImmutable $now): RollupPruneResult
    {
        $connection = $this->resourceConnection->getConnection();
        $hourlyTable = $this->resourceConnection->getTableName(self::HOURLY_TABLE);
        $dailyTable = $this->resourceConnection->getTableName(self::DAILY_TABLE);

        $hourlyCutoff = $this->utcDate($now, -self::HOURLY_RETENTION_DAYS);

        $groups = $connection->fetchAll(
            $connection->select()
                ->from(
                    $hourlyTable,
                    [
                        'store_view_id' => 'store_view_id',
                        'category' => 'category',
                        'day_bucket' => new \Zend_Db_Expr('DATE(hour_bucket)'),
                        'total' => new \Zend_Db_Expr('SUM(count)'),
                    ]
                )
                ->where('hour_bucket < ?', $hourlyCutoff)
                ->group(['store_view_id', 'category', new \Zend_Db_Expr('DATE(hour_bucket)')])
        );

        $rolledDayGroups = 0;
        $hourlyRowsPruned = 0;

        foreach ($groups as $group) {
            $hourlyRowsPruned += $this->rollUpOneDayGroup($connection, $hourlyTable, $dailyTable, $group);
            $rolledDayGroups++;
        }

        $dailyCutoff = $this->utcDate($now, -self::DAILY_RETENTION_DAYS);
        $dailyRowsPruned = (int) $connection->delete($dailyTable, ['day_bucket < ?' => $dailyCutoff]);

        return new RollupPruneResult($rolledDayGroups, $hourlyRowsPruned, $dailyRowsPruned);
    }

    /**
     * Whether any hourly rollup row already exists for this store view across
     * the given categories -- used to gate the one-time historical seed
     * (Model\Seed\HistorySeeder) so it fires exactly once per store view,
     * without a separate persisted flag: this reads the same table the seed
     * itself writes to, rather than inventing new state that could drift
     * out of sync with it.
     *
     * @param int $storeViewId
     * @param string[] $categories
     * @return bool
     */
    public function hasAnyHourlyDataForCategories(int $storeViewId, array $categories): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::HOURLY_TABLE);

        $row = $connection->fetchOne(
            $connection->select()
                ->from($table, [new \Zend_Db_Expr('1')])
                ->where('store_view_id = ?', $storeViewId)
                ->where('category IN (?)', $categories)
                ->limit(1)
        );

        return $row !== false;
    }

    /**
     * Fetches historical hourly counts for one bucket over the last N weeks.
     *
     * Bucket = (store view, category, hour-of-day, day-of-week).
     *
     * @param int $storeViewId
     * @param string $category
     * @param int $isoDayOfWeek day of week, 1 (Monday) through 7 (Sunday), matching DateTimeImmutable::format('N')
     * @param int $hourOfDay hour of day, 0 through 23
     * @param int $weeks how many weeks of history to look back
     * @param \DateTimeImmutable $now
     * @return HourlyCountSample[] oldest first
     */
    public function hourlyCountsForBucket(
        int $storeViewId,
        string $category,
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
                ->where('store_view_id = ?', $storeViewId)
                ->where('category = ?', $category)
                ->where('HOUR(hour_bucket) = ?', $hourOfDay)
                ->where('WEEKDAY(hour_bucket) = ?', $mysqlWeekday)
                ->where('hour_bucket >= ?', $this->utcDate($now, -($weeks * 7)))
                ->where('hour_bucket <= ?', $this->formatUtcHour($now))
                ->order('hour_bucket ASC')
        );

        return array_map($this->toHourlyCountSample(...), $rows);
    }

    /**
     * Fetches every hourly row for one (store view, category) over the last N
     * weeks as a plain chronological series, unconditioned by hour-of-day or
     * day-of-week, unlike hourlyCountsForBucket().
     *
     * @param int $storeViewId
     * @param string $category
     * @param int $weeks how many weeks of history to look back
     * @param \DateTimeImmutable $now
     * @return HourlyCountSample[] oldest first
     */
    public function allHourlyCountsInWindow(
        int $storeViewId,
        string $category,
        int $weeks,
        \DateTimeImmutable $now
    ): array {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::HOURLY_TABLE);

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['hour_bucket', 'count'])
                ->where('store_view_id = ?', $storeViewId)
                ->where('category = ?', $category)
                ->where('hour_bucket >= ?', $this->utcDate($now, -($weeks * 7)))
                ->where('hour_bucket <= ?', $this->formatUtcHour($now))
                ->order('hour_bucket ASC')
        );

        return array_map($this->toHourlyCountSample(...), $rows);
    }

    /**
     * Fetches every daily row for one (store view, category) over the last N days.
     *
     * @param int $storeViewId
     * @param string $category
     * @param \DateTimeImmutable $now
     * @param int $days how many days of history to look back
     * @return DailyCountSample[] oldest first
     */
    public function dailyCountsInWindow(
        int $storeViewId,
        string $category,
        \DateTimeImmutable $now,
        int $days
    ): array {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::DAILY_TABLE);

        // Bare "Y-m-d", not utcDate()'s full datetime: day_bucket is a DATE column,
        // and a datetime lower bound silently drops the boundary day, narrowing the
        // window to $days-1.
        $utc = new \DateTimeZone('UTC');
        $lowerBound = $now->setTimezone($utc)->modify(sprintf('-%d days', $days))->format('Y-m-d');
        $upperBound = $now->setTimezone($utc)->format('Y-m-d');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['day_bucket', 'count'])
                ->where('store_view_id = ?', $storeViewId)
                ->where('category = ?', $category)
                ->where('day_bucket >= ?', $lowerBound)
                ->where('day_bucket <= ?', $upperBound)
                ->order('day_bucket ASC')
        );

        return array_map($this->toDailyCountSample(...), $rows);
    }

    /**
     * Sums and moves one day group's hourly rows into the daily table, then deletes them, as one transaction.
     *
     * @param AdapterInterface $connection
     * @param string $hourlyTable
     * @param string $dailyTable
     * @param array $group
     * @return int hourly rows deleted for this group
     */
    private function rollUpOneDayGroup(
        AdapterInterface $connection,
        string $hourlyTable,
        string $dailyTable,
        array $group
    ): int {
        $storeViewId = (int) $group['store_view_id'];
        $category = $group['category'];
        $dayStart = $group['day_bucket'].' 00:00:00';
        $dayEnd = (new \DateTimeImmutable($group['day_bucket'], new \DateTimeZone('UTC')))
            ->modify('+1 day')
            ->format('Y-m-d H:i:s');

        $connection->beginTransaction();

        try {
            $connection->insertOnDuplicate(
                $dailyTable,
                [
                    'store_view_id' => $storeViewId,
                    'category' => $category,
                    'day_bucket' => $group['day_bucket'],
                    'count' => (int) $group['total'],
                ],
                ['count']
            );

            $deleted = (int) $connection->delete($hourlyTable, [
                'store_view_id = ?' => $storeViewId,
                'category = ?' => $category,
                'hour_bucket >= ?' => $dayStart,
                'hour_bucket < ?' => $dayEnd,
            ]);

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        return $deleted;
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

    /**
     * Maps a raw daily row to its typed domain object.
     *
     * @param array $row
     * @return DailyCountSample
     */
    private function toDailyCountSample(array $row): DailyCountSample
    {
        return new DailyCountSample(
            date: new \DateTimeImmutable($row['day_bucket'], new \DateTimeZone('UTC')),
            count: (int) $row['count'],
        );
    }
}
