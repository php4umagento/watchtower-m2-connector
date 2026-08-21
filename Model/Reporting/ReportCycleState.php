<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Reporting;

/**
 * Snapshot of the singleton row in watchtower_report_cycle_state: when
 * Cron\ReportJob's real evaluate-and-submit cycle last ran. Drives
 * ReportJob's own elapsed-time guard -- see that class's docblock.
 */
class ReportCycleState
{
    /**
     * @param \DateTimeImmutable|null $lastRunAt null if the cycle has never run
     */
    public function __construct(
        public readonly ?\DateTimeImmutable $lastRunAt,
    ) {
    }
}
