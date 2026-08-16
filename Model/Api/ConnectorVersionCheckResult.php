<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Api;

/**
 * Outcome of a GET /api/installs/connector-version call, already compared
 * locally against this install's own installed version. Per PRD FR24-FR27:
 * the platform only ever states minimum_version/latest_version -- whether
 * THIS install is below/behind is this module's own judgment, never
 * something the platform decides on its behalf.
 */
class ConnectorVersionCheckResult
{
    /**
     * @param bool $succeeded whether the platform could be reached and answered
     * @param string|null $installedVersion this module's own version, null if not Composer-managed
     * @param string|null $minimumVersion platform-configured floor; only meaningful when $succeeded
     * @param string|null $latestVersion platform-configured latest; only meaningful when $succeeded
     * @param bool $belowMinimum true only when both $installedVersion and $minimumVersion are known
     *     and the former is strictly less than the latter -- never true on an inconclusive comparison
     * @param bool $updateAvailable same conservatism as $belowMinimum, against $latestVersion instead
     * @param string|null $errorMessage set only when $succeeded is false
     */
    public function __construct(
        public readonly bool $succeeded,
        public readonly ?string $installedVersion = null,
        public readonly ?string $minimumVersion = null,
        public readonly ?string $latestVersion = null,
        public readonly bool $belowMinimum = false,
        public readonly bool $updateAvailable = false,
        public readonly ?string $errorMessage = null,
    ) {
    }
}
