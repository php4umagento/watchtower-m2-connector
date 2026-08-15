<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Api;

/**
 * One report ready to submit to POST /api/installs/metrics. Generic across
 * every tracked signal; $storeViewCode is null for install-scoped signals such
 * as cron_health, and populated for store-view-scoped ones.
 */
class MetricReport
{
    /**
     * @param string|null $storeViewCode
     * @param string $eventType
     * @param SignalStatus $status
     * @param int $sequenceNumber
     * @param \DateTimeImmutable $evaluatedAt
     * @param ReportReason $reason
     * @param string $rulesetVersion
     */
    public function __construct(
        public readonly ?string $storeViewCode,
        public readonly string $eventType,
        public readonly SignalStatus $status,
        public readonly int $sequenceNumber,
        public readonly \DateTimeImmutable $evaluatedAt,
        public readonly ReportReason $reason,
        public readonly string $rulesetVersion,
    ) {
    }
}
