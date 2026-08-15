<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Records each dispatch of the watchtower_integration_health convention event
 * locally, so ConventionEventReader can answer "when did this integration last
 * report ok/failed" durably.
 *
 * Event data contract: 'status' ('ok'|'failed'), 'integration' (the label a
 * store's source-picker configuration is matched against), and an OPTIONAL
 * 'store_id' falling back to the current store context. A dispatch with no
 * resolvable store view is dropped, since there is nothing to attribute it to.
 */
class ConventionEventObserver implements ObserverInterface
{
    /**
     * Oversized input is rejected here rather than left to a MySQL "Data too long"
     * fatal: Magento's event manager does not catch observer exceptions, so a DB
     * error would break the very integration this signal exists to monitor.
     */
    private const VALID_STATUSES = ['ok', 'failed'];
    private const MAX_INTEGRATION_LABEL_LENGTH = 64;

    /**
     * @param IntegrationHealthEventRepository $repository
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly IntegrationHealthEventRepository $repository,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Records one dispatched watchtower_integration_health event.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $status = (string) $event->getData('status');
        $integrationLabel = (string) $event->getData('integration');

        if (!in_array($status, self::VALID_STATUSES, true)) {
            return;
        }

        if ($integrationLabel === '' || strlen($integrationLabel) > self::MAX_INTEGRATION_LABEL_LENGTH) {
            return;
        }

        $storeViewId = $this->resolveStoreViewId($event->getData('store_id'));

        if ($storeViewId === null) {
            return;
        }

        $this->repository->record(
            $storeViewId,
            $integrationLabel,
            $status,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
    }

    /**
     * The store view id to attribute this dispatch to, or null when there is none.
     *
     * An explicit store_id of 0 (admin scope) is dropped rather than falling back
     * to the ambient context, which would override what the dispatcher stated.
     *
     * @param mixed $explicitStoreId
     * @return int|null
     */
    private function resolveStoreViewId(mixed $explicitStoreId): ?int
    {
        if ($explicitStoreId !== null) {
            $storeId = (int) $explicitStoreId;

            return $storeId !== Store::DEFAULT_STORE_ID ? $storeId : null;
        }

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (NoSuchEntityException) {
            return null;
        }

        return $storeId !== Store::DEFAULT_STORE_ID ? $storeId : null;
    }
}
