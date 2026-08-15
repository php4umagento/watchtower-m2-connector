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
use Watchtower\Connector\Model\Api\MetricReport;
use Watchtower\Connector\Model\Api\MetricsSubmissionService;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\Response;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Organization\OrganizationStateRepository;

/**
 * Covers MetricsSubmissionService's own logic in isolation (as opposed to
 * ReportingServiceTest, which mocks it out), including
 * organization_paused self-detection: a 403 is the ONLY condition the
 * platform's metrics endpoint raises it for, so it's a reliable signal
 * the connector can act on without a separate ping first.
 */
class MetricsSubmissionServiceTest extends TestCase
{
    public function testASuccessfulSubmissionReturnsAcceptedAndRejected(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('post')->willReturn(new Response(200, ['accepted' => 2, 'rejected' => []]));

        $result = $this->service($client)->submit('https://watchtower.test', 'a-key', [$this->report()]);

        self::assertTrue($result->succeeded);
        self::assertSame(2, $result->accepted);
        self::assertSame([], $result->rejected);
    }

    public function testA429ResponseCarriesTheRetryAfterSeconds(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('post')->willReturn(new Response(429, ['message' => 'Too Many Attempts.'], 30));

        $result = $this->service($client)->submit('https://watchtower.test', 'a-key', [$this->report()]);

        self::assertFalse($result->succeeded);
        self::assertSame(30, $result->retryAfterSeconds);
    }

    public function testANetworkFailureIsNotThrown(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('post')->willThrowException(new \RuntimeException('Connection refused'));

        $result = $this->service($client)->submit('https://watchtower.test', 'a-key', [$this->report()]);

        self::assertFalse($result->succeeded);
        self::assertSame('Connection refused', $result->errorMessage);
    }

    /**
     * InstallMetricsController.php's ONLY abort_if(403) condition is a
     * paused organization -- self-detecting this without a separate ping
     * lets ReportingService/StoreViewSyncService gate future attempts
     * immediately, not just after the next manual ping.
     */
    public function testA403ResponseMarksTheOrganizationAsPaused(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('post')->willReturn(new Response(403, ['message' => 'Organization is paused.']));

        $organizationStateRepository = $this->createMock(OrganizationStateRepository::class);
        $organizationStateRepository->expects(self::once())->method('save')
            ->with(true, self::isInstanceOf(\DateTimeImmutable::class));

        $this->service($client, $organizationStateRepository)
            ->submit('https://watchtower.test', 'a-key', [$this->report()]);
    }

    /**
     * A 200 is proof the organization is NOT paused right now -- must clear
     * a stale "paused" cached from an earlier failure without waiting for
     * the next manual ping.
     */
    public function testASuccessfulSubmissionMarksTheOrganizationAsNotPaused(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('post')->willReturn(new Response(200, ['accepted' => 1, 'rejected' => []]));

        $organizationStateRepository = $this->createMock(OrganizationStateRepository::class);
        $organizationStateRepository->expects(self::once())->method('save')
            ->with(false, self::isInstanceOf(\DateTimeImmutable::class));

        $this->service($client, $organizationStateRepository)
            ->submit('https://watchtower.test', 'a-key', [$this->report()]);
    }

    /**
     * A 401/429/5xx carries no signal about pause state at all -- must not
     * touch the cache either direction.
     */
    public function testANonPausedNonSuccessResponseDoesNotTouchTheCachedPausedState(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('post')->willReturn(new Response(401, ['message' => 'Unauthenticated.']));

        $organizationStateRepository = $this->createMock(OrganizationStateRepository::class);
        $organizationStateRepository->expects(self::never())->method('save');

        $this->service($client, $organizationStateRepository)
            ->submit('https://watchtower.test', 'a-key', [$this->report()]);
    }

    /**
     * Logs the outcome at debug level without ever including the API key
     * -- baseUrl/apiKey are passed to submit() but must never
     * appear in a log call's own context.
     */
    public function testLogsTheSubmissionOutcomeAtDebugLevelWithoutTheApiKey(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('post')->willReturn(new Response(200, ['accepted' => 3, 'rejected' => []]));

        $calls = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('debug')->willReturnCallback(function (string $message, array $context) use (&$calls): void {
            $calls[] = [$message, $context];
        });

        $service = new MetricsSubmissionService(
            $client,
            $this->createStub(OrganizationStateRepository::class),
            $logger
        );
        $service->submit('https://watchtower.test', 'a-real-secret-key', [$this->report(), $this->report()]);

        self::assertSame(['Watchtower submitting metric reports.', ['count' => 2]], $calls[0]);
        self::assertSame(
            ['Watchtower metrics submission succeeded.', ['accepted' => 3, 'rejectedCount' => 0]],
            $calls[1]
        );

        foreach ($calls as [$message, $context]) {
            self::assertStringNotContainsString('a-real-secret-key', $message . json_encode($context));
        }
    }

    private function service(
        Client $client,
        ?OrganizationStateRepository $organizationStateRepository = null
    ): MetricsSubmissionService {
        return new MetricsSubmissionService(
            $client,
            $organizationStateRepository ?? $this->createStub(OrganizationStateRepository::class),
            $this->createStub(LoggerInterface::class)
        );
    }

    private function report(): MetricReport
    {
        return new MetricReport(
            storeViewCode: null,
            eventType: 'cron_health',
            status: SignalStatus::Normal,
            sequenceNumber: 1,
            evaluatedAt: new \DateTimeImmutable('2026-08-13T15:00:00+00:00'),
            reason: ReportReason::Heartbeat,
            rulesetVersion: '1.0.1',
        );
    }
}
