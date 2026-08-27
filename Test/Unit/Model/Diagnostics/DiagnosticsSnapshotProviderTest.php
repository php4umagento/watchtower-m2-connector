<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Diagnostics;

use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\PingResult;
use Watchtower\Connector\Model\Api\PingService;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Buffer\ReportBufferRepository;
use Watchtower\Connector\Model\Config;
use Watchtower\Connector\Model\Environment\ConnectorVersionState;
use Watchtower\Connector\Model\Environment\ConnectorVersionStateRepository;
use Watchtower\Connector\Model\Environment\EnvironmentState;
use Watchtower\Connector\Model\Environment\EnvironmentStateRepository;
use Watchtower\Connector\Model\CronHealth\Evaluator as CronHealthEvaluator;
use Watchtower\Connector\Model\Diagnostics\DiagnosticsSnapshotProvider;
use Watchtower\Connector\Model\Diagnostics\SubmissionOutcome;
use Watchtower\Connector\Model\Diagnostics\SubmissionOutcomeRepository;
use Watchtower\Connector\Model\EventCounter\EventCounterRepository;
use Watchtower\Connector\Model\HealthState\HealthState;
use Watchtower\Connector\Model\HealthState\HealthStateRepository;
use Watchtower\Connector\Model\IntegrationHealth\WatchedIntegrationRepository;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthState;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthStateRepository;
use Watchtower\Connector\Model\RateSignal\DispersionEvaluator;
use Watchtower\Connector\Model\RateSignal\DispersionState;
use Watchtower\Connector\Model\RateSignal\DispersionStateRepository;
use Watchtower\Connector\Model\Seed\HistorySeeder;
use Watchtower\Connector\Model\Seed\SeedCoverageRepository;
use Watchtower\Connector\Model\Seed\SeedCoverageResult;
use Watchtower\Connector\Model\Seed\SeedCoverageStatus;
use Watchtower\Connector\Model\StoreView\LiveStoreViewResolver;
use Watchtower\Connector\Test\Unit\StoreStubTrait;

/**
 * Proves DiagnosticsSnapshotProvider assembles a full DiagnosticsSnapshot
 * from its 12 read-only sources -- this is the shared layer both
 * watchtower:status and the admin diagnostics page render, so its own
 * assembly logic is tested independently of either presentation.
 */
class DiagnosticsSnapshotProviderTest extends TestCase
{
    use StoreStubTrait;

    private const NOW_STRING = '2026-08-14T09:00:00+00:00';

    public function testUnreachablePingStillProducesASnapshotWithNullConnectionFields(): void
    {
        $pingService = $this->createStub(PingService::class);
        $pingService->method('ping')->willReturn(
            new PingResult(reachable: false, errorMessage: 'Connection refused')
        );

        $snapshot = $this->provider(pingService: $pingService)->snapshot($this->now());

        self::assertFalse($snapshot->reachable);
        self::assertSame('Connection refused', $snapshot->unreachableError);
        self::assertNull($snapshot->keyValid);
        self::assertNull($snapshot->organizationPaused);
    }

    public function testReachablePingSurfacesKeyValidityAndOrganizationPaused(): void
    {
        $pingService = $this->createStub(PingService::class);
        $pingService->method('ping')->willReturn(
            new PingResult(reachable: true, httpStatus: 200, organizationPaused: true)
        );

        $snapshot = $this->provider(pingService: $pingService)->snapshot($this->now());

        self::assertTrue($snapshot->reachable);
        self::assertNull($snapshot->unreachableError);
        self::assertTrue($snapshot->keyValid);
        self::assertTrue($snapshot->organizationPaused);
    }

