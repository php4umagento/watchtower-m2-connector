<?php

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Api;

use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Api\Client;
use Watchtower\Connector\Model\Api\Response;
use Watchtower\Connector\Model\Api\StoreViewSyncService;
use Watchtower\Connector\Model\Organization\OrganizationStateRepository;
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

    private function pausedStub(bool $paused): OrganizationStateRepository
    {
        $repository = $this->createStub(OrganizationStateRepository::class);
        $repository->method('isPaused')->willReturn($paused);

        return $repository;
    }

    private function service(
        StoreManagerInterface $storeManager,
        Client $client,
        ?OrganizationStateRepository $organizationStateRepository = null
    ): StoreViewSyncService {
        if ($organizationStateRepository === null) {
            $organizationStateRepository = $this->createStub(OrganizationStateRepository::class);
            $organizationStateRepository->method('isPaused')->willReturn(false);
        }

        return new StoreViewSyncService(
            new LiveStoreViewResolver($storeManager),
            $client,
            $organizationStateRepository,
            $this->createStub(LoggerInterface::class)
        );
    }
}
