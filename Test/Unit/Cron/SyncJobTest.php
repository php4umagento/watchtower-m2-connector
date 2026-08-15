<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Watchtower\Connector\Cron\SyncJob;
use Watchtower\Connector\Model\Api\StoreViewSyncService;
use Watchtower\Connector\Model\Api\SyncResult;
use Watchtower\Connector\Model\Config;

/**
 * Scheduled counterpart to Console\Command\SyncCommand -- same
 * isConfigured()/isEnabled() gating,
 * same StoreViewSyncService, so this only needs to lock the gating and
 * the failure/rejection log paths, not re-prove sync behavior already
 * covered by StoreViewSyncServiceTest.
 */
class SyncJobTest extends TestCase
{
    public function testDoesNothingWhenNotConfigured(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn(false);

        $storeViewSyncService = $this->createMock(StoreViewSyncService::class);
        $storeViewSyncService->expects(self::never())->method('sync');

        (new SyncJob($config, $storeViewSyncService, $this->createStub(LoggerInterface::class)))->execute();
    }

    public function testDoesNothingWhenDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn(true);
        $config->method('isEnabled')->willReturn(false);

        $storeViewSyncService = $this->createMock(StoreViewSyncService::class);
        $storeViewSyncService->expects(self::never())->method('sync');

        (new SyncJob($config, $storeViewSyncService, $this->createStub(LoggerInterface::class)))->execute();
    }

    public function testCallsTheSyncServiceWhenEnabled(): void
    {
        $config = $this->configuredAndEnabled();

        $storeViewSyncService = $this->createMock(StoreViewSyncService::class);
        $storeViewSyncService->expects(self::once())
            ->method('sync')
            ->with('https://watchtower.test', 'a-real-api-key')
            ->willReturn(new SyncResult(succeeded: true, created: ['default']));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        (new SyncJob($config, $storeViewSyncService, $logger))->execute();
    }

    public function testLogsAWarningOnFailureWithoutThrowing(): void
    {
        $config = $this->configuredAndEnabled();

        $storeViewSyncService = $this->createStub(StoreViewSyncService::class);
        $storeViewSyncService->method('sync')->willReturn(
            new SyncResult(succeeded: false, errorMessage: 'Connection refused')
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Watchtower scheduled store-view sync failed.',
            ['error' => 'Connection refused']
        );

        (new SyncJob($config, $storeViewSyncService, $logger))->execute();
    }

    public function testLogsAWarningWhenStoreViewsAreRejected(): void
    {
        $config = $this->configuredAndEnabled();

        $rejected = [['code' => 'default', 'reason' => 'store view allowance exceeded for this install']];
        $storeViewSyncService = $this->createStub(StoreViewSyncService::class);
        $storeViewSyncService->method('sync')->willReturn(new SyncResult(succeeded: true, rejected: $rejected));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Watchtower scheduled store-view sync had rejected store views.',
            ['rejected' => $rejected]
        );

        (new SyncJob($config, $storeViewSyncService, $logger))->execute();
    }

    private function configuredAndEnabled(): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn(true);
        $config->method('isEnabled')->willReturn(true);
        $config->method('baseUrl')->willReturn('https://watchtower.test');
        $config->method('apiKey')->willReturn('a-real-api-key');

        return $config;
    }
}
