<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;

/**
 * Plain snapshot of one row in watchtower_integration_health_state, keyed by
 * store_view_id (integration_health is per store view, unlike cron_health's
 * single install-scoped row). The lastSuccessAt/lastFailureAt columns exist
 * because the underlying source tables are not durable history -- see
 * IntegrationHealth\Evaluator.
 */
class IntegrationHealthState
{
    /**
     * @param int $storeViewId
     * @param \DateTimeImmutable|null $lastSuccessAt
     * @param \DateTimeImmutable|null $lastFailureAt
     * @param SignalStatus|null $pendingStatus
     * @param SignalStatus|null $confirmedStatus
     * @param int $sequenceNumber
     * @param ReportReason|null $lastReportedReason null only when no evaluation has ever run
     * @param string|null $sourceType source this state describes; null on a pre-fingerprint row
     * @param string|null $sourceIdentifier source this state describes; null on a pre-fingerprint row
     * @param \DateTimeImmutable|null $observingSince when the current source started being observed
     */
    public function __construct(
        public readonly int $storeViewId,
        public readonly ?\DateTimeImmutable $lastSuccessAt,
        public readonly ?\DateTimeImmutable $lastFailureAt,
        public readonly ?SignalStatus $pendingStatus,
        public readonly ?SignalStatus $confirmedStatus,
        public readonly int $sequenceNumber,
        public readonly ?ReportReason $lastReportedReason = null,
        public readonly ?string $sourceType = null,
        public readonly ?string $sourceIdentifier = null,
        public readonly ?\DateTimeImmutable $observingSince = null,
    ) {
    }

    /**
     * Whether the accumulated evidence in this state still describes the given source.
     *
     * @param string $sourceType
     * @param string $sourceIdentifier
     * @return bool
     */
    public function describesSource(string $sourceType, string $sourceIdentifier): bool
    {
        return $this->sourceType === $sourceType && $this->sourceIdentifier === $sourceIdentifier;
    }
}
