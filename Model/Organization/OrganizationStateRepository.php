<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Organization;

use Magento\Framework\App\ResourceConnection;

/**
 * Locally cached organization_paused flag; while paused the connector stops
 * submitting sync/metrics but keeps pinging. The platform only surfaces it on
 * GET /api/installs/ping's response body, so the value is cached rather than
 * re-pinged every cycle; PingService::ping() and a 403 from POST
 * /api/installs/metrics both refresh it.
 *
 * No row means "never determined" -- isPaused() fails open. A "paused" reading
 * older than STALE_AFTER_HOURS expires too: neither service issues a real
 * request while it trusts "paused" and no scheduled job refreshes the cache, so
 * an unpause could otherwise never be self-detected.
 *
 * save() stamps updated_at explicitly: MySQL's ON UPDATE CURRENT_TIMESTAMP does
 * not fire when the value is unchanged, exactly the repeated "still paused" case.
 */
class OrganizationStateRepository
{
    private const TABLE = 'watchtower_organization_state';
    private const ROW_ID = 1;

    /**
     * Long enough not to retry a still-paused organization every hourly cycle,
     * short enough to notice a real unpause within the same day.
     */
    private const STALE_AFTER_HOURS = 6;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Whether the organization is known to currently be paused, per a cached reading no older than STALE_AFTER_HOURS.
     *
     * @param \DateTimeImmutable $now
     * @return bool
     */
    public function isPaused(\DateTimeImmutable $now): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('id = ?', self::ROW_ID)
        );

        if ($row === false || !(bool) $row['organization_paused']) {
            return false;
        }

        $updatedAt = new \DateTimeImmutable($row['updated_at'], new \DateTimeZone('UTC'));
        $staleCutoff = $now->modify(sprintf('-%d hours', self::STALE_AFTER_HOURS));

        return $updatedAt >= $staleCutoff;
    }

    /**
     * Records the platform's own current organization_paused value (from a ping or a self-detected 403/200).
     *
     * @param bool $paused
     * @param \DateTimeImmutable $now
     * @return void
     */
    public function save(bool $paused, \DateTimeImmutable $now): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->insertOnDuplicate(
            $table,
            [
                'id' => self::ROW_ID,
                'organization_paused' => $paused,
                'updated_at' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ],
            ['organization_paused', 'updated_at']
        );
    }
}
