<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Signal;

use Magento\Framework\App\ResourceConnection;

/**
 * Counts `customer_entity` rows (registrations) created within an hourly
 * window, filtered by store_id. The only table-sourced sub-counter of
 * customer_account -- logins and logouts stay on the event bus
 * (customer_log has no store_id and is overwritten per customer, so it
 * cannot serve either), which is why customer_account is the one signal
 * that mixes a seeded table source with a cold event source.
 */
class CustomerAccountRegistrationReader implements RateSignalReaderInterface
{
    private const TABLE = 'customer_entity';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Counts customer registrations for one store view within [$windowStart, $windowEnd).
     *
     * @param int $storeViewId
     * @param \DateTimeImmutable $windowStart inclusive
     * @param \DateTimeImmutable $windowEnd exclusive
     * @return int
     */
    public function countForWindow(
        int $storeViewId,
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd
    ): int {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $select = $connection->select()
            ->from($table, ['count' => new \Zend_Db_Expr('COUNT(*)')])
            ->where('store_id = ?', $storeViewId)
            ->where('created_at >= ?', $this->formatUtc($windowStart))
            ->where('created_at < ?', $this->formatUtc($windowEnd));

        return (int) $connection->fetchOne($select);
    }

    /**
     * Every datetime comparison in this reader is made in UTC. True today
     * only because Magento pins PHP's ambient timezone to UTC (see
     * app/bootstrap.php), but made explicit here rather than inherited.
     *
     * @param \DateTimeImmutable $dateTime
     * @return string
     */
    private function formatUtc(\DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
