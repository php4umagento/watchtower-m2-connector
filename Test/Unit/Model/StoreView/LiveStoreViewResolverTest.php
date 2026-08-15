<?php

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\StoreView;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\StoreView\LiveStoreViewResolver;

/**
 * Dedicated coverage for the shared is_active filter, rather than
 * relying on whatever incidentally exercises it via its three callers
 * (ReportingService, IntegrationHealthConfigValidator,
 * Block/Adminhtml/IntegrationHealth/Sources).
 */
class LiveStoreViewResolverTest extends TestCase
{
    public function testAllReturnsOnlyActiveStoreViews(): void
    {
        $active = $this->store(1, true);
        $inactive = $this->store(2, false);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$active, $inactive]);

        self::assertSame([$active], (new LiveStoreViewResolver($storeManager))->all());
    }

    public function testIdsReturnsOnlyActiveStoreViewIds(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([
            $this->store(1, true),
            $this->store(2, false),
            $this->store(3, true),
        ]);

        self::assertSame([1, 3], (new LiveStoreViewResolver($storeManager))->ids());
    }

    public function testNoLiveStoreViewsProducesEmptyArraysNotErrors(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->store(1, false)]);

        $resolver = new LiveStoreViewResolver($storeManager);

        self::assertSame([], $resolver->all());
        self::assertSame([], $resolver->ids());
    }

    private function store(int $id, bool $isActive): StoreInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getIsActive')->willReturn($isActive);

        return $store;
    }
}
