<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Environment;

use Magento\Framework\App\ProductMetadataInterface;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Environment\MagentoVersionReader;

class MagentoVersionReaderTest extends TestCase
{
    public function testVersionAndEditionPassThroughFromProductMetadata(): void
    {
        $productMetadata = $this->createStub(ProductMetadataInterface::class);
        $productMetadata->method('getVersion')->willReturn('2.4.9');
        $productMetadata->method('getEdition')->willReturn('Community');

        $reader = new MagentoVersionReader($productMetadata);

        self::assertSame('2.4.9', $reader->version());
        self::assertSame('Community', $reader->edition());
    }
}
