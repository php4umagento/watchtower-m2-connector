<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\AdminAuthFailure;

use Magento\Framework\App\ResourceConnection;

/**
 * CRUD for watchtower_install_event_counter: install-scoped hourly counts for
 * events with no store view to attribute them to.
 *
 * The shape mirrors EventCounter\EventCounterRepository's real counter table,
 * minus the store_view_id column, which does not apply here -- there is
 * exactly one of this table's rows per (event name, hour), not one per store
 * view. This is a separate table and a separate class rather than a third
 * mode bolted onto EventCounterRepository, because that class's docblock and
 * every caller already assume every real count is store-view-scoped; adding
 * an install-scoped branch there would be exactly the kind of implicit,
 * easy-to-miss special case this module tries not to create.
 */
class InstallEventCounterRepository
{
    private const TABLE = 'watchtower_install_event_counter';

    /** Matches EventCounterRepository::RETENTION_DAYS -- same rationale, same table shape. */
    public const RETENTION_DAYS = 90;

    /** Matches EventCounterRepository::MAX_EVENT_NAME_LENGTH; see that constant's docblock for why this is enforced. */
    public const MAX_EVENT_NAME_LENGTH = 64;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Increments the counter for one event name/hour by one, creating the row on its first occurrence.
     *
     * @param string $eventName
     * @param \DateTimeImmutable $now
     * @return void
     */
    public function increment(string $eventName, \DateTimeImmutable $now): void
    {
        $this->assertEventNameFits($eventName);

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

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
     * The current count for one event name/hour, or 0 when no row exists yet.
     *
     * @param string $eventName
     * @param \DateTimeImmutable $hourBucket any instant within the hour; only its UTC top-of-hour is compared
     * @return int
     */
    public function countFor(string $eventName, \DateTimeImmutable $hourBucket): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $value = $connection->fetchOne(
            $connection->select()
                ->from($table, ['count'])
                ->where('event_name = ?', $eventName)
                ->where('hour_bucket = ?', $this->formatUtcHour($hourBucket))
        );

        return $value !== false ? (int) $value : 0;
    }

    /**
     * Deletes rows older than RETENTION_DAYS, returning the number removed.
     *
     * @param \DateTimeImmutable $now
     * @return int
     */
    public function prune(\DateTimeImmutable $now): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $cutoff = $now->modify('-'.self::RETENTION_DAYS.' days')
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:00:00');

        return (int) $connection->delete($table, ['hour_bucket < ?' => $cutoff]);
    }

    /**
     * Fails loudly on a name the event_name column cannot hold, instead of letting the database silently shorten it.
     *
     * See EventCounterRepository::assertEventNameFits()'s docblock for the
     * incident this guard exists because of.
     *
     * @param string $eventName
     * @return void
     * @throws \LengthException
     */
    private function assertEventNameFits(string $eventName): void
    {
        if (strlen($eventName) > self::MAX_EVENT_NAME_LENGTH) {
            throw new \LengthException(sprintf(
                'Event name "%s" is %d characters, exceeding the %d-character event_name column.',
                $eventName,
                strlen($eventName),
                self::MAX_EVENT_NAME_LENGTH
            ));
        }
    }

    /**
     * The UTC top-of-hour instant containing $now, as MySQL DATETIME text.
     *
     * @param \DateTimeImmutable $now
     * @return string
     */
    private function formatUtcHour(\DateTimeImmutable $now): string
    {
        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:00:00');
    }
}
