<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Diagnostics;

use Watchtower\Connector\Model\Environment\EnvironmentState;

/**
 * A full diagnostics reading, assembled by DiagnosticsSnapshotProvider and
 * rendered by both the watchtower:status command and the admin diagnostics page.
 */
class DiagnosticsSnapshot
{
    /**
     * @param bool $reachable
     * @param string|null $unreachableError set only when reachable=false
     * @param bool|null $keyValid null when unreachable
     * @param bool|null $organizationPaused null when unreachable
     * @param \DateTimeImmutable|null $lastSuccessfulSubmissionAt null if never
     * @param int $bufferedReportCount
     * @param int $droppedEventCountLast24Hours
     * @param SignalSnapshot $cronHealth
     * @param StoreViewSnapshot[] $storeViews
     * @param SubmissionOutcome[] $recentSubmissionOutcomes newest first
     * @param EnvironmentState $environment last-known Magento/connector version and EOL/update
     *     status, from the last successful sync -- never re-fetched just to render this snapshot
     */
    public function __construct(
        public readonly bool $reachable,
        public readonly ?string $unreachableError,
        public readonly ?bool $keyValid,
        public readonly ?bool $organizationPaused,
        public readonly ?\DateTimeImmutable $lastSuccessfulSubmissionAt,
        public readonly int $bufferedReportCount,
        public readonly int $droppedEventCountLast24Hours,
        public readonly SignalSnapshot $cronHealth,
        public readonly array $storeViews,
        public readonly array $recentSubmissionOutcomes,
        public readonly EnvironmentState $environment,
    ) {
    }
}
