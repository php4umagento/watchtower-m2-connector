<?php

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Api\StoreViewSyncService;
use Watchtower\Connector\Model\Api\SyncResult;
use Watchtower\Connector\Model\Config;
use Watchtower\Connector\Model\Config\Backend\EnabledSyncTrigger;

/**
 * Locks the "sync on enable" behavior: saving the admin config with
 * enabled=1 for the first time must fire an immediate sync, but every
 * other section save (including one that merely re-saves "enabled" with
 * its existing value alongside an unrelated field) must not. Magento
 * calls this backend model's afterSave() on EVERY section save regardless
 * of which field actually changed (see Magento\Config\Model\Config::
 * _processGroup(), which builds and saves a backend model for every
 * submitted field), so isValueChanged() is the only thing standing
 * between "fires on enable" and "fires on every save".
 */
class EnabledSyncTriggerTest extends TestCase
{
    public function testFiresSyncExactlyOnceWhenTransitioningFromUnsetToEnabled(): void
    {
        $storeViewSyncService = $this->createMock(StoreViewSyncService::class);
        $storeViewSyncService->expects(self::once())
            ->method('sync')
            ->with('https://watchtower.test', 'a-real-api-key')
            ->willReturn(new SyncResult(succeeded: true, created: ['default']));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $model = $this->buildModel(oldValue: null, storeViewSyncService: $storeViewSyncService, logger: $logger);
        $model->setPath('watchtower/general/enabled')->setValue('1');

        $model->afterSave();
    }

    public function testFiresSyncWhenTransitioningFromZeroToOne(): void
    {
        $storeViewSyncService = $this->createMock(StoreViewSyncService::class);
        $storeViewSyncService->expects(self::once())->method('sync')->willReturn(new SyncResult(succeeded: true));

        $model = $this->buildModel(oldValue: '0', storeViewSyncService: $storeViewSyncService);
        $model->setPath('watchtower/general/enabled')->setValue('1');

        $model->afterSave();
    }

    /**
     * Saving another field in the section alone still re-saves the
     * "enabled" row with its unchanged value, so the sync trigger must
     * not fire a second time just because a save happened.
     */
    public function testDoesNotFireWhenTheValueIsUnchanged(): void
    {
        $storeViewSyncService = $this->createMock(StoreViewSyncService::class);
        $storeViewSyncService->expects(self::never())->method('sync');

        $model = $this->buildModel(oldValue: '1', storeViewSyncService: $storeViewSyncService);
        $model->setPath('watchtower/general/enabled')->setValue('1');

        $model->afterSave();
    }

    public function testDoesNotFireWhenTransitioningToDisabled(): void
    {
        $storeViewSyncService = $this->createMock(StoreViewSyncService::class);
        $storeViewSyncService->expects(self::never())->method('sync');

        $model = $this->buildModel(oldValue: '1', storeViewSyncService: $storeViewSyncService);
        $model->setPath('watchtower/general/enabled')->setValue('0');

        $model->afterSave();
    }

    public function testDoesNotAttemptSyncWhenNotConfigured(): void
    {
        $storeViewSyncService = $this->createMock(StoreViewSyncService::class);
        $storeViewSyncService->expects(self::never())->method('sync');

        $model = $this->buildModel(oldValue: null, storeViewSyncService: $storeViewSyncService, isConfigured: false);
        $model->setPath('watchtower/general/enabled')->setValue('1');

        $model->afterSave();
    }

    public function testLogsAWarningWhenTheOnEnableSyncFails(): void
    {
        $storeViewSyncService = $this->createStub(StoreViewSyncService::class);
        $storeViewSyncService->method('sync')->willReturn(
            new SyncResult(succeeded: false, errorMessage: 'Connection refused')
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Watchtower on-enable sync failed.',
            ['error' => 'Connection refused']
        );

        $model = $this->buildModel(oldValue: null, storeViewSyncService: $storeViewSyncService, logger: $logger);
        $model->setPath('watchtower/general/enabled')->setValue('1');

        $model->afterSave();
    }

    private function buildModel(
        ?string $oldValue,
        StoreViewSyncService $storeViewSyncService,
        ?LoggerInterface $logger = null,
        bool $isConfigured = true
    ): EnabledSyncTrigger {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($oldValue);

        $eventManager = $this->createStub(ManagerInterface::class);
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($eventManager);

        $watchtowerConfig = $this->createStub(Config::class);
        $watchtowerConfig->method('isConfigured')->willReturn($isConfigured);
        $watchtowerConfig->method('baseUrl')->willReturn('https://watchtower.test');
        $watchtowerConfig->method('apiKey')->willReturn('a-real-api-key');

        $objectManager = new ObjectManagerHelper($this);

        return $objectManager->getObject(EnabledSyncTrigger::class, [
            'context' => $context,
            'registry' => $this->createStub(Registry::class),
            'config' => $scopeConfig,
            'cacheTypeList' => $this->createStub(TypeListInterface::class),
            'watchtowerConfig' => $watchtowerConfig,
            'storeViewSyncService' => $storeViewSyncService,
            'logger' => $logger ?? $this->createStub(LoggerInterface::class),
            // Explicit nulls: without these, ObjectManagerHelper
            // auto-mocks AbstractResource via a deprecated self::any()
            // matcher (see _getResourceModelMock()), which PHPUnit 12
            // flags as a notice on every call.
            'resource' => null,
            'resourceCollection' => null,
        ]);
    }
}
