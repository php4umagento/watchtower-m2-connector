<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Api;

use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Organization\OrganizationStateRepository;
use Watchtower\Connector\Model\StoreView\LiveStoreViewResolver;

/**
 * Reports this install's live store views to the platform. Identity and
 * presence only, no metrics, so it needs nothing beyond Magento's own store
 * hierarchy.
 */
class StoreViewSyncService
{
    private const PATH = '/api/installs/sync';

    /**
     * @param LiveStoreViewResolver $liveStoreViewResolver
     * @param Client $client
     * @param OrganizationStateRepository $organizationStateRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly LiveStoreViewResolver $liveStoreViewResolver,
        private readonly Client $client,
        private readonly OrganizationStateRepository $organizationStateRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Syncs this install's live, active store views to the platform.
     *
     * @param string $baseUrl
     * @param string $apiKey
     * @return SyncResult
     */
    public function sync(string $baseUrl, string $apiKey): SyncResult
    {
        // Unlike metrics submission, the sync endpoint has no server-side
        // 403-on-paused check, so this cached client-side gate is the only
        // thing stopping a paused organization from syncing.
        if ($this->organizationStateRepository->isPaused(new \DateTimeImmutable())) {
            return new SyncResult(succeeded: false, errorMessage: 'Organization is paused; not syncing store views.');
        }

        // StoreManagerInterface::getStores() only excludes the admin store
        // (id 0); it does NOT filter on is_active, so a store view the
        // merchant disabled in Magento would otherwise be reported as "live",
        // consuming the platform's shop allowance and metered billing
        // quantity for a storefront that isn't running. LiveStoreViewResolver
        // applies that filter identically for every caller in the module.
        $storeViews = array_values(array_map(
            fn (StoreInterface $store): array => $this->describeStore($store),
            $this->liveStoreViewResolver->all()
        ));

        if ($storeViews === []) {
            return new SyncResult(succeeded: false, errorMessage: 'No live store views found on this Magento install.');
        }

        $this->logger->debug('Watchtower syncing store views.', ['count' => count($storeViews)]);

        try {
            $response = $this->client->post($baseUrl, $apiKey, self::PATH, ['store_views' => $storeViews]);
        } catch (\Throwable $e) {
            $this->logger->debug('Watchtower sync raised an exception.', ['error' => $e->getMessage()]);

            return new SyncResult(succeeded: false, errorMessage: $e->getMessage());
        }

        if ($response->statusCode !== 200) {
            $statusCode = $response->statusCode;
            $this->logger->debug('Watchtower sync failed.', ['statusCode' => $statusCode]);

            return new SyncResult(
                succeeded: false,
                errorMessage: $response->body['message'] ?? sprintf('Unexpected HTTP %d.', $statusCode),
            );
        }

        $body = $response->body ?? [];
        $synced = $body['synced'] ?? [];
        $created = $body['created'] ?? [];
        $rejected = $body['rejected'] ?? [];

        $this->logger->debug('Watchtower sync succeeded.', [
            'syncedCount' => count($synced),
            'createdCount' => count($created),
            'rejectedCount' => count($rejected),
        ]);

        return new SyncResult(succeeded: true, synced: $synced, created: $created, rejected: $rejected);
    }

    /**
     * Converts a store into the wire shape POST /api/installs/sync expects.
     *
     * @param StoreInterface $store
     * @return array{
     *     code: string,
     *     name: string,
     *     url: string,
     *     website_name: string,
     *     store_name: string,
     *     store_view_id: int
     * }
     */
    private function describeStore(StoreInterface $store): array
    {
        /**
         * @var \Magento\Store\Model\Store $store phpstan/psr type widening;
         * StoreInterface itself has no getWebsite()/getGroup()
         */
        return [
            'code' => (string) $store->getCode(),
            'name' => (string) $store->getName(),
            'url' => (string) $store->getBaseUrl(),
            'website_name' => (string) $store->getWebsite()->getName(),
            'store_name' => (string) $store->getGroup()->getName(),
            'store_view_id' => (int) $store->getId(),
        ];
    }
}
