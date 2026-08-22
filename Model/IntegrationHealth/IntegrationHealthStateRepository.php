<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

use Magento\Framework\App\ResourceConnection;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;

/**
 * CRUD for watchtower_integration_health_state -- the per-store-view
 * analogue of HealthStateRepository. Needed for the same reason that one
 * exists: the underlying source tables are not durable history on their own.
 */
class IntegrationHealthStateRepository
{
    private const TABLE = 'watchtower_integration_health_state';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Fetches the current integration health state for a store view, or a fresh default when none exists yet.
     *
     * @param int $storeViewId
     * @return IntegrationHealthState
     */
    public function get(int $storeViewId): IntegrationHealthState
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('store_view_id = ?', $storeViewId)
        );

        if ($row === false) {
            return new IntegrationHealthState(
                storeViewId: $storeViewId,
                lastSuccessAt: null,
                lastFailureAt: null,
                pendingStatus: null,
                confirmedStatus: null,
                sequenceNumber: 1,
            );
        }

        return new IntegrationHealthState(
            storeViewId: $storeViewId,
            lastSuccessAt: $this->toDateTime($row['last_success_at']),
            lastFailureAt: $this->toDateTime($row['last_failure_at']),
            pendingStatus: SignalStatus::tryFrom((string) $row['pending_status']),
            confirmedStatus: SignalStatus::tryFrom((string) $row['confirmed_status']),
            sequenceNumber: (int) $row['sequence_number'],
            lastReportedReason: ReportReason::tryFrom((string) ($row['last_reported_reason'] ?? '')),
        );
    }

    /**
     * Persists an integration health state, upserting by store view.
     *
     * @param IntegrationHealthState $state
     * @return void
     */
    public function save(IntegrationHealthState $state): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->insertOnDuplicate(
            $table,
            [
                'store_view_id' => $state->storeViewId,
                'last_success_at' => $state->lastSuccessAt?->format('Y-m-d H:i:s'),
                'last_failure_at' => $state->lastFailureAt?->format('Y-m-d H:i:s'),
                'pending_status' => $state->pendingStatus?->value,
                'confirmed_status' => $state->confirmedStatus?->value,
                'last_reported_reason' => $state->lastReportedReason?->value,
                'sequence_number' => $state->sequenceNumber,
            ],
            [
                'last_success_at',
                'last_failure_at',
                'pending_status',
                'confirmed_status',
                'last_reported_reason',
                'sequence_number',
            ]
        );
    }

    /**
     * Converts a nullable stored datetime string to a DateTimeImmutable.
     *
     * Explicit UTC, not the bare single-arg form: stored datetimes are
     * always UTC, and parsing them without saying so falls back to PHP's
     * default timezone.
     *
     * @param string|null $value
     * @return \DateTimeImmutable|null
     */
    private function toDateTime(?string $value): ?\DateTimeImmutable
    {
        return $value !== null ? new \DateTimeImmutable($value, new \DateTimeZone('UTC')) : null;
    }
}
