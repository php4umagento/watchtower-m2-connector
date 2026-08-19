<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Environment;

use Composer\InstalledVersions;

/**
 * This module's own installed package version, as Composer itself resolved
 * it -- not a hand-maintained constant, which would just be a second source
 * of truth to keep in sync with the git tag Composer already resolves from
 * (see composer.json's own "Versioning" note in the module's CHANGELOG).
 */
class ConnectorVersionReader
{
    private const PACKAGE_NAME = 'php4u/module-watchtower-m2-connector';

    /**
     * This module's own version, e.g. "1.1.0", or null if this isn't a real
     * Composer-managed install (a plain directory copy under app/code with
     * no vendor/composer metadata) -- callers must treat that as "unknown",
     * never as "up to date" or "outdated".
     *
     * @return string|null
     */
    public function version(): ?string
    {
        if (!InstalledVersions::isInstalled(self::PACKAGE_NAME)) {
            return null;
        }

        return InstalledVersions::getPrettyVersion(self::PACKAGE_NAME);
    }
}
