<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

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
     */
    public function __construct(
        public readonly int $storeViewId,
        public readonly ?\DateTimeImmutable $lastSuccessAt,
        public readonly ?\DateTimeImmutable $lastFailureAt,
        public readonly ?SignalStatus $pendingStatus,
        public readonly ?SignalStatus $confirmedStatus,
        public readonly int $sequenceNumber,
    ) {
    }

    /**
     * Whether this is a fresh state with no prior confirmed status.
     *
     * @return bool
     */
    public function isFirstEvaluation(): bool
    {
        return $this->confirmedStatus === null;
    }
}