    public function testSurfacesBufferAndDroppedEventCounts(): void
    {
        $bufferRepository = $this->createStub(ReportBufferRepository::class);
        $bufferRepository->method('bufferedCount')->willReturn(7);
        $bufferRepository->method('lastSuccessfulSubmissionAt')
            ->willReturn(new \DateTimeImmutable('2026-08-14T08:00:00+00:00'));

        $eventCounterRepository = $this->createStub(EventCounterRepository::class);
        $eventCounterRepository->method('totalDroppedInLast24Hours')->willReturn(4);

        $snapshot = $this->provider(
            bufferRepository: $bufferRepository,
            eventCounterRepository: $eventCounterRepository,
        )->snapshot($this->now());

        self::assertSame(7, $snapshot->bufferedReportCount);
        self::assertSame(4, $snapshot->droppedEventCountLast24Hours);
        self::assertSame(
            '2026-08-14T08:00:00+00:00',
            $snapshot->lastSuccessfulSubmissionAt?->format(\DateTimeInterface::ATOM)
        );
    }

    public function testSurfacesCronHealthFromHealthStateRepository(): void
    {
        $healthStateRepository = $this->createStub(HealthStateRepository::class);
        $healthStateRepository->method('get')->willReturnCallback(
            fn (string $eventType) => new HealthState(
                eventType: $eventType,
                lastSuccessAt: null,
                lastFailureAt: null,
                pendingStatus: null,
                confirmedStatus: SignalStatus::Normal,
                sequenceNumber: 12,
                lastReportedReason: ReportReason::Heartbeat,
            )
        );

        $snapshot = $this->provider(healthStateRepository: $healthStateRepository)->snapshot($this->now());

        self::assertSame(CronHealthEvaluator::EVENT_TYPE, $snapshot->cronHealth->category);
        self::assertSame(SignalStatus::Normal, $snapshot->cronHealth->status);
        self::assertSame(12, $snapshot->cronHealth->sequenceNumber);
        self::assertSame(ReportReason::Heartbeat, $snapshot->cronHealth->reason);
    }

    /**
     * Every live store view gets a signal for each of the 3 dispersion
     * categories (basket_quote, checkout, customer_account), keyed off
     * HistorySeeder::CATEGORY_* -- integration_health is deliberately NOT
     * included here, since this store view has no configured source.
     */
    public function testEachLiveStoreViewGetsTheThreeDispersionCategorySignals(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $dispersionStateRepository = $this->createStub(DispersionStateRepository::class);
        $dispersionStateRepository->method('get')->willReturnCallback(
            fn (int $storeViewId, string $category) => new DispersionState(
                storeViewId: $storeViewId,
                category: $category,
                pendingStatus: null,
                confirmedStatus: SignalStatus::InsufficientData,
                sequenceNumber: 1,
                lastReportedReason: ReportReason::Transition,
            )
        );

        $watchedIntegrationRepository = $this->createStub(WatchedIntegrationRepository::class);
        $watchedIntegrationRepository->method('hasAnyWatched')->willReturn(false);

        $snapshot = $this->provider(
            storeManager: $storeManager,
            dispersionStateRepository: $dispersionStateRepository,
            watchedIntegrationRepository: $watchedIntegrationRepository,
        )->snapshot($this->now());

        self::assertCount(1, $snapshot->storeViews);
        $storeView = $snapshot->storeViews[0];
        self::assertSame('default', $storeView->storeViewCode);
        self::assertCount(3, $storeView->signals);
        self::assertSame(
            [
                HistorySeeder::CATEGORY_BASKET_QUOTE,
                HistorySeeder::CATEGORY_CHECKOUT,
                HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT,
            ],
            array_map(static fn ($signal) => $signal->category, $storeView->signals)
        );
        self::assertSame(
            [ReportReason::Transition, ReportReason::Transition, ReportReason::Transition],
            array_map(static fn ($signal) => $signal->reason, $storeView->signals)
        );
    }

