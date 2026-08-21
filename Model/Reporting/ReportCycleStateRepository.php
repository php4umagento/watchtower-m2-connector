<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Reporting;

use Magento\Framework\App\ResourceConnection;

/**
 * CRUD for watchtower_report_cycle_state, a singleton row (same pattern as
 * ConnectorVersionStateRepository/IgnoredDomainStateRepository).
 */
class ReportCycleStateRepository
{
    private const TABLE = 'watchtower_report_cycle_state';
    private const ROW_ID = 1;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * When the real cycle last ran, or an all-null default if it never has.
     *
     * @return ReportCycleState
     */
    public function get(): ReportCycleState
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('id = ?', self::ROW_ID)
        );

        if ($row === false) {
            return new ReportCycleState(lastRunAt: null);
        }

        return new ReportCycleState(
            lastRunAt: new \DateTimeImmutable($row['last_run_at'], new \DateTimeZone('UTC')),
        );
    }

    /**
     * Records that the real cycle just ran.
     *
     * @param \DateTimeImmutable $now
     * @return void
     */
    public function save(\DateTimeImmutable $now): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $data = [
            'id' => self::ROW_ID,
            'last_run_at' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];

        $connection->insertOnDuplicate($table, $data, array_keys($data));
    }
}
