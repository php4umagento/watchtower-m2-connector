<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\EventCounter;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\EventCounter\CustomerSessionObserver;
use Watchtower\Connector\Model\EventCounter\EventCounterRepository;

/**
 * A customer_login (and, independently, customer_logout) event with a
 * resolvable store increments that store's real counter; an event with no
 * resolvable storefront store (admin/default scope, or the store manager
 * throwing outright) increments only the local drop counter and never the
 * real one -- an event is never attributed to a fallback store.
 */
class CustomerSessionObserverTest extends TestCase
{
    public function testCustomerLoginEventWithAResolvableStoreIncrementsThatStoresCounter(): void
    {
        $repository = $this->createMock(EventCounterRepository::class);
        $repository->expects(self::once())
            ->method('increment')
            ->with(1, 'customer_login', self::isInstanceOf(\DateTimeImmutable::class));
        $repository->expects(self::never())->method('incrementDropped');

        $observer = new CustomerSessionObserver($repository, $this->storeManagerResolvingTo(1));

        $observer->execute($this->observerFor('customer_login'));
    }

    public function testCustomerLogoutEventIncrementsIndependentlyOfLogin(): void
    {
        $repository = $this->createMock(EventCounterRepository::class);
        $repository->expects(self::once())
            ->method('increment')
            ->with(7, 'customer_logout', self::isInstanceOf(\DateTimeImmutable::class));
        $repository->expects(self::never())->method('incrementDropped');

        $observer = new CustomerSessionObserver($repository, $this->storeManagerResolvingTo(7));

        $observer->execute($this->observerFor('customer_logout'));
    }

    public function testAdminOrDefaultScopeIncrementsTheDropCounterAndNotTheRealCounter(): void
    {
        $repository = $this->createMock(EventCounterRepository::class);
        $repository->expects(self::never())->method('increment');
        $repository->expects(self::once())
            ->method('incrementDropped')
            ->with('customer_login', self::isInstanceOf(\DateTimeImmutable::class));

        $observer = new CustomerSessionObserver($repository, $this->storeManagerResolvingTo(Store::DEFAULT_STORE_ID));

        $observer->execute($this->observerFor('customer_login'));
    }

    public function testAnUnresolvableStoreManagerAlsoDropsTheEventRatherThanAttributingItToAFallback(): void
    {
        $repository = $this->createMock(EventCounterRepository::class);
        $repository->expects(self::never())->method('increment');
        $repository->expects(self::once())
            ->method('incrementDropped')
            ->with('customer_logout', self::isInstanceOf(\DateTimeImmutable::class));

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new NoSuchEntityException(__('No such store.')));

        $observer = new CustomerSessionObserver($repository, $storeManager);

        $observer->execute($this->observerFor('customer_logout'));
    }

    private function storeManagerResolvingTo(int $storeId): StoreManagerInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn($storeId);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $storeManager;
    }

    private function observerFor(string $eventName): Observer
    {
        return new Observer(['event' => new Event(['name' => $eventName])]);
    }
}
