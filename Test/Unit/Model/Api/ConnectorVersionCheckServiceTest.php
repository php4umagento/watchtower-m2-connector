<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Api;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Api\Client;
use Watchtower\Connector\Model\Api\ConnectorVersionCheckService;
use Watchtower\Connector\Model\Api\Response;
use Watchtower\Connector\Model\Environment\ConnectorVersionReader;

/**
 * The platform only ever states minimum_version/latest_version; deciding
 * whether THIS install is below either is entirely this service's own
 * comparison, so every branch of that comparison is covered here --
 * including the inconclusive ones, where reporting "below" would
 * self-disable a connector nobody asked to disable.
 */
class ConnectorVersionCheckServiceTest extends TestCase
{
    public function testAnInstalledVersionUnderMinimumIsReportedAsBelowMinimum(): void
    {
        $result = $this->service('1.0.0', new Response(200, [
            'minimum_version' => '1.2.0',
            'latest_version' => '1.3.0',
        ]))->check('https://watchtower.test', 'a-real-api-key');

        self::assertTrue($result->succeeded);
        self::assertSame('1.0.0', $result->installedVersion);
        self::assertSame('1.2.0', $result->minimumVersion);
        self::assertSame('1.3.0', $result->latestVersion);
        self::assertTrue($result->belowMinimum);
        self::assertTrue($result->updateAvailable);
        self::assertNull($result->errorMessage);
    }

    /**
     * The non-blocking case FR27 exists for: behind latest but at or above
     * minimum must never also read as belowMinimum, or an "update
     * available" notice would silently stop reporting.
     */
    public function testBehindLatestButAtOrAboveMinimumIsUpdateAvailableOnly(): void
    {
        $result = $this->service('1.2.0', new Response(200, [
            'minimum_version' => '1.2.0',
            'latest_version' => '1.3.0',
        ]))->check('https://watchtower.test', 'a-real-api-key');

        self::assertTrue($result->succeeded);
        self::assertFalse($result->belowMinimum, 'Exactly at the minimum is not below it.');
        self::assertTrue($result->updateAvailable);
    }

    public function testAnUpToDateInstallIsNeitherBelowMinimumNorUpdateAvailable(): void
    {
        $result = $this->service('1.3.0', new Response(200, [
            'minimum_version' => '1.2.0',
            'latest_version' => '1.3.0',
        ]))->check('https://watchtower.test', 'a-real-api-key');

        self::assertTrue($result->succeeded);
        self::assertFalse($result->belowMinimum);
        self::assertFalse($result->updateAvailable);
    }

    /**
     * A dev checkout ahead of the published latest_version must not be
     * mistaken for anything: version_compare is strictly "<", so being
     * ahead reads the same as being current.
     */
    public function testAnInstallAheadOfLatestIsTreatedAsCurrent(): void
    {
        $result = $this->service('2.0.0', new Response(200, [
            'minimum_version' => '1.2.0',
            'latest_version' => '1.3.0',
        ]))->check('https://watchtower.test', 'a-real-api-key');

        self::assertFalse($result->belowMinimum);
        self::assertFalse($result->updateAvailable);
    }

    /**
     * FR24's contract in one test: a failed check carries no verdict, so it
     * must never itself report belowMinimum. Failing closed here would
     * self-disable every connector the moment the platform had an outage.
     */
    public function testANetworkFailureNeverReportsBelowMinimum(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('get')->willThrowException(new \RuntimeException('Connection refused'));

        $result = $this->serviceWithClient('1.0.0', $client)->check('https://watchtower.test', 'a-real-api-key');

        self::assertFalse($result->succeeded);
        self::assertFalse($result->belowMinimum);
        self::assertFalse($result->updateAvailable);
        self::assertSame('Connection refused', $result->errorMessage);
        self::assertSame('1.0.0', $result->installedVersion);
        self::assertNull($result->minimumVersion);
        self::assertNull($result->latestVersion);
    }

    public function testANon200ResponseIsAFailureCarryingThePlatformsOwnMessage(): void
    {
        $result = $this->service('1.0.0', new Response(401, ['message' => 'Unauthenticated.']))
            ->check('https://watchtower.test', 'a-wrong-key');

        self::assertFalse($result->succeeded);
        self::assertFalse($result->belowMinimum);
        self::assertSame('Unauthenticated.', $result->errorMessage);
    }

    public function testANon200ResponseWithoutAMessageFallsBackToTheStatusCode(): void
    {
        $result = $this->service('1.0.0', new Response(503, []))
            ->check('https://watchtower.test', 'a-real-api-key');

        self::assertFalse($result->succeeded);
        self::assertSame('Unexpected HTTP 503.', $result->errorMessage);
    }

    /**
     * A source/dev checkout has no Composer-installed version at all. The
     * comparison is then inconclusive rather than "oldest possible", so
     * neither flag may be set -- a developer running from a git clone must
     * not have reporting silently self-disabled.
     */
    public function testAnUnknownInstalledVersionIsNeverReportedAsBelowMinimum(): void
    {
        $result = $this->service(null, new Response(200, [
            'minimum_version' => '1.2.0',
            'latest_version' => '1.3.0',
        ]))->check('https://watchtower.test', 'a-real-api-key');

        self::assertTrue($result->succeeded);
        self::assertNull($result->installedVersion);
        self::assertFalse($result->belowMinimum);
        self::assertFalse($result->updateAvailable);
    }

    /**
     * The mirror image: a platform that hasn't configured a minimum (or
     * sends a non-string for it) states no floor at all, which is not the
     * same as a floor everything clears -- but it must still never disable.
     */
    public function testAnAbsentOrNonStringThresholdIsNeverReportedAsBelowMinimum(): void
    {
        $absent = $this->service('1.0.0', new Response(200, []))
            ->check('https://watchtower.test', 'a-real-api-key');

        self::assertTrue($absent->succeeded);
        self::assertNull($absent->minimumVersion);
        self::assertNull($absent->latestVersion);
        self::assertFalse($absent->belowMinimum);
        self::assertFalse($absent->updateAvailable);

        $nonString = $this->service('1.0.0', new Response(200, [
            'minimum_version' => 120,
            'latest_version' => ['1.3.0'],
        ]))->check('https://watchtower.test', 'a-real-api-key');

        self::assertNull($nonString->minimumVersion);
        self::assertNull($nonString->latestVersion);
        self::assertFalse($nonString->belowMinimum);
        self::assertFalse($nonString->updateAvailable);
    }

    /**
     * This endpoint is platform-wide config, identical for every install,
     * so it is a plain GET on a fixed path -- pinned here because the
     * platform routes minimum_version by path, not by payload.
     */
    public function testCallsTheConnectorVersionEndpointWithTheConfiguredCredentials(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('get')
            ->with('https://watchtower.test', 'a-real-api-key', '/api/installs/connector-version')
            ->willReturn(new Response(200, ['minimum_version' => '1.0.0', 'latest_version' => '1.0.0']));

        $this->serviceWithClient('1.0.0', $client)->check('https://watchtower.test', 'a-real-api-key');
    }

    private function service(?string $installedVersion, Response $response): ConnectorVersionCheckService
    {
        $client = $this->createStub(Client::class);
        $client->method('get')->willReturn($response);

        return $this->serviceWithClient($installedVersion, $client);
    }

    private function serviceWithClient(?string $installedVersion, Client $client): ConnectorVersionCheckService
    {
        $reader = $this->createStub(ConnectorVersionReader::class);
        $reader->method('version')->willReturn($installedVersion);

        return new ConnectorVersionCheckService($client, $reader, $this->createStub(LoggerInterface::class));
    }
}
