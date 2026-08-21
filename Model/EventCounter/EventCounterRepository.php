<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\EventCounter;

use Magento\Framework\App\ResourceConnection;

/**
 * CRUD for watchtower_event_counter (per store view/event name/hour) and its
 * companion watchtower_event_drop_counter (per event name/hour, no store
 * view). These hold customer_account's login and logout sub-counters, which
 * cannot be seeded from a table and instead accumulate one observed event at
 * a time.
 *
 * Because each call records a single discrete event rather than a recomputed
 * total, the upsert must increment the existing row rather than replace it:
 * `count = count + VALUES(count)`, not the plain `count = VALUES(count)`
 * insertOnDuplicate() produces by default.
 */
class EventCounterRepository
{
    private const COUNTER_TABLE = 'watchtower_event_counter';
    private const DROP_COUNTER_TABLE = 'watchtower_event_drop_counter';

    /**
     * Matches RollupRepository::HOURLY_RETENTION_DAYS: nothing here reads
     * further back than the 24-hour diagnostic window
     * (totalDroppedInLast24Hours()), so there is no seasonal-lookback
     * requirement pulling this any higher, but 90 days keeps a comfortable
     * margin for ad-hoc debugging without letting either table grow
     * unbounded.
     */
    public const RETENTION_DAYS = 90;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Increments the counter for one store view/event name/hour by one, creating the row on its first occurrence.
     *
     * @param int $storeViewId
     * @param string $eventName
     * @param \DateTimeImmutable $now
     * @return void
     */
    public function increment(int $storeViewId, string $eventName, \DateTimeImmutable $now): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::COUNTER_TABLE);

        $connection->insertOnDuplicate(
            $table,
            [
                'store_view_id' => $storeViewId,
                'event_name' => $eventName,
                'hour_bucket' => $this->formatUtcHour($now),
                'count' => 1,
            ],
            ['count' => new \Zend_Db_Expr('count + VALUES(count)')]
        );
    }

    /**
     * Increments the local diagnostic drop counter for one event name/hour
     * by one, creating the row on its first occurrence. Never transmitted
     * to the platform -- see CustomerSessionObserver.
     *
     * @param string $eventName
     * @param \DateTimeImmutable $now
     * @return void
     */
    public function incrementDropped(string $eventName, \DateTimeImmutable $now): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::DROP_COUNTER_TABLE);

        $connection->insertOnDuplicate(
            $table,
            [
                'event_name' => $eventName,
                'hour_bucket' => $this->formatUtcHour($now),
                'count' => 1,
            ],
            ['count' => new \Zend_Db_Expr('count + VALUES(count)')]
        );
    }

    /**
     * The current count for one store view/event name/hour, or 0 when no row exists yet.
     *
     * @param int $storeViewId
     * @param string $eventName
     * @param \DateTimeImmutable $hourBucket any instant within the hour; only its UTC top-of-hour is compared
     * @return int
     */
    public function countFor(int $storeViewId, string $eventName, \DateTimeImmutable $hourBucket): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::COUNTER_TABLE);

        $value = $connection->fetchOne(
            $connection->select()
                ->from($table, ['count'])
                ->where('store_view_id = ?', $storeViewId)
                ->where('event_name = ?', $eventName)
                ->where('hour_bucket = ?', $this->formatUtcHour($hourBucket))
        );

        return $value !== false ? (int) $value : 0;
    }

    /**
     * The current dropped-event count for one event name/hour, or 0 when no row exists yet.
     *
     * @param string $eventName
     * @param \DateTimeImmutable $hourBucket any instant within the hour; only its UTC top-of-hour is compared
     * @return int
     */
    public function droppedCountFor(string $eventName, \DateTimeImmutable $hourBucket): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::DROP_COUNTER_TABLE);

        $value = $connection->fetchOne(
            $connection->select()
                ->from($table, ['count'])
                ->where('event_name = ?', $eventName)
                ->where('hour_bucket = ?', $this->formatUtcHour($hourBucket))
        );

        return $value !== false ? (int) $value : 0;
    }

    /**
     * Total dropped-event count across every event name, summed over the 24
     * hours up to and including $now's own hour. A bounded window for a
     * status snapshot rather than an unbounded lifetime total.
     *
     * @param \DateTimeImmutable $now
     * @return int
     */
    public function totalDroppedInLast24Hours(\DateTimeImmutable $now): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::DROP_COUNTER_TABLE);
        $since = $now->modify('-24 hours');

        $value = $connection->fetchOne(
            $connection->select()
                ->from($table, ['SUM(count)'])
                ->where('hour_bucket >= ?', $this->formatUtcHour($since))
        );

        // A bare SUM() with no GROUP BY always returns one row (NULL when
        // nothing matches), never "no row at all", so unlike countFor() only
        // the NULL case needs handling.
        return $value !== null ? (int) $value : 0;
    }

    /**
     * Deletes rows in both counter tables whose hour_bucket is past RETENTION_DAYS.
     *
     * Unlike RollupRepository::rollupAndPrune(), there is no daily table to
     * roll aged rows into first -- these are already the coarsest form
     * either counter is ever kept in, so pruning is a single unconditional
     * delete per table.
     *
     * @param \DateTimeImmutable $now
     * @return EventCounterPruneResult
     */
    public function prune(\DateTimeImmutable $now): EventCounterPruneResult
    {
        $connection = $this->resourceConnection->getConnection();
        $cutoff = $this->utcDate($now, -self::RETENTION_DAYS);

        $counterTable = $this->resourceConnection->getTableName(self::COUNTER_TABLE);
        $dropCounterTable = $this->resourceConnection->getTableName(self::DROP_COUNTER_TABLE);

        $counterRowsPruned = (int) $connection->delete($counterTable, ['hour_bucket < ?' => $cutoff]);
        $dropCounterRowsPruned = (int) $connection->delete($dropCounterTable, ['hour_bucket < ?' => $cutoff]);

        return new EventCounterPruneResult($counterRowsPruned, $dropCounterRowsPruned);
    }

    /**
     * Formats an instant as its UTC top-of-hour string, the granularity both counter tables store.
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
}
