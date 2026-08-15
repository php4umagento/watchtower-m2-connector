<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Buffer;

use Watchtower\Connector\Model\Api\MetricReport;

/**
 * A MetricReport that is currently sitting in watchtower_report_buffer,
 * waiting to be submitted. The report's own fields (sequence_number,
 * evaluated_at, ...) are exactly what was originally evaluated and are
 * never rewritten while buffered, so the platform always sees the true
 * original evaluation instant on eventual delivery. Retry bookkeeping is
 * buffer-wide (watchtower_submission_backoff), not per row.
 */
class BufferedReport
{
    /**
     * @param int $bufferId
     * @param MetricReport $report
     */
    public function __construct(
        public readonly int $bufferId,
        public readonly MetricReport $report,
    ) {
    }
}
