<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\EventCounter;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Increments watchtower_event_counter for the customer_login and
 * customer_logout event-bus events (etc/events.xml). Unlike registrations,
 * logins and logouts have no table that can be counted retroactively
 * (customer_log holds one row per customer, overwritten on every login, with
 * no store_id), so they are counted as they happen.
 *
 * One instance handles both events, bound twice in etc/events.xml;
 * $observer->getEvent()->getName() is trusted only because that file
 * constrains it to exactly these two event names.
 *
 * A store view id of Store::DEFAULT_STORE_ID (the admin/default scope, not a
 * real storefront) -- or a store that cannot be resolved at all -- is
 * dropped from the real counter and only bumps the local, never-transmitted
 * diagnostic in watchtower_event_drop_counter, never attributed to a
 * fallback store.
 */
class CustomerSessionObserver implements ObserverInterface
{
    /**
     * @param EventCounterRepository $eventCounterRepository
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly EventCounterRepository $eventCounterRepository,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Increments the appropriate counter for a dispatched customer_login or customer_logout event.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $eventName = (string) $observer->getEvent()->getName();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $storeViewId = $this->resolveStoreViewId();

        if ($storeViewId === null) {
            $this->eventCounterRepository->incrementDropped($eventName, $now);

            return;
        }

        $this->eventCounterRepository->increment($storeViewId, $eventName, $now);
    }

    /**
     * The current request's store view id, or null when no real storefront
     * store view can be resolved (the admin/default scope, or the store
     * manager throwing outright).
     *
     * @return int|null
     */
    private function resolveStoreViewId(): ?int
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (NoSuchEntityException) {
            return null;
        }

        return $storeId !== Store::DEFAULT_STORE_ID ? $storeId : null;
    }
}
