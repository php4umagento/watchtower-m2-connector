<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Environment;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Environment\ConnectorVersionReader;

/**
 * Composer\InstalledVersions is a static API over the consuming project's own
 * real vendor/composer/installed.php, not something this module's own DI can
 * substitute a fake for -- so unlike every other Reader in this module, this
 * one is exercised against real runtime package metadata rather than a
 * mocked collaborator. Asserts shape (a real, non-empty version string),
 * not an exact value, since the real value legitimately changes on every
 * release and a hardcoded assertion here would break on every version bump
 * for no real reason.
 */
class ConnectorVersionReaderTest extends TestCase
{
    public function testReturnsARealNonEmptyVersionStringWhenComposerManaged(): void
    {
        $reader = new ConnectorVersionReader();

        $version = $reader->version();

        self::assertNotNull($version, 'This test only runs from within a real Composer install of this package.');
        self::assertNotSame('', $version);
    }

    /**
     * A leading "v" makes version_compare() rank the whole string below a
     * normal release, which would judge every install "below minimum".
     */
    public function testStripsTheLeadingVSoItNeverBreaksVersionCompareAgainstThePlatformsBareFormat(): void
    {
        $version = (new ConnectorVersionReader())->version();

        self::assertNotNull($version);
        self::assertDoesNotMatchRegularExpression('/^v/i', $version);
    }
}
