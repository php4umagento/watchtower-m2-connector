<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Api;

use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Api\Client;
use Watchtower\Connector\Model\Api\Response;
use Watchtower\Connector\Model\Api\StoreViewSyncService;
use Watchtower\Connector\Model\Environment\ConnectorVersionReader;
use Watchtower\Connector\Model\Environment\ConnectorVersionState;
use Watchtower\Connector\Model\Environment\ConnectorVersionStateRepository;
use Watchtower\Connector\Model\Environment\EnvironmentStateRepository;
use Watchtower\Connector\Model\Environment\MagentoVersionReader;
use Watchtower\Connector\Model\Organization\OrganizationStateRepository;
use Watchtower\Connector\Model\StoreView\IgnoredDomainStateRepository;
use Watchtower\Connector\Model\StoreView\LiveStoreViewResolver;
use Watchtower\Connector\Test\Unit\StoreStubTrait;

class StoreViewSyncServiceTest extends TestCase
{
    use StoreStubTrait;

    public function testRejectedStoreViewDoesNotThrowAndIsExcludedFromSyncedOrCreated(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $client = $this->createStub(Client::class);
        $client->method('post')->willReturn(new Response(200, [
            'synced' => [],
            'created' => [],
            'rejected' => [
                ['code' => 'default', 'reason' => 'store view allowance exceeded for this install'],
            ],
        ]));

        $result = $this->service($storeManager, $client)->sync('https://watchtower.test', 'a-real-api-key');

        self::assertTrue($result->succeeded);
        self::assertSame([], $result->synced);
        self::assertSame([], $result->created);
        self::assertCount(1, $result->rejected);
        self::assertSame('default', $result->rejected[0]['code']);
        self::assertNotContains('default', $result->synced);
        self::assertNotContains('default', $result->created);
    }

    public function testPayloadIncludesMagentoAndConnectorVersionAlongsideStoreViews(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $capturedPayload = null;

        $client = $this->createStub(Client::class);
        $client->method('post')->willReturnCallback(
            function (string $baseUrl, string $apiKey, string $path, array $payload) use (&$capturedPayload) {
                $capturedPayload = $payload;

                return new Response(200, ['synced' => ['default'], 'created' => [], 'rejected' => []]);
            }
        );

        $this->service($storeManager, $client)->sync('https://watchtower.test', 'a-real-api-key');

        self::assertSame('2.4.9', $capturedPayload['magento_version']);
        self::assertSame('Community', $capturedPayload['magento_edition']);
        self::assertSame('1.1.0', $capturedPayload['connector_version']);
    }

    public function testParsesMagentoEolFromTheResponse(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $client = $this->createStub(Client::class);
        $client->method('post')->willReturn(new Response(200, [
            'synced' => ['default'],
            'created' => [],
            'rejected' => [],
            'magento_eol' => ['is_eol' => true, 'eol_date' => '2025-06-11', 'status_label' => 'eol'],
        ]));

        $result = $this->service($storeManager, $client)->sync('https://watchtower.test', 'a-real-api-key');

        self::assertNotNull($result->magentoEol);
        self::assertTrue($result->magentoEol->isEol);
        self::assertSame('2025-06-11', $result->magentoEol->eolDate);
        self::assertSame('eol', $result->magentoEol->statusLabel);
    }

    /**
     * A response from a platform version that doesn't send this field yet
     * (or couldn't determine it) must not be misread as "definitely not
     * EOL" -- it must come back null, not a false-y info object.
     */
    public function testMagentoEolIsNullWhenAbsentFromTheResponse(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $client = $this->createStub(Client::class);
        $client->method('post')->willReturn(
            new Response(200, ['synced' => ['default'], 'created' => [], 'rejected' => []])
        );

        $result = $this->service($storeManager, $client)->sync('https://watchtower.test', 'a-real-api-key');

        self::assertNull($result->magentoEol);
    }

