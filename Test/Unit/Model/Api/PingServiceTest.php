<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Api;

use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\Client;
use Watchtower\Connector\Model\Api\PingService;
use Watchtower\Connector\Model\Api\Response;
use Watchtower\Connector\Model\Organization\OrganizationStateRepository;

class PingServiceTest extends TestCase
{
    public function testSuccessfulPingReturnsReachableAndKeyValidWithAllFields(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('get')->willReturn(new Response(200, [
            'status' => 'ok',
            'install' => 'My Magento Install',
            'organization_paused' => false,
            'server_time' => '2026-08-13T18:30:00+00:00',
            'entitled_signals' => ['basket_quote', 'checkout', 'cron_health'],
            'alerting_enabled' => true,
        ]));

        $result = $this->service($client)->ping('https://watchtower.test', 'a-real-key');

        self::assertTrue($result->reachable);
        self::assertTrue($result->keyValid());
        self::assertSame(200, $result->httpStatus);
        self::assertSame('My Magento Install', $result->install);
        self::assertFalse($result->organizationPaused);
        self::assertSame(['basket_quote', 'checkout', 'cron_health'], $result->entitledSignals);
        self::assertTrue($result->alertingEnabled);
        self::assertNull($result->errorMessage);
    }

    public function testInvalidKeyIsReachableButNotKeyValid(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('get')->willReturn(new Response(401, ['message' => 'Unauthenticated.']));

        $result = $this->service($client)->ping('https://watchtower.test', 'a-wrong-key');

        self::assertTrue($result->reachable, 'A 401 still means the platform was reached.');
        self::assertFalse($result->keyValid());
        self::assertSame(401, $result->httpStatus);
        self::assertSame('Unauthenticated.', $result->errorMessage);
    }

    public function testNetworkFailureIsNeitherReachableNorKeyValid(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('get')->willThrowException(new \RuntimeException('Could not resolve host'));

        $result = $this->service($client)->ping('https://watchtower.test', 'a-real-key');

        self::assertFalse($result->reachable);
        self::assertFalse($result->keyValid());
        self::assertNull($result->httpStatus);
        self::assertSame('Could not resolve host', $result->errorMessage);
    }

    public function testDistinctOutcomesAreNotConfusedWithEachOther(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('get')->willReturn(new Response(200, ['status' => 'ok']));
        $success = $this->service($client)->ping('https://watchtower.test', 'k');

        $client429 = $this->createStub(Client::class);
        $client429->method('get')->willReturn(new Response(429, ['message' => 'Too Many Attempts.']));
        $rateLimited = $this->service($client429)->ping('https://watchtower.test', 'k');

        self::assertTrue($success->reachable && $success->keyValid());
        self::assertTrue($rateLimited->reachable && !$rateLimited->keyValid());
        self::assertNotSame($success->keyValid(), $rateLimited->keyValid());
    }

    /**
     * A ping response's own organization_paused value is the platform's
     * authoritative signal -- must be cached locally so
     * ReportingService/StoreViewSyncService can gate on it without pinging
     * before every cycle.
     */
    public function testASuccessfulPingCachesTheOrganizationPausedValue(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('get')->willReturn(new Response(200, ['organization_paused' => true]));

        $organizationStateRepository = $this->createMock(OrganizationStateRepository::class);
        $organizationStateRepository->expects(self::once())->method('save')
            ->with(true, self::isInstanceOf(\DateTimeImmutable::class));

        $this->service($client, $organizationStateRepository)->ping('https://watchtower.test', 'k');
    }

    /**
     * A 401/429/network failure carries no organization_paused signal at
     * all -- must not overwrite the cache with a guessed value.
     */
    public function testANonSuccessfulPingDoesNotTouchTheCachedPausedState(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('get')->willReturn(new Response(401, ['message' => 'Unauthenticated.']));

        $organizationStateRepository = $this->createMock(OrganizationStateRepository::class);
        $organizationStateRepository->expects(self::never())->method('save');

        $this->service($client, $organizationStateRepository)->ping('https://watchtower.test', 'k');
    }

    private function service(
        Client $client,
        ?OrganizationStateRepository $organizationStateRepository = null
    ): PingService {
        return new PingService(
            $client,
            $organizationStateRepository ?? $this->createStub(OrganizationStateRepository::class)
        );
    }
}
