<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

use Magento\Framework\App\ResourceConnection;

/**
 * CRUD for watchtower_integration_health_config -- one row per store view
 * that has configured an integration_health source.
 */
class IntegrationHealthConfigRepository
{
    private const TABLE = 'watchtower_integration_health_config';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Fetches the configured source for a store view, or null if none is configured.
     *
     * @param int $storeViewId
     * @return IntegrationHealthConfig|null
     */
    public function get(int $storeViewId): ?IntegrationHealthConfig
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('store_view_id = ?', $storeViewId)
        );

        if ($row === false) {
            return null;
        }

        return new IntegrationHealthConfig(
            storeViewId: $storeViewId,
            sourceType: (string) $row['source_type'],
            sourceIdentifier: (string) $row['source_identifier'],
            expectedMaxIntervalMinutes: (int) $row['expected_max_interval_minutes'],
        );
    }

    /**
     * Fetches every store view's configured source, keyed by store view id.
     *
     * @return array<int, IntegrationHealthConfig>
     */
    public function getAll(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $rows = $connection->fetchAll($connection->select()->from($table));

        $configs = [];

        foreach ($rows as $row) {
            $storeViewId = (int) $row['store_view_id'];
            $configs[$storeViewId] = new IntegrationHealthConfig(
                storeViewId: $storeViewId,
                sourceType: (string) $row['source_type'],
                sourceIdentifier: (string) $row['source_identifier'],
                expectedMaxIntervalMinutes: (int) $row['expected_max_interval_minutes'],
            );
        }

        return $configs;
    }

    /**
     * Persists a store view's configured source, upserting by store view.
     *
     * @param IntegrationHealthConfig $config
     * @return void
     */
    public function save(IntegrationHealthConfig $config): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->insertOnDuplicate(
            $table,
            [
                'store_view_id' => $config->storeViewId,
                'source_type' => $config->sourceType,
                'source_identifier' => $config->sourceIdentifier,
                'expected_max_interval_minutes' => $config->expectedMaxIntervalMinutes,
            ],
            ['source_type', 'source_identifier', 'expected_max_interval_minutes']
        );
    }

    /**
     * Clears a store view's configured source, returning it to the "not evaluated" state.
     *
     * @param int $storeViewId
     * @return void
     */
    public function delete(int $storeViewId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->delete($table, ['store_view_id = ?' => $storeViewId]);
    }
}