    public function testASuccessfulSyncPersistsTheEnvironmentStateForLaterOfflineDisplay(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $client = $this->createStub(Client::class);
        $client->method('post')->willReturn(new Response(200, [
            'synced' => ['default'],
            'created' => [],
            'rejected' => [],
            'magento_eol' => ['is_eol' => false, 'eol_date' => '2027-04-09', 'status_label' => 'supported'],
        ]));

        $savedArgs = null;
        $environmentStateRepository = $this->createMock(EnvironmentStateRepository::class);
        $environmentStateRepository->expects(self::once())->method('save')->willReturnCallback(
            function (...$args) use (&$savedArgs) {
                $savedArgs = $args;
            }
        );

        $organizationStateRepository = $this->createStub(OrganizationStateRepository::class);
        $organizationStateRepository->method('isPaused')->willReturn(false);

        $service = new StoreViewSyncService(
            new LiveStoreViewResolver($storeManager),
            $client,
            $organizationStateRepository,
            $this->magentoVersionReaderStub(),
            $this->connectorVersionReaderStub(),
            $environmentStateRepository,
            $this->connectorVersionStateRepositoryStub(),
            $this->createStub(IgnoredDomainStateRepository::class),
            $this->createStub(LoggerInterface::class)
        );
        $service->sync('https://watchtower.test', 'a-real-api-key');

        self::assertSame('2.4.9', $savedArgs[0]);
        self::assertSame('Community', $savedArgs[1]);
        self::assertSame('1.1.0', $savedArgs[2]);
        self::assertFalse($savedArgs[3]->isEol);
        self::assertInstanceOf(\DateTimeImmutable::class, $savedArgs[4]);
    }

    public function testAFailedSyncNeverPersistsEnvironmentState(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $client = $this->createStub(Client::class);
        $client->method('post')->willReturn(new Response(500, ['message' => 'Internal Server Error']));

        $environmentStateRepository = $this->createMock(EnvironmentStateRepository::class);
        $environmentStateRepository->expects(self::never())->method('save');

        $ignoredDomainStateRepository = $this->createMock(IgnoredDomainStateRepository::class);
        $ignoredDomainStateRepository->expects(self::never())->method('save');

        $organizationStateRepository = $this->createStub(OrganizationStateRepository::class);
        $organizationStateRepository->method('isPaused')->willReturn(false);

        $service = new StoreViewSyncService(
            new LiveStoreViewResolver($storeManager),
            $client,
            $organizationStateRepository,
            $this->magentoVersionReaderStub(),
            $this->connectorVersionReaderStub(),
            $environmentStateRepository,
            $this->connectorVersionStateRepositoryStub(),
            $ignoredDomainStateRepository,
            $this->createStub(LoggerInterface::class)
        );
        $result = $service->sync('https://watchtower.test', 'a-real-api-key');

        self::assertFalse($result->succeeded);
    }

    public function testSuccessfulSyncPopulatesSyncedAndCreated(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $client = $this->createStub(Client::class);
        $client->method('post')->willReturn(new Response(200, [
            'synced' => [],
            'created' => ['default'],
            'rejected' => [],
        ]));

        $result = $this->service($storeManager, $client)->sync('https://watchtower.test', 'a-real-api-key');

        self::assertTrue($result->succeeded);
        self::assertSame(['default'], $result->created);
        self::assertSame([], $result->rejected);
    }

    public function testNetworkFailureIsNotThrown(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $client = $this->createStub(Client::class);
        $client->method('post')->willThrowException(new \RuntimeException('Connection refused'));

        $result = $this->service($storeManager, $client)->sync('https://watchtower.test', 'a-real-api-key');

        self::assertFalse($result->succeeded);
        self::assertSame('Connection refused', $result->errorMessage);
    }

    /**
     * Regression test for a real bug caught in review:
     * StoreManagerInterface::getStores() only excludes the admin store (id
     * 0); it does not filter on is_active. A disabled store view must not be
     * reported as live; doing so would make the platform create a
     * StoreView and consume the shop allowance / metered billing quantity
     * for a storefront that isn't actually running.
     */
    public function testDisabledStoreViewsAreNotSynced(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([
            $this->activeStore('default'),
            $this->inactiveStore('disabled_view'),
        ]);

        $capturedPayload = null;

        $client = $this->createMock(Client::class);
        $client->expects(self::once())
            ->method('post')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(function (array $payload) use (&$capturedPayload) {
                    $capturedPayload = $payload;

                    return true;
                })
            )
            ->willReturn(new Response(200, ['synced' => ['default'], 'created' => [], 'rejected' => []]));

        $this->service($storeManager, $client)->sync('https://watchtower.test', 'a-real-api-key');

