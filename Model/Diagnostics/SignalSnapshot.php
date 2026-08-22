<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Diagnostics;

use Watchtower\Connector\Model\Api\SignalStatus;

/**
 * One tracked signal's last-known status and sequence number, for diagnostics.
 */
class SignalSnapshot
{
    /**
     * @param string $category cron_health, basket_quote, checkout, customer_account, or integration_health
     * @param SignalStatus|null $status null means no evaluation has ever run for this signal
     * @param int $sequenceNumber
     * @param float|null $estimatedDetectionLatencyHours set only for a rate-based
     *     signal currently in Low-Volume Signal Mode with enough history to estimate one --
     *     null for cron_health/integration_health (not rate-based) and for a signal that
     *     isn't in Low-Volume Signal Mode or is still INSUFFICIENT_DATA
     */
    public function __construct(
        public readonly string $category,
        public readonly ?SignalStatus $status,
        public readonly int $sequenceNumber,
        public readonly ?float $estimatedDetectionLatencyHours = null,
    ) {
    }
}