    /**
     * The per-signal detection-latency estimate comes from DispersionEvaluator,
     * a separate read-only query from the confirmed status/sequence read off
     * DispersionStateRepository -- this proves the two get threaded together
     * onto the same SignalSnapshot, keyed by category.
     */
    public function testDetectionLatencyIsThreadedOntoTheMatchingSignalSnapshot(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $dispersionStateRepository = $this->createStub(DispersionStateRepository::class);
        $dispersionStateRepository->method('get')->willReturnCallback(
            fn (int $storeViewId, string $category) => new DispersionState(
                storeViewId: $storeViewId,
                category: $category,
                pendingStatus: null,
                confirmedStatus: SignalStatus::Normal,
                sequenceNumber: 5,
            )
        );

        $dispersionEvaluator = $this->createStub(DispersionEvaluator::class);
        $dispersionEvaluator->method('estimatedDetectionLatencyHours')->willReturnCallback(
            fn (int $storeViewId, string $category) => $category === HistorySeeder::CATEGORY_BASKET_QUOTE
                ? 19.0
                : null
        );

        $watchedIntegrationRepository = $this->createStub(WatchedIntegrationRepository::class);
        $watchedIntegrationRepository->method('hasAnyWatched')->willReturn(false);

        $snapshot = $this->provider(
            storeManager: $storeManager,
            dispersionStateRepository: $dispersionStateRepository,
            dispersionEvaluator: $dispersionEvaluator,
            watchedIntegrationRepository: $watchedIntegrationRepository,
        )->snapshot($this->now());

        $signals = $snapshot->storeViews[0]->signals;
        $byCategory = [];
        foreach ($signals as $signal) {
            $byCategory[$signal->category] = $signal->estimatedDetectionLatencyHours;
        }

        self::assertSame(19.0, $byCategory[HistorySeeder::CATEGORY_BASKET_QUOTE]);
        self::assertNull($byCategory[HistorySeeder::CATEGORY_CHECKOUT]);
        self::assertNull($byCategory[HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT]);
    }

    /**
     * ensembleDrivingChecks comes straight off DispersionState, unlike
     * estimatedDetectionLatencyHours which is a separate DispersionEvaluator
     * query -- proves the read-only state field itself reaches the snapshot.
     */
    public function testEnsembleDrivingChecksIsThreadedOntoTheMatchingSignalSnapshot(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $dispersionStateRepository = $this->createStub(DispersionStateRepository::class);
        $dispersionStateRepository->method('get')->willReturnCallback(
            fn (int $storeViewId, string $category) => new DispersionState(
                storeViewId: $storeViewId,
                category: $category,
                pendingStatus: null,
                confirmedStatus: SignalStatus::SevereDrop,
                sequenceNumber: 5,
                ensembleDrivingChecks: $category === HistorySeeder::CATEGORY_BASKET_QUOTE
                    ? ['seasonal', 'trend']
                    : []
            )
        );

        $watchedIntegrationRepository = $this->createStub(WatchedIntegrationRepository::class);
        $watchedIntegrationRepository->method('hasAnyWatched')->willReturn(false);

        $snapshot = $this->provider(
            storeManager: $storeManager,
            dispersionStateRepository: $dispersionStateRepository,
            watchedIntegrationRepository: $watchedIntegrationRepository,
        )->snapshot($this->now());

        $byCategory = [];
        foreach ($snapshot->storeViews[0]->signals as $signal) {
            $byCategory[$signal->category] = $signal->ensembleDrivingChecks;
        }

        self::assertSame(['seasonal', 'trend'], $byCategory[HistorySeeder::CATEGORY_BASKET_QUOTE]);
        self::assertSame([], $byCategory[HistorySeeder::CATEGORY_CHECKOUT]);
        self::assertSame([], $byCategory[HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT]);
    }

