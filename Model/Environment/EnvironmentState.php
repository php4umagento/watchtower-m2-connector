<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Environment;

use Watchtower\Connector\Model\Api\ConnectorUpdateInfo;
use Watchtower\Connector\Model\Api\MagentoEolInfo;

/**
 * Plain snapshot of the singleton row in watchtower_environment_state: the
 * environment facts reported on the last successful sync, plus the
 * platform's own EOL/update determination echoed back with it. Read by
 * watchtower:status and the Diagnostics admin page so both can show this
 * without a live call to either the platform or magento.watch on every page
 * load -- only a sync ever refreshes it.
 */
class EnvironmentState
{
    /**
     * @param string|null $magentoVersion
     * @param string|null $magentoEdition
     * @param string|null $connectorVersion
     * @param MagentoEolInfo|null $magentoEol
     * @param ConnectorUpdateInfo|null $connectorUpdate
     * @param \DateTimeImmutable|null $syncedAt
     */
    public function __construct(
        public readonly ?string $magentoVersion,
        public readonly ?string $magentoEdition,
        public readonly ?string $connectorVersion,
        public readonly ?MagentoEolInfo $magentoEol,
        public readonly ?ConnectorUpdateInfo $connectorUpdate,
        public readonly ?\DateTimeImmutable $syncedAt,
    ) {
    }
}
