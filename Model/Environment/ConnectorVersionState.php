<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Environment;

/**
 * Plain snapshot of the singleton row in watchtower_connector_version_state:
 * the last SUCCESSFUL GET /api/installs/connector-version check's outcome.
 * Only a successful check ever overwrites this (see
 * ConnectorVersionStateRepository's own docblock for why a failed check must
 * not) -- so $belowMinimum here is exactly the flag ReportingService and
 * StoreViewSyncService gate submission on, and the flag the admin notice and
 * watchtower:status read, without any of them needing a live call of their
 * own.
 */
class ConnectorVersionState
{
    /**
     * @param string|null $installedVersion
     * @param string|null $minimumVersion
     * @param string|null $latestVersion
     * @param bool $belowMinimum
     * @param bool $updateAvailable
     * @param \DateTimeImmutable|null $checkedAt null if a check has never succeeded
     */
    public function __construct(
        public readonly ?string $installedVersion,
        public readonly ?string $minimumVersion,
        public readonly ?string $latestVersion,
        public readonly bool $belowMinimum,
        public readonly bool $updateAvailable,
        public readonly ?\DateTimeImmutable $checkedAt,
    ) {
    }
}
