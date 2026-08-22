<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Seed;

use Magento\Framework\App\ResourceConnection;

/**
 * CRUD for watchtower_seed_coverage -- the durable record of the last
 * HistorySeeder::seed() outcome per (store view, category), so the
 * diagnostics page and watchtower:status can read back "why is this still
 * warming up?" without re-seeding, which DiagnosticsSnapshotProvider must
 * never trigger as a side effect of rendering a page.
 */
class SeedCoverageRepository
{
    private const TABLE = 'watchtower_seed_coverage';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Fetches the last persisted seed coverage outcome for a store view/category pair.
     *
     * Null means this pair has never been seeded -- either seeding hasn't
     * run yet, or the category cold-started on the event bus instead.
     *
     * @param int $storeViewId
     * @param string $category
     * @return SeedCoverageResult|null
     */
    public function get(int $storeViewId, string $category): ?SeedCoverageResult
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $row = $connection->fetchRow(
            $connection->select()
                ->from($table)
                ->where('store_view_id = ?', $storeViewId)
                ->where('category = ?', $category)
        );

        if ($row === false) {
            return null;
        }

        return new SeedCoverageResult(
            category: $category,
            requestedDays: (int) $row['requested_days'],
            daysSeeded: (int) $row['days_seeded'],
            status: SeedCoverageStatus::from($row['status']),
            limitReason: SeedLimitReason::tryFrom((string) ($row['limit_reason'] ?? '')),
            sourceRetentionDays: $row['source_retention_days'] !== null ? (int) $row['source_retention_days'] : null,
        );
    }

    /**
     * Persists a seed coverage outcome, upserting by store view and category.
     *
     * @param int $storeViewId
     * @param SeedCoverageResult $result
     * @return void
     */
    public function save(int $storeViewId, SeedCoverageResult $result): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->insertOnDuplicate(
            $table,
            [
                'store_view_id' => $storeViewId,
                'category' => $result->category,
                'status' => $result->status->value,
                'requested_days' => $result->requestedDays,
                'days_seeded' => $result->daysSeeded,
                'limit_reason' => $result->limitReason?->value,
                'source_retention_days' => $result->sourceRetentionDays,
            ],
            ['status', 'requested_days', 'days_seeded', 'limit_reason', 'source_retention_days']
        );
    }
}
