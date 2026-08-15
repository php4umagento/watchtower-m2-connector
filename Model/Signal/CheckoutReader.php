<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Signal;

use Magento\Framework\App\ResourceConnection;

/**
 * Counts `sales_order` rows (order placements) created within an hourly
 * window, with NO status filter -- order status mutates after creation, so
 * filtering by current status would make a historical hour's contents
 * depend on when it is queried. This reader counts every order regardless
 * of status.
 *
 * Reads the `sales_order` entity table, NEVER `sales_order_grid` -- an
 * Adobe Commerce archiving artifact ("logical partitions ... to increase
 * performance for read operations on these grids") that is not reliably
 * present or current on every edition. `sales_order` itself is never
 * truncated by that archiving.
 */
class CheckoutReader implements RateSignalReaderInterface
{
    private const TABLE = 'sales_order';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Counts orders placed for one store view within [$windowStart, $windowEnd), unconditional on status.
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
