<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\RateSignal;

use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;

/**
 * Plain snapshot of one row in watchtower_dispersion_state, keyed by
 * (store_view_id, category) rather than cron_health's single event_type,
 * since Check A is evaluated per store view.
 */
class DispersionState
{
    /**
     * @param int $storeViewId
     * @param string $category
     * @param SignalStatus|null $pendingStatus
     * @param SignalStatus|null $confirmedStatus
     * @param int $sequenceNumber
     * @param ReportReason|null $lastReportedReason null only when no evaluation has ever run
     * @param string[] $ensembleDrivingChecks which named checks (dispersion, seasonal, trend) drove
     *     the most recent raw classification; empty when it came from the inter-arrival
     *     (low-volume) path instead of the ensemble, or when no evaluation has ever run
     */
    public function __construct(
        public readonly int $storeViewId,
        public readonly string $category,
        public readonly ?SignalStatus $pendingStatus,
        public readonly ?SignalStatus $confirmedStatus,
        public readonly int $sequenceNumber,
        public readonly ?ReportReason $lastReportedReason = null,
        public readonly array $ensembleDrivingChecks = [],
    ) {
    }
}
