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
     * Must match the event_name column width in etc/db_schema.xml for BOTH
     * counter tables.
     *
     * Enforced rather than trusted because of how this failed once: the
     * column was varchar(32), CheckoutFailureObserver wrote the 40-character
     * 'sales_model_service_quote_submit_failure', and MySQL in its default
     * non-strict mode truncated it to 32 without complaint. The writer then
     * stored one key while the reader queried another, so the signal read
     * zero forever and looked like a store with no failures rather than a
     * broken counter. Worse, the truncated form collides with
     * sales_model_service_quote_submit_before/_success, which share that
     * 32-character prefix, so two different events would have summed into
     * one row.
     *
     * A store running MySQL in strict mode would have thrown instead, which
     * the observers catch, so that install would have logged and carried on
     * with the same silent zero. Neither mode is detectable from the data.
     */
    public const MAX_EVENT_NAME_LENGTH = 64;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Fails loudly on a name the event_name column cannot hold.
     *
     * The alternative is letting the database silently shorten it.
     *
     * The caller is always this module's own code with a compile-time
     * constant name, so this can only fire during development, which is
     * exactly when it is useful. Observers wrap their bodies in a
     * \Throwable catch, so even in the impossible case of this reaching a
     * merchant it degrades to a logged error rather than a broken checkout.
     *
     * @param string $eventName
     * @return void
     * @throws \LengthException
     */
    private function assertEventNameFits(string $eventName): void
    {
        if (strlen($eventName) > self::MAX_EVENT_NAME_LENGTH) {
            throw new \LengthException(sprintf(
                'Event name "%s" is %d characters, exceeding the %d-character event_name column. '
                . 'Widen the column in etc/db_schema.xml rather than shortening the name, so the '
                . 'counter stays literal about which event it observed.',
                $eventName,
                strlen($eventName),
                self::MAX_EVENT_NAME_LENGTH
            ));
        }
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
        $this->assertEventNameFits($eventName);

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
        $this->assertEventNameFits($eventName);

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
     * Hourly counts for one store view/event name over a lookback window.
     *
     * Keyed by UTC top-of-hour string, for the last $days before $before.
     * Only hours that actually have a row appear, so a store with no failures
     * in a given hour simply has no key for it -- the caller decides what a
     * missing hour means. Excludes $before's own hour, so a caller learning
     * from history never includes the hour it is currently evaluating.
     *
     * @param int $storeViewId
     * @param string $eventName
     * @param int $days how many days back from $before to include
     * @param \DateTimeImmutable $before hours strictly earlier than this instant's top-of-hour are returned
     * @return array<string, int> hour_bucket string => count
     */
    public function countsInWindow(int $storeViewId, string $eventName, int $days, \DateTimeImmutable $before): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::COUNTER_TABLE);

        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($table, ['hour_bucket', 'count'])
                ->where('store_view_id = ?', $storeViewId)
                ->where('event_name = ?', $eventName)
                ->where('hour_bucket >= ?', $this->utcDate($before, -$days))
                ->where('hour_bucket < ?', $this->formatUtcHour($before))
                ->order('hour_bucket ASC')
        );

        return array_map(static fn ($count): int => (int) $count, $rows);
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
