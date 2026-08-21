<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Api;

use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Environment\ConnectorVersionReader;
use Watchtower\Connector\Model\Environment\ConnectorVersionStateRepository;
use Watchtower\Connector\Model\Environment\EnvironmentStateRepository;
use Watchtower\Connector\Model\Environment\MagentoVersionReader;
use Watchtower\Connector\Model\Organization\OrganizationStateRepository;
use Watchtower\Connector\Model\StoreView\IgnoredDomainStateRepository;
use Watchtower\Connector\Model\StoreView\LiveStoreViewResolver;

/**
 * Reports this install's live store views to the platform. Also carries this
 * install's Magento version/edition and the connector's own installed
 * version -- identity and environment facts, not metrics -- so the platform
 * can flag an EOL Magento version or an outdated connector without either
 * check depending on the module's own (possibly outdated) judgment of itself.
 */
class StoreViewSyncService
{
    private const PATH = '/api/installs/sync';

    /**
     * @param LiveStoreViewResolver $liveStoreViewResolver
     * @param Client $client
     * @param OrganizationStateRepository $organizationStateRepository
     * @param MagentoVersionReader $magentoVersionReader
     * @param ConnectorVersionReader $connectorVersionReader
     * @param EnvironmentStateRepository $environmentStateRepository
     * @param ConnectorVersionStateRepository $connectorVersionStateRepository
     * @param IgnoredDomainStateRepository $ignoredDomainStateRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly LiveStoreViewResolver $liveStoreViewResolver,
        private readonly Client $client,
        private readonly OrganizationStateRepository $organizationStateRepository,
        private readonly MagentoVersionReader $magentoVersionReader,
        private readonly ConnectorVersionReader $connectorVersionReader,
        private readonly EnvironmentStateRepository $environmentStateRepository,
        private readonly ConnectorVersionStateRepository $connectorVersionStateRepository,
        private readonly IgnoredDomainStateRepository $ignoredDomainStateRepository,
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

        // PRD FR25: below minimum_version stops sync too, not just metrics.
        // Reads the state ReportingService's own cycle last persisted --
        // this method never makes its own connector-version call, since
        // FR24 ties that check to the report cycle's cadence, not sync's.
        if ($this->connectorVersionStateRepository->get()->belowMinimum) {
            return new SyncResult(
                succeeded: false,
                errorMessage: 'Connector version is below the minimum supported version; not syncing store views.',
            );
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

        $payload = [
            'store_views' => $storeViews,
            'magento_version' => $this->magentoVersionReader->version(),
            'magento_edition' => $this->magentoVersionReader->edition(),
            'connector_version' => $this->connectorVersionReader->version(),
        ];

        try {
            $response = $this->client->post($baseUrl, $apiKey, self::PATH, $payload);
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

        $magentoEol = $this->parseMagentoEol($body['magento_eol'] ?? null);

        // Cached locally so watchtower:status and the Diagnostics admin page
        // can show it without a live round trip of their own -- only a real
        // sync ever refreshes this.
        $this->environmentStateRepository->save(
            $payload['magento_version'],
            $payload['magento_edition'],
            $payload['connector_version'],
            $magentoEol,
            new \DateTimeImmutable(),
        );

        $this->saveIgnoredDomainState($rejected);

        return new SyncResult(
            succeeded: true,
            synced: $synced,
            created: $created,
            rejected: $rejected,
            magentoEol: $magentoEol,
        );
    }

    /**
     * Persists this sync's ignored-local-domain outcome (PRD FR28-30) for
     * the admin notice -- including a zero count, so a resolved local domain
     * clears the notice on the next sync.
     *
     * @param array<int,array{code:string,reason:string,reason_code?:string}> $rejected
     * @return void
     */
    private function saveIgnoredDomainState(array $rejected): void
    {
        $ignored = array_values(array_filter(
            $rejected,
            static fn (array $entry): bool => ($entry['reason_code'] ?? null) === 'ignored_local_domain'
        ));

        $this->ignoredDomainStateRepository->save(
            count($ignored),
            $ignored[0]['code'] ?? null,
            new \DateTimeImmutable(),
        );
    }

    /**
     * Parses the response's 'magento_eol' value into a typed DTO.
     *
     * @param mixed $raw the decoded 'magento_eol' response value, expected to be an
     *     array{is_eol: bool, eol_date: string|null, status_label: string|null} or null
     * @return MagentoEolInfo|null
     */
    private function parseMagentoEol(mixed $raw): ?MagentoEolInfo
    {
        if (!is_array($raw)) {
            return null;
        }

        return new MagentoEolInfo(
            isEol: (bool) ($raw['is_eol'] ?? false),
            eolDate: $raw['eol_date'] ?? null,
            statusLabel: $raw['status_label'] ?? null,
        );
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