    /**
     * seedCoverage is a read-only SeedCoverageRepository lookup, keyed the
     * same way estimatedDetectionLatencyHours is (per store view/category) --
     * proves it reaches the right SignalSnapshot and stays null for a
     * category that was never seeded.
     */
    public function testSeedCoverageIsThreadedOntoTheMatchingSignalSnapshot(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $dispersionStateRepository = $this->createStub(DispersionStateRepository::class);
        $dispersionStateRepository->method('get')->willReturnCallback(
            fn (int $storeViewId, string $category) => new DispersionState(
                storeViewId: $storeViewId,
                category: $category,
                pendingStatus: null,
                confirmedStatus: SignalStatus::Normal,
                sequenceNumber: 5,
            )
        );

        $seedResult = new SeedCoverageResult(
            category: HistorySeeder::CATEGORY_BASKET_QUOTE,
            requestedDays: 84,
            daysSeeded: 26,
            status: SeedCoverageStatus::Seeded,
        );
        $seedCoverageRepository = $this->createStub(SeedCoverageRepository::class);
        $seedCoverageRepository->method('get')->willReturnCallback(
            fn (int $storeViewId, string $category) => $category === HistorySeeder::CATEGORY_BASKET_QUOTE
                ? $seedResult
                : null
        );

        $watchedIntegrationRepository = $this->createStub(WatchedIntegrationRepository::class);
        $watchedIntegrationRepository->method('hasAnyWatched')->willReturn(false);

        $snapshot = $this->provider(
            storeManager: $storeManager,
            dispersionStateRepository: $dispersionStateRepository,
            seedCoverageRepository: $seedCoverageRepository,
            watchedIntegrationRepository: $watchedIntegrationRepository,
        )->snapshot($this->now());

        $byCategory = [];
        foreach ($snapshot->storeViews[0]->signals as $signal) {
            $byCategory[$signal->category] = $signal->seedCoverage;
        }

        self::assertSame($seedResult, $byCategory[HistorySeeder::CATEGORY_BASKET_QUOTE]);
        self::assertNull($byCategory[HistorySeeder::CATEGORY_CHECKOUT]);
        self::assertNull($byCategory[HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT]);
    }

    /**
     * A store view WITH a configured integration_health source gets a 4th
     * signal for it; one WITHOUT does not -- the config repository's
     * get() returning null is exactly the gate DiagnosticsSnapshotProvider
     * uses to decide whether to query IntegrationHealthStateRepository at
     * all for that store view.
     */
    public function testIntegrationHealthSignalOnlyAppearsWhenSomethingIsWatched(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $dispersionStateRepository = $this->createStub(DispersionStateRepository::class);
        $dispersionStateRepository->method('get')->willReturnCallback(
            fn (int $storeViewId, string $category) => new DispersionState(
                storeViewId: $storeViewId,
                category: $category,
                pendingStatus: null,
                confirmedStatus: null,
                sequenceNumber: 1,
            )
        );

        $watchedIntegrationRepository = $this->createStub(WatchedIntegrationRepository::class);
        $watchedIntegrationRepository->method('hasAnyWatched')->willReturn(true);

        $integrationHealthStateRepository = $this->createStub(IntegrationHealthStateRepository::class);
        $integrationHealthStateRepository->method('get')->willReturn(new IntegrationHealthState(
            storeViewId: 1,
            lastSuccessAt: null,
            lastFailureAt: null,
            pendingStatus: null,
            confirmedStatus: SignalStatus::Normal,
            sequenceNumber: 3,
            lastReportedReason: ReportReason::Heartbeat,
        ));

        $snapshot = $this->provider(
            storeManager: $storeManager,
            dispersionStateRepository: $dispersionStateRepository,
            watchedIntegrationRepository: $watchedIntegrationRepository,
            integrationHealthStateRepository: $integrationHealthStateRepository,
        )->snapshot($this->now());

        $signals = $snapshot->storeViews[0]->signals;
        self::assertCount(4, $signals);
        self::assertSame('integration_health', $signals[3]->category);
        self::assertSame(SignalStatus::Normal, $signals[3]->status);
        self::assertSame(3, $signals[3]->sequenceNumber);
        self::assertSame(ReportReason::Heartbeat, $signals[3]->reason);
    }

