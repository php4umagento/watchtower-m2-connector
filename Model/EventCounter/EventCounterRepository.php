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
     * Formats an instant as its UTC top-of-hour string, the granularity both counter tables store.
     *
     * @param \DateTimeImmutable $dateTime
     * @return string
     */
    private function formatUtcHour(\DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:00:00');
    }
}
