<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\RateSignal;

use Magento\Framework\App\ResourceConnection;
use Watchtower\Connector\Model\Api\SignalStatus;

/**
 * CRUD for watchtower_dispersion_state, DispersionEvaluator's own durable
 * debounce state per (store view, category) pair; the rate-based analogue
 * of HealthStateRepository, which is install-scoped and has no
 * store_view_id column, so cannot be reused here as-is.
 */
class DispersionStateRepository
{
    private const TABLE = 'watchtower_dispersion_state';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Fetches the current dispersion state for a store view/category pair, or a fresh default when none exists yet.
     *
     * @param int $storeViewId
     * @param string $category
     * @return DispersionState
     */
    public function get(int $storeViewId, string $category): DispersionState
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
            return new DispersionState(
                storeViewId: $storeViewId,
                category: $category,
                pendingStatus: null,
                confirmedStatus: null,
                sequenceNumber: 1,
            );
        }

        return new DispersionState(
            storeViewId: $storeViewId,
            category: $category,
            pendingStatus: SignalStatus::tryFrom((string) $row['pending_status']),
            confirmedStatus: SignalStatus::tryFrom((string) $row['confirmed_status']),
            sequenceNumber: (int) $row['sequence_number'],
        );
    }

    /**
     * Persists a dispersion state, upserting by store view and category.
     *
     * @param DispersionState $state
     * @return void
     */
    public function save(DispersionState $state): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->insertOnDuplicate(
            $table,
            [
                'store_view_id' => $state->storeViewId,
                'category' => $state->category,
                'pending_status' => $state->pendingStatus?->value,
                'confirmed_status' => $state->confirmedStatus?->value,
                'sequence_number' => $state->sequenceNumber,
            ],
            ['pending_status', 'confirmed_status', 'sequence_number']
        );
    }
}
