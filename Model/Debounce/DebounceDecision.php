<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Debounce;

use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;

/**
 * What one debounce tick concluded: the status and reason to report now, and
 * the pending/confirmed pair to persist for next time.
 *
 * Deliberately carries no store view, category, or timestamps. The decision is
 * the part every signal shares; what to key it on and where to persist it is
 * the caller's business.
 */
class DebounceDecision
{
    /**
     * @param SignalStatus $reportStatus status to put on the wire this tick
     * @param ReportReason $reportReason heartbeat or transition
     * @param SignalStatus|null $nextPendingStatus pending status to persist
     * @param SignalStatus $nextConfirmedStatus confirmed status to persist
     */
    public function __construct(
        public readonly SignalStatus $reportStatus,
        public readonly ReportReason $reportReason,
        public readonly ?SignalStatus $nextPendingStatus,
        public readonly SignalStatus $nextConfirmedStatus,
    ) {
    }
}