    /**
     * The isConfigured() guard lives in this shared layer rather than in
     * each caller: an install with the module enabled but no base URL/API
     * key saved yet would otherwise fatal with a TypeError the moment
     * ping() is called with null arguments under strict_types.
     */
    public function testAnUnconfiguredInstallReturnsAnUnreachableSnapshotWithoutCallingPing(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn(false);

        $pingService = $this->createMock(PingService::class);
        $pingService->expects(self::never())->method('ping');

        $snapshot = $this->provider(config: $config, pingService: $pingService)->snapshot($this->now());

        self::assertFalse($snapshot->reachable);
        self::assertSame('Watchtower is not configured.', $snapshot->unreachableError);
        self::assertNull($snapshot->keyValid);
        self::assertNull($snapshot->organizationPaused);
        self::assertSame([], $snapshot->storeViews);
        self::assertSame([], $snapshot->recentSubmissionOutcomes);
    }

    public function testRecentSubmissionOutcomesAreSurfacedNewestFirst(): void
    {
        $outcomes = [
            new SubmissionOutcome(true, 5, 0, [], null, new \DateTimeImmutable('2026-08-14T08:00:00+00:00')),
            new SubmissionOutcome(
                false,
                0,
                1,
                ['store view not recognised for this install' => 1],
                'boom',
                new \DateTimeImmutable('2026-08-14T07:00:00+00:00')
            ),
        ];

        $submissionOutcomeRepository = $this->createStub(SubmissionOutcomeRepository::class);
        $submissionOutcomeRepository->method('recent')->willReturn($outcomes);

        $snapshot = $this->provider(
            submissionOutcomeRepository: $submissionOutcomeRepository,
        )->snapshot($this->now());

        self::assertSame($outcomes, $snapshot->recentSubmissionOutcomes);
    }

    /**
     * The version-check state is read from local storage, never from a live
     * call, so it is surfaced even on the unconfigured path -- an install
     * that has been self-disabled and then had its credentials cleared must
     * still show WHY it stopped reporting.
     */
    public function testConnectorVersionStateIsSurfacedOnBothTheConfiguredAndUnconfiguredPaths(): void
    {
        $connectorVersionStateRepository = $this->createStub(ConnectorVersionStateRepository::class);
        $connectorVersionStateRepository->method('get')->willReturn(new ConnectorVersionState(
            installedVersion: '1.0.0',
            minimumVersion: '1.2.0',
            latestVersion: '1.2.0',
            belowMinimum: true,
            updateAvailable: true,
            checkedAt: new \DateTimeImmutable('2026-08-14T08:30:00+00:00'),
        ));

        $unconfigured = $this->createStub(Config::class);
        $unconfigured->method('isConfigured')->willReturn(false);

        foreach ([null, $unconfigured] as $config) {
            $snapshot = $this->provider(
                config: $config,
                connectorVersionStateRepository: $connectorVersionStateRepository,
            )->snapshot($this->now());

            self::assertSame('1.0.0', $snapshot->connectorVersion->installedVersion);
            self::assertSame('1.2.0', $snapshot->connectorVersion->minimumVersion);
            self::assertTrue($snapshot->connectorVersion->belowMinimum);
            self::assertTrue($snapshot->connectorVersion->updateAvailable);
            self::assertSame(
                '2026-08-14T08:30:00+00:00',
                $snapshot->connectorVersion->checkedAt?->format(\DateTimeInterface::ATOM)
            );
        }
    }