        self::assertCount(1, $capturedPayload['store_views']);
        self::assertSame('default', $capturedPayload['store_views'][0]['code']);
    }

    public function testNoLiveStoreViewsReportsFailureRatherThanSendingAnEmptyBatch(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->inactiveStore('disabled_view')]);

        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('post');

        $result = $this->service($storeManager, $client)->sync('https://watchtower.test', 'a-real-api-key');

        self::assertFalse($result->succeeded);
    }

    /**
     * The platform's sync endpoint has no 403-on-paused check of its own,
     * so this cached client-side gate is the ONLY protection for sync
     * while an organization is paused -- it must never call
     * the client at all while paused, not merely handle a rejection
     * gracefully.
     */
    public function testDoesNotSyncWhileTheOrganizationIsKnownToBePaused(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('post');

        $organizationStateRepository = $this->createStub(OrganizationStateRepository::class);
        $organizationStateRepository->method('isPaused')->willReturn(true);

        $result = $this->service($storeManager, $client, $organizationStateRepository)
            ->sync('https://watchtower.test', 'a-real-api-key');

        self::assertFalse($result->succeeded);
        self::assertSame('Organization is paused; not syncing store views.', $result->errorMessage);
    }

    /**
     * Logs the sync outcome at debug level without ever including the
     * API key.
     */
    public function testLogsTheSyncOutcomeAtDebugLevelWithoutTheApiKey(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $client = $this->createStub(Client::class);
        $client->method('post')->willReturn(
            new Response(200, ['synced' => [], 'created' => ['default'], 'rejected' => []])
        );

        $calls = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('debug')->willReturnCallback(function (string $message, array $context) use (&$calls): void {
            $calls[] = [$message, $context];
        });

        $service = new StoreViewSyncService(
            new LiveStoreViewResolver($storeManager),
            $client,
            $this->pausedStub(false),
            $this->magentoVersionReaderStub(),
            $this->connectorVersionReaderStub(),
            $this->createStub(EnvironmentStateRepository::class),
            $this->connectorVersionStateRepositoryStub(),
            $this->createStub(IgnoredDomainStateRepository::class),
            $logger
        );
        $service->sync('https://watchtower.test', 'a-real-secret-key');

        self::assertSame(['Watchtower syncing store views.', ['count' => 1]], $calls[0]);
        self::assertSame(
            ['Watchtower sync succeeded.', ['syncedCount' => 0, 'createdCount' => 1, 'rejectedCount' => 0]],
            $calls[1]
        );

        foreach ($calls as [$message, $context]) {
            self::assertStringNotContainsString('a-real-secret-key', $message . json_encode($context));
        }
    }

    /**
     * PRD FR25: below the platform's minimum_version, sync stops too, not
     * just metrics -- and like the paused gate it must short-circuit before
     * the client is touched at all, since an install syncing from a version
     * the platform has disowned is exactly what self-disabling exists to
     * prevent.
     */
    public function testDoesNotSyncWhileTheConnectorIsBelowTheMinimumVersion(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('post');

        $result = $this->service(
            $storeManager,
            $client,
            connectorVersionStateRepository: $this->connectorVersionStateRepositoryStub(belowMinimum: true)
        )->sync('https://watchtower.test', 'a-real-api-key');

        self::assertFalse($result->succeeded);
        self::assertSame(
            'Connector version is below the minimum supported version; not syncing store views.',
            $result->errorMessage
        );
    }

    public function testASuccessfulSyncWithNoIgnoredLocalDomainRejectionsClearsTheState(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $client = $this->createStub(Client::class);
        $client->method('post')->willReturn(new Response(200, [
            'synced' => ['default'],
            'created' => [],
            'rejected' => [],
        ]));

        $ignoredDomainStateRepository = $this->createMock(IgnoredDomainStateRepository::class);
        $ignoredDomainStateRepository->expects(self::once())->method('save')->with(0, null, self::isInstanceOf(
            \DateTimeImmutable::class
        ));

        $this->service($storeManager, $client, ignoredDomainStateRepository: $ignoredDomainStateRepository)
            ->sync('https://watchtower.test', 'a-real-api-key');
    }

    /**
     * PRD FR29: a rejection carrying reason_code ignored_local_domain must
     * be recorded for the admin notice, keyed by count and one example
     * code -- and must NOT be logged/treated as any kind of sync error.
     */
    public function testASuccessfulSyncWithAnIgnoredLocalDomainRejectionSavesTheCountAndAnExample(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $client = $this->createStub(Client::class);
        $client->method('post')->willReturn(new Response(200, [
            'synced' => [],
            'created' => [],
            'rejected' => [
                [
                    'code' => 'default',
                    'reason' => "The reported URL looks like a local or development environment.",
                    'reason_code' => 'ignored_local_domain',
                ],
            ],
        ]));

        $ignoredDomainStateRepository = $this->createMock(IgnoredDomainStateRepository::class);
        $ignoredDomainStateRepository->expects(self::once())->method('save')->with(1, 'default', self::isInstanceOf(
            \DateTimeImmutable::class
        ));

        $this->service($storeManager, $client, ignoredDomainStateRepository: $ignoredDomainStateRepository)
            ->sync('https://watchtower.test', 'a-real-api-key');
    }

    /**
     * Only entries carrying THIS reason_code count -- a rejection for any
     * other reason (allowance exceeded, held host mismatch, no reason_code
     * at all) must not be misread as a local domain.
     */
    public function testOnlyRejectionsCarryingTheIgnoredLocalDomainReasonCodeAreCounted(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $client = $this->createStub(Client::class);
        $client->method('post')->willReturn(new Response(200, [
            'synced' => [],
            'created' => [],
            'rejected' => [
                ['code' => 'sv1', 'reason' => 'store view allowance exceeded for this install'],
                [
                    'code' => 'sv2',
                    'reason' => "The reported URL's host doesn't match this shop's known host.",
                    'reason_code' => 'host_mismatch_held',
                ],
                [
                    'code' => 'sv3',
                    'reason' => "The reported URL looks like a local or development environment.",
                    'reason_code' => 'ignored_local_domain',
                ],
            ],
        ]));

        $ignoredDomainStateRepository = $this->createMock(IgnoredDomainStateRepository::class);
        $ignoredDomainStateRepository->expects(self::once())->method('save')->with(1, 'sv3', self::isInstanceOf(
            \DateTimeImmutable::class
        ));

        $this->service($storeManager, $client, ignoredDomainStateRepository: $ignoredDomainStateRepository)
            ->sync('https://watchtower.test', 'a-real-api-key');
    }

    private function pausedStub(bool $paused): OrganizationStateRepository
    {
        $repository = $this->createStub(OrganizationStateRepository::class);
        $repository->method('isPaused')->willReturn($paused);

        return $repository;
    }

    private function service(
        StoreManagerInterface $storeManager,
        Client $client,
        ?OrganizationStateRepository $organizationStateRepository = null,
        ?ConnectorVersionStateRepository $connectorVersionStateRepository = null,
        ?IgnoredDomainStateRepository $ignoredDomainStateRepository = null
    ): StoreViewSyncService {
        if ($organizationStateRepository === null) {
            $organizationStateRepository = $this->createStub(OrganizationStateRepository::class);
            $organizationStateRepository->method('isPaused')->willReturn(false);
        }

        return new StoreViewSyncService(
            new LiveStoreViewResolver($storeManager),
            $client,
            $organizationStateRepository,
            $this->magentoVersionReaderStub(),
            $this->connectorVersionReaderStub(),
            $this->createStub(EnvironmentStateRepository::class),
            $connectorVersionStateRepository ?? $this->connectorVersionStateRepositoryStub(),
            $ignoredDomainStateRepository ?? $this->createStub(IgnoredDomainStateRepository::class),
            $this->createStub(LoggerInterface::class)
        );
    }

    private function magentoVersionReaderStub(): MagentoVersionReader
    {
        $reader = $this->createStub(MagentoVersionReader::class);
        $reader->method('version')->willReturn('2.4.9');
        $reader->method('edition')->willReturn('Community');

        return $reader;
    }

    private function connectorVersionStateRepositoryStub(bool $belowMinimum = false): ConnectorVersionStateRepository
    {
        $repository = $this->createStub(ConnectorVersionStateRepository::class);
        $repository->method('get')->willReturn(new ConnectorVersionState(
            installedVersion: '1.1.0',
            minimumVersion: $belowMinimum ? '1.2.0' : '1.0.0',
            latestVersion: '1.2.0',
            belowMinimum: $belowMinimum,
            updateAvailable: true,
            checkedAt: new \DateTimeImmutable('2026-08-14T10:00:00+00:00'),
        ));

        return $repository;
    }

    private function connectorVersionReaderStub(): ConnectorVersionReader
    {
        $reader = $this->createStub(ConnectorVersionReader::class);
        $reader->method('version')->willReturn('1.1.0');

        return $reader;
    }
}
