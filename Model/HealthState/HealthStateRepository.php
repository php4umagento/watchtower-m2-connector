<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\HealthState;

use Magento\Framework\App\ResourceConnection;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;

/**
 * CRUD for watchtower_health_state, the connector's own durable record of a
 * state-based signal's (success, failure, debounce, sequence) state. Needed
 * because Magento's cron_schedule purges aggressively and is not durable
 * history.
 */
class HealthStateRepository
{
    private const TABLE = 'watchtower_health_state';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Fetches the current health state for an event type, or a fresh default when none exists yet.
     *
     * @param string $eventType
     * @return HealthState
     */
    public function get(string $eventType): HealthState
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('event_type = ?', $eventType)
        );

        if ($row === false) {
            return new HealthState(
                eventType: $eventType,
                lastSuccessAt: null,
                lastFailureAt: null,
                pendingStatus: null,
                confirmedStatus: null,
                sequenceNumber: 1,
            );
        }

        return new HealthState(
            eventType: $eventType,
            lastSuccessAt: $this->toDateTime($row['last_success_at']),
            lastFailureAt: $this->toDateTime($row['last_failure_at']),
            pendingStatus: SignalStatus::tryFrom((string) $row['pending_status']),
            confirmedStatus: SignalStatus::tryFrom((string) $row['confirmed_status']),
            sequenceNumber: (int) $row['sequence_number'],
            lastReportedReason: ReportReason::tryFrom((string) ($row['last_reported_reason'] ?? '')),
        );
    }

    /**
     * Persists a health state, upserting by event type.
     *
     * @param HealthState $state
     * @return void
     */
    public function save(HealthState $state): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->insertOnDuplicate(
            $table,
            [
                'event_type' => $state->eventType,
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
     * @param string|null $value
     * @return \DateTimeImmutable|null
     */
    private function toDateTime(?string $value): ?\DateTimeImmutable
    {
        return $value !== null ? new \DateTimeImmutable($value) : null;
    }
}
