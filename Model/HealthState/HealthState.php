<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\HealthState;

use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;

/**
 * Plain snapshot of one row in watchtower_health_state; a value object
 * rather than a full Magento Model/ResourceModel/Collection triad, since
 * this table has no admin grid, no EAV, and one caller.
 */
class HealthState
{
    /**
     * @param string $eventType
     * @param \DateTimeImmutable|null $lastSuccessAt
     * @param \DateTimeImmutable|null $lastFailureAt
     * @param SignalStatus|null $pendingStatus
     * @param SignalStatus|null $confirmedStatus
     * @param int $sequenceNumber
     * @param ReportReason|null $lastReportedReason null only when no evaluation has ever run
     */
    public function __construct(
        public readonly string $eventType,
        public readonly ?\DateTimeImmutable $lastSuccessAt,
        public readonly ?\DateTimeImmutable $lastFailureAt,
        public readonly ?SignalStatus $pendingStatus,
        public readonly ?SignalStatus $confirmedStatus,
        public readonly int $sequenceNumber,
        public readonly ?ReportReason $lastReportedReason = null,
    ) {
    }
}
