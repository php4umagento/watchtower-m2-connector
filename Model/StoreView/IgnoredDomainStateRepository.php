<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\StoreView;

use Magento\Framework\App\ResourceConnection;

/**
 * CRUD for watchtower_ignored_domain_state, a singleton row (same pattern
 * as ConnectorVersionStateRepository). save() runs on every successful sync
 * with that sync's own count, including 0, so a resolved local domain
 * clears the notice on the next sync rather than staying stuck displayed.
 */
class IgnoredDomainStateRepository
{
    private const TABLE = 'watchtower_ignored_domain_state';
    private const ROW_ID = 1;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * The last sync's ignored-local-domain outcome, or an all-empty default if never synced.
     *
     * @return IgnoredDomainState
     */
    public function get(): IgnoredDomainState
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('id = ?', self::ROW_ID)
        );

        if ($row === false) {
            return new IgnoredDomainState(ignoredCount: 0, exampleCode: null, occurredAt: null);
        }

        return new IgnoredDomainState(
            ignoredCount: (int) $row['ignored_count'],
            exampleCode: $row['example_code'],
            occurredAt: $row['occurred_at'] === null
                ? null
                : new \DateTimeImmutable($row['occurred_at'], new \DateTimeZone('UTC')),
        );
    }

    /**
     * Records the current sync's ignored-local-domain outcome, overwriting whatever was there before.
     *
     * @param int $ignoredCount 0 clears a previously-displayed notice
     * @param string|null $exampleCode null when $ignoredCount is 0
     * @param \DateTimeImmutable $now
     * @return void
     */
    public function save(int $ignoredCount, ?string $exampleCode, \DateTimeImmutable $now): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $data = [
            'id' => self::ROW_ID,
            'ignored_count' => $ignoredCount,
            'example_code' => $exampleCode,
            'occurred_at' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];

        $connection->insertOnDuplicate($table, $data, array_keys($data));
    }
}
