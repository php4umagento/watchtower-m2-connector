<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\IntegrationHealth;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Config;
use Watchtower\Connector\Model\IntegrationHealth\ConventionEventObserver;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthEventRepository;

/**
 * Event and Observer are both plain \Magento\Framework\DataObject
 * subclasses -- real instances are used directly rather than mocked, the
 * same way this module's own EventCounter\CustomerSessionObserverTest
 * would (no dedicated test exists for that class either, so this
 * establishes the pattern for an events.xml-wired observer in this module).
 */
class ConventionEventObserverTest extends TestCase
{
    public function testAnExplicitStoreIdOnTheEventIsUsedWithoutConsultingTheStoreManager(): void
    {
        $repository = $this->createMock(IntegrationHealthEventRepository::class);
        $repository->expects(self::once())
            ->method('record')
            ->with(5, 'erp_sync', 'ok', self::isInstanceOf(\DateTimeImmutable::class));

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects(self::never())->method('getStore');

        (new ConventionEventObserver(
            $repository,
            $storeManager,
            $this->enabledConfig(),
            $this->createStub(LoggerInterface::class)
        ))->execute(
            $this->observerWith(['status' => 'ok', 'integration' => 'erp_sync', 'store_id' => 5])
        );
    }

    public function testNoExplicitStoreIdFallsBackToTheCurrentStoreManagerContext(): void
    {
        $repository = $this->createMock(IntegrationHealthEventRepository::class);
        $repository->expects(self::once())
            ->method('record')
            ->with(7, 'erp_sync', 'failed', self::isInstanceOf(\DateTimeImmutable::class));

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(7);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        (new ConventionEventObserver(
            $repository,
            $storeManager,
            $this->enabledConfig(),
            $this->createStub(LoggerInterface::class)
        ))->execute(
            $this->observerWith(['status' => 'failed', 'integration' => 'erp_sync'])
        );
    }

    public function testAnUnresolvableStoreContextIsDroppedRatherThanRecorded(): void
    {
        $repository = $this->createMock(IntegrationHealthEventRepository::class);
        $repository->expects(self::never())->method('record');

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new NoSuchEntityException());

        (new ConventionEventObserver(
            $repository,
            $storeManager,
            $this->enabledConfig(),
            $this->createStub(LoggerInterface::class)
        ))->execute(
            $this->observerWith(['status' => 'ok', 'integration' => 'erp_sync'])
        );
    }

    public function testTheAdminDefaultScopeIsDroppedRatherThanRecorded(): void
    {
        $repository = $this->createMock(IntegrationHealthEventRepository::class);
        $repository->expects(self::never())->method('record');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects(self::never())->method('getStore');

        (new ConventionEventObserver(
            $repository,
            $storeManager,
            $this->enabledConfig(),
            $this->createStub(LoggerInterface::class)
        ))->execute(
            $this->observerWith(['status' => 'ok', 'integration' => 'erp_sync', 'store_id' => Store::DEFAULT_STORE_ID])
        );
    }

    public function testAMissingStatusOrIntegrationLabelIsDroppedRatherThanRecorded(): void
    {
        $repository = $this->createMock(IntegrationHealthEventRepository::class);
        $repository->expects(self::never())->method('record');

        $storeManager = $this->createStub(StoreManagerInterface::class);

        (new ConventionEventObserver(
            $repository,
            $storeManager,
            $this->enabledConfig(),
            $this->createStub(LoggerInterface::class)
        ))->execute(
            $this->observerWith(['status' => 'ok'])
        );
    }

    /**
     * watchtower_integration_health_event.status is a varchar(6) column and
     * the read side (IntegrationHealthEventRepository::latestObservation())
     * only ever matches the literal 'ok'/'failed' values -- a dispatch with
     * any other status must be dropped, not passed through to a repository
     * insert that would otherwise fatal with a MySQL "Data too long" error
     * inside the merchant's own dispatching module.
     */
    public function testAnUnrecognizedStatusValueIsDroppedRatherThanRecorded(): void
    {
        $repository = $this->createMock(IntegrationHealthEventRepository::class);
        $repository->expects(self::never())->method('record');

        $storeManager = $this->createStub(StoreManagerInterface::class);

        (new ConventionEventObserver(
            $repository,
            $storeManager,
            $this->enabledConfig(),
            $this->createStub(LoggerInterface::class)
        ))->execute(
            $this->observerWith(['status' => 'success', 'integration' => 'erp_sync'])
        );
    }

    /**
     * integration_label is a varchar(64) column -- an oversized label must
     * be dropped for the same reason as an unrecognized status: it can
     * never be recorded without a fatal, and it could never match a real
     * admin-configured source_identifier anyway (that's also capped at 64
     * characters for convention_event, see IntegrationHealthConfigValidator).
     */
    public function testAnOversizedIntegrationLabelIsDroppedRatherThanRecorded(): void
    {
        $repository = $this->createMock(IntegrationHealthEventRepository::class);
        $repository->expects(self::never())->method('record');

        $storeManager = $this->createStub(StoreManagerInterface::class);

        (new ConventionEventObserver(
            $repository,
            $storeManager,
            $this->enabledConfig(),
            $this->createStub(LoggerInterface::class)
        ))->execute(
            $this->observerWith(['status' => 'ok', 'integration' => str_repeat('a', 65)])
        );
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function observerWith(array $eventData): Observer
    {
        return new Observer(['event' => new Event($eventData)]);
    }

    /**
     * Every observer this module ships returns early when the merchant has
     * switched it off; these cases all exercise the enabled path.
     */
    private function enabledConfig(): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);

        return $config;
    }
}
