<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Diagnostics;

/**
 * One row of watchtower_submission_outcome_log -- a single past
 * POST /api/installs/metrics attempt. $rejectionReasons breaks the rejected
 * count down by reason, so a benign already-delivered dedup is
 * distinguishable from a genuinely unrecognized store view.
 */
class SubmissionOutcome
{
    /**
     * @param bool $succeeded
     * @param int $acceptedCount
     * @param int $rejectedCount
     * @param array<string,int> $rejectionReasons reason string => count, empty when rejectedCount is 0
     * @param string|null $errorMessage
     * @param \DateTimeImmutable $occurredAt
     */
    public function __construct(
        public readonly bool $succeeded,
        public readonly int $acceptedCount,
        public readonly int $rejectedCount,
        public readonly array $rejectionReasons,
        public readonly ?string $errorMessage,
        public readonly \DateTimeImmutable $occurredAt,
    ) {
    }
}
