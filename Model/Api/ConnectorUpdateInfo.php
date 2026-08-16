<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Api;

/**
 * The platform's own comparison of this install's reported connector version
 * against the latest published release, echoed back on a sync response.
 * Computed platform-side rather than by the connector itself, so "is there a
 * newer version" never depends on the very thing that might be out of date.
 */
class ConnectorUpdateInfo
{
    /**
     * @param bool $updateAvailable
     * @param string|null $latestVersion e.g. "1.2.0"
     */
    public function __construct(
        public readonly bool $updateAvailable,
        public readonly ?string $latestVersion,
    ) {
    }
}
