<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\StoreView;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\StoreView\LocalDomainDetector;

class LocalDomainDetectorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function urlProvider(): array
    {
        return [
            'bare localhost' => ['http://localhost/', true],
            'a subdomain of localhost' => ['http://admin.localhost/', true],
            'Warden/Valet/Herd .test' => ['https://mystore.test/', true],
            'DDEV' => ['https://mystore.ddev.site/', true],
            'Lando' => ['https://mystore.lndo.site/', true],
            'Docksal bare' => ['https://mystore.docksal/', true],
            'Docksal .site' => ['https://mystore.docksal.site/', true],
            'Vagrant' => ['https://mystore.vagrant/', true],
            'docker.localhost' => ['https://mystore.docker.localhost/', true],
            'RFC 2606 .example' => ['https://mystore.example/', true],
            'RFC 2606 .invalid' => ['https://mystore.invalid/', true],
            'mDNS/Bonjour .local' => ['https://mystore.local/', true],
            'loopback IP' => ['http://127.0.0.1/', true],
            'private IP (RFC 1918)' => ['http://192.168.1.50/', true],
            'a real production domain' => ['https://mystore.example.com/', false],
            'a domain merely containing the word test' => ['https://teststore.com/', false],
            'a public IP' => ['http://8.8.8.8/', false],
            'case-insensitive match' => ['https://MyStore.DDEV.Site/', true],
        ];
    }

    #[DataProvider('urlProvider')]
    public function testLooksLocal(string $url, bool $expected): void
    {
        self::assertSame($expected, (new LocalDomainDetector())->looksLocal($url));
    }

    public function testAMalformedUrlIsNotFlaggedAsLocal(): void
    {
        self::assertFalse((new LocalDomainDetector())->looksLocal('not-a-url'));
    }
}
