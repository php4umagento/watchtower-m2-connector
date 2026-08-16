<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Environment;

use Magento\Framework\App\ResourceConnection;

/**
 * CRUD for watchtower_connector_version_state, a singleton row (same
 * pattern as OrganizationStateRepository/EnvironmentStateRepository).
 *
 * save() is only ever meant to be called after a SUCCESSFUL
 * ConnectorVersionCheckService::check() -- a failed check (network error,
 * non-200) carries no information about whether this install is still below
 * minimum_version, and per PRD FR24's own contract a failure must never
 * itself change self-disabled status. The caller (ReportingService) enforces
 * this by simply not calling save() on a failed check, so the persisted
 * belowMinimum flag always reflects the last time the platform was actually
 * asked, not a guess made in its absence.
 */
class ConnectorVersionStateRepository
{
    private const TABLE = 'watchtower_connector_version_state';
    private const ROW_ID = 1;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * The last successfully-checked state, or an all-null/not-below-minimum default if never checked.
     *
     * @return ConnectorVersionState
     */
    public function get(): ConnectorVersionState
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('id = ?', self::ROW_ID)
        );

        if ($row === false) {
            return new ConnectorVersionState(
                installedVersion: null,
                minimumVersion: null,
                latestVersion: null,
                belowMinimum: false,
                updateAvailable: false,
                checkedAt: null,
            );
        }

        return new ConnectorVersionState(
            installedVersion: $row['installed_version'],
            minimumVersion: $row['minimum_version'],
            latestVersion: $row['latest_version'],
            belowMinimum: (bool) $row['below_minimum'],
            updateAvailable: (bool) $row['update_available'],
            checkedAt: $row['checked_at'] === null
                ? null
                : new \DateTimeImmutable($row['checked_at'], new \DateTimeZone('UTC')),
        );
    }

    /**
     * Records a successful check's outcome.
     *
     * See this class's own docblock for why a failed check must never reach this method.
     *
     * @param string|null $installedVersion
     * @param string|null $minimumVersion
     * @param string|null $latestVersion
     * @param bool $belowMinimum
     * @param bool $updateAvailable
     * @param \DateTimeImmutable $now
     * @return void
     */
    public function save(
        ?string $installedVersion,
        ?string $minimumVersion,
        ?string $latestVersion,
        bool $belowMinimum,
        bool $updateAvailable,
        \DateTimeImmutable $now
    ): void {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $data = [
            'id' => self::ROW_ID,
            'installed_version' => $installedVersion,
            'minimum_version' => $minimumVersion,
            'latest_version' => $latestVersion,
            'below_minimum' => (int) $belowMinimum,
            'update_available' => (int) $updateAvailable,
            'checked_at' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];

        $connection->insertOnDuplicate($table, $data, array_keys($data));
    }
}