    private function provider(
        ?Config $config = null,
        ?PingService $pingService = null,
        ?ReportBufferRepository $bufferRepository = null,
        ?EventCounterRepository $eventCounterRepository = null,
        ?HealthStateRepository $healthStateRepository = null,
        ?DispersionStateRepository $dispersionStateRepository = null,
        ?DispersionEvaluator $dispersionEvaluator = null,
        ?SeedCoverageRepository $seedCoverageRepository = null,
        ?IntegrationHealthStateRepository $integrationHealthStateRepository = null,
        ?WatchedIntegrationRepository $watchedIntegrationRepository = null,
        ?StoreManagerInterface $storeManager = null,
        ?SubmissionOutcomeRepository $submissionOutcomeRepository = null,
        ?EnvironmentStateRepository $environmentStateRepository = null,
        ?ConnectorVersionStateRepository $connectorVersionStateRepository = null,
    ): DiagnosticsSnapshotProvider {
        if ($config === null) {
            $config = $this->createStub(Config::class);
            $config->method('isConfigured')->willReturn(true);
            $config->method('baseUrl')->willReturn('https://watchtower.test');
            $config->method('apiKey')->willReturn('test-key');
        }

        if ($pingService === null) {
            $pingService = $this->createStub(PingService::class);
            $pingService->method('ping')->willReturn(new PingResult(reachable: true, httpStatus: 200));
        }

        if ($bufferRepository === null) {
            $bufferRepository = $this->createStub(ReportBufferRepository::class);
            $bufferRepository->method('bufferedCount')->willReturn(0);
            $bufferRepository->method('lastSuccessfulSubmissionAt')->willReturn(null);
        }

        if ($eventCounterRepository === null) {
            $eventCounterRepository = $this->createStub(EventCounterRepository::class);
            $eventCounterRepository->method('totalDroppedInLast24Hours')->willReturn(0);
        }

        if ($healthStateRepository === null) {
            $healthStateRepository = $this->createStub(HealthStateRepository::class);
            $healthStateRepository->method('get')->willReturnCallback(
                fn (string $eventType) => new HealthState($eventType, null, null, null, null, 0)
            );
        }

        if ($dispersionStateRepository === null) {
            $dispersionStateRepository = $this->createStub(DispersionStateRepository::class);
            $dispersionStateRepository->method('get')->willReturnCallback(
                fn (int $storeViewId, string $category) => new DispersionState($storeViewId, $category, null, null, 0)
            );
        }

        if ($dispersionEvaluator === null) {
            $dispersionEvaluator = $this->createStub(DispersionEvaluator::class);
            $dispersionEvaluator->method('estimatedDetectionLatencyHours')->willReturn(null);
        }

        if ($seedCoverageRepository === null) {
            $seedCoverageRepository = $this->createStub(SeedCoverageRepository::class);
            $seedCoverageRepository->method('get')->willReturn(null);
        }

        if ($integrationHealthStateRepository === null) {
            $integrationHealthStateRepository = $this->createStub(IntegrationHealthStateRepository::class);
        }

        if ($watchedIntegrationRepository === null) {
            $watchedIntegrationRepository = $this->createStub(WatchedIntegrationRepository::class);
            $watchedIntegrationRepository->method('hasAnyWatched')->willReturn(false);
        }

        if ($storeManager === null) {
            $storeManager = $this->createStub(StoreManagerInterface::class);
            $storeManager->method('getStores')->willReturn([]);
        }

        if ($submissionOutcomeRepository === null) {
            $submissionOutcomeRepository = $this->createStub(SubmissionOutcomeRepository::class);
            $submissionOutcomeRepository->method('recent')->willReturn([]);
        }

        if ($environmentStateRepository === null) {
            $environmentStateRepository = $this->createStub(EnvironmentStateRepository::class);
            $environmentStateRepository->method('get')->willReturn(new EnvironmentState(
                magentoVersion: null,
                magentoEdition: null,
                connectorVersion: null,
                magentoEol: null,
                syncedAt: null,
            ));
        }

        if ($connectorVersionStateRepository === null) {
            $connectorVersionStateRepository = $this->createStub(ConnectorVersionStateRepository::class);
            $connectorVersionStateRepository->method('get')->willReturn(new ConnectorVersionState(
                installedVersion: null,
                minimumVersion: null,
                latestVersion: null,
                belowMinimum: false,
                updateAvailable: false,
                checkedAt: null,
            ));
        }

        return new DiagnosticsSnapshotProvider(
            $config,
            $pingService,
            $bufferRepository,
            $eventCounterRepository,
            $healthStateRepository,
            $dispersionStateRepository,
            $dispersionEvaluator,
            $seedCoverageRepository,
            $integrationHealthStateRepository,
            $watchedIntegrationRepository,
            new LiveStoreViewResolver($storeManager),
            $submissionOutcomeRepository,
            $environmentStateRepository,
            $connectorVersionStateRepository,
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW_STRING);
    }
}
