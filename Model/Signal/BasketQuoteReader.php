<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Signal;

use Magento\Framework\App\ResourceConnection;

/**
 * Counts `quote` rows created within an hourly window, filtered to
 * non-empty carts (items_count > 0) so an abandoned-at-zero session quote
 * never inflates the basket_quote signal.
 *
 * NEVER `quote_item` -- rows are deleted on cart-line removal, eroding counts on
 * top of ordinary quote expiry. NEVER `sales_order.quote_id` -- it tracks
 * `checkout` almost exactly, collapsing the intent-vs-conversion separation.
 */
class BasketQuoteReader implements RateSignalReaderInterface
{
    private const TABLE = 'quote';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Counts non-empty quotes created for one store view within [$windowStart, $windowEnd).
     *
     * The `updated_at` condition looks redundant but is not: `created_at` is
     * unindexed, and since `created_at <= updated_at` always holds it can only
     * prune rows the `created_at` filter would reject anyway -- while letting the
     * QUOTE_STORE_ID_UPDATED_AT index replace a full scan of the store's quotes.
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
            ->where('items_count > ?', 0)
            ->where('updated_at >= ?', $this->formatUtc($windowStart))
            ->where('created_at >= ?', $this->formatUtc($windowStart))
            ->where('created_at < ?', $this->formatUtc($windowEnd));

        return (int) $connection->fetchOne($select);
    }

    /**
     * Every datetime comparison in this reader is made in UTC.
     *
     * Explicit rather than inherited from Magento's ambient UTC bootstrap timezone.
     *
     * @param \DateTimeImmutable $dateTime
     * @return string
     */
    private function formatUtc(\DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
