<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\StoreView;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * This install's live (non-admin, enabled) store views.
 *
 * StoreManagerInterface::getStores() only excludes the admin store (id 0);
 * it does NOT filter on is_active, so a store view the merchant disabled in
 * Magento would otherwise be treated as still running. This exact filter
 * originated in StoreViewSyncService's own B1 fix (C0/C1) -- a real billing-
 * scoping bug where a merchant-disabled store view was synced as "live" and
 * bumped metered Stripe billing quantity for a storefront that wasn't
 * running -- then got copied into ReportingService::liveStores(),
 * IntegrationHealthConfigValidator::liveStoreViewIds(), and
 * Block/Adminhtml/IntegrationHealth/Sources::getLiveStoreViews() under this
 * module's own "rule of three" tolerance for small duplicated logic.
 * Extracted here (C4's own architect review) once a fourth copy made the
 * drift risk on a billing-scoping invariant no longer tolerable; all four
 * original call sites, including StoreViewSyncService itself, now delegate
 * here instead of keeping their own copy.
 */
class LiveStoreViewResolver
{
    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Every currently-active store view.
     *
     * @return StoreInterface[]
     */
    public function all(): array
    {
        return array_values(array_filter(
            $this->storeManager->getStores(),
            static fn (StoreInterface $store): bool => (bool) $store->getIsActive()
        ));
    }

    /**
     * Ids of every currently-active store view.
     *
     * @return int[]
     */
    public function ids(): array
    {
        return array_values(array_map(
            static fn (StoreInterface $store): int => (int) $store->getId(),
            $this->all()
        ));
    }
}
