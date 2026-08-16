<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Environment;

use Magento\Framework\App\ResourceConnection;
use Watchtower\Connector\Model\Api\ConnectorUpdateInfo;
use Watchtower\Connector\Model\Api\MagentoEolInfo;

/**
 * CRUD for watchtower_environment_state, a singleton row (same pattern as
 * OrganizationStateRepository) caching the environment facts from the last
 * successful sync and the platform's own EOL/update determination that came
 * back with it.
 */
class EnvironmentStateRepository
{
    private const TABLE = 'watchtower_environment_state';
    private const ROW_ID = 1;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * The last-known environment state, or an all-null state if a successful sync has never happened.
     *
     * @return EnvironmentState
     */
    public function get(): EnvironmentState
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('id = ?', self::ROW_ID)
        );

        if ($row === false) {
            return new EnvironmentState(
                magentoVersion: null,
                magentoEdition: null,
                connectorVersion: null,
                magentoEol: null,
                connectorUpdate: null,
                syncedAt: null,
            );
        }

        return new EnvironmentState(
            magentoVersion: $row['magento_version'],
            magentoEdition: $row['magento_edition'],
            connectorVersion: $row['connector_version'],
            magentoEol: $row['magento_is_eol'] === null ? null : new MagentoEolInfo(
                isEol: (bool) $row['magento_is_eol'],
                eolDate: $row['magento_eol_date'],
                statusLabel: $row['magento_status_label'],
            ),
            connectorUpdate: $row['connector_update_available'] === null ? null : new ConnectorUpdateInfo(
                updateAvailable: (bool) $row['connector_update_available'],
                latestVersion: $row['connector_latest_version'],
            ),
            syncedAt: $row['synced_at'] === null
                ? null
                : new \DateTimeImmutable($row['synced_at'], new \DateTimeZone('UTC')),
        );
    }

    /**
     * Records the environment facts from a successful sync and the platform's own EOL/update determination.
     *
     * @param string|null $magentoVersion
     * @param string|null $magentoEdition
     * @param string|null $connectorVersion
     * @param MagentoEolInfo|null $magentoEol
     * @param ConnectorUpdateInfo|null $connectorUpdate
     * @param \DateTimeImmutable $now
     * @return void
     */
    public function save(
        ?string $magentoVersion,
        ?string $magentoEdition,
        ?string $connectorVersion,
        ?MagentoEolInfo $magentoEol,
        ?ConnectorUpdateInfo $connectorUpdate,
        \DateTimeImmutable $now
    ): void {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $data = [
            'id' => self::ROW_ID,
            'magento_version' => $magentoVersion,
            'magento_edition' => $magentoEdition,
            'connector_version' => $connectorVersion,
            'magento_is_eol' => $magentoEol === null ? null : (int) $magentoEol->isEol,
            'magento_eol_date' => $magentoEol?->eolDate,
            'magento_status_label' => $magentoEol?->statusLabel,
            'connector_update_available' => $connectorUpdate === null ? null : (int) $connectorUpdate->updateAvailable,
            'connector_latest_version' => $connectorUpdate?->latestVersion,
            'synced_at' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];

        $connection->insertOnDuplicate($table, $data, array_keys($data));
    }
}
