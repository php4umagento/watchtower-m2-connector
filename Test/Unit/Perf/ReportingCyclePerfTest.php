<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Perf;

use Magento\Store\Model\Group;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Api\ConnectorVersionCheckResult;
use Watchtower\Connector\Model\Api\ConnectorVersionCheckService;
use Watchtower\Connector\Model\Api\MetricReport;
use Watchtower\Connector\Model\Api\MetricsSubmissionResult;
use Watchtower\Connector\Model\Api\MetricsSubmissionService;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Buffer\ReportBufferRepository;
use Watchtower\Connector\Model\Config;
use Watchtower\Connector\Model\CronHealth\Evaluator;
use Watchtower\Connector\Model\Diagnostics\SubmissionOutcomeRepository;
use Watchtower\Connector\Model\Environment\ConnectorVersionState;
use Watchtower\Connector\Model\Environment\ConnectorVersionStateRepository;
use Watchtower\Connector\Model\IntegrationHealth\ConventionEventReader;
use Watchtower\Connector\Model\IntegrationHealth\CronJobObserver;
use Watchtower\Connector\Model\IntegrationHealth\Evaluator as IntegrationHealthEvaluator;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfigRepository;
use Watchtower\Connector\Model\IntegrationHealth\QueueConsumerObserver;
use Watchtower\Connector\Model\Organization\OrganizationStateRepository;
use Watchtower\Connector\Model\RateSignal\DispersionEvaluator;
use Watchtower\Connector\Model\ReportingService;
use Watchtower\Connector\Model\Rollup\RollupRepository;
use Watchtower\Connector\Model\Seed\HistorySeeder;
use Watchtower\Connector\Model\Seed\SeedCoverageRepository;
use Watchtower\Connector\Model\Signal\BasketQuoteReader;
use Watchtower\Connector\Model\Signal\CheckoutReader;
use Watchtower\Connector\Model\Signal\CustomerAccountRegistrationReader;
use Watchtower\Connector\Model\StoreView\LiveStoreViewResolver;

/**
 * A bounded execution-time/call-count smoke test for a representative
 * multi-store-view reporting cycle. Not a benchmark suite
 * (no assertion here claims a specific throughput number, which would be
 * both environment-dependent and a maintenance burden) -- it exists to
 * catch a genuine class of regression none of ReportingServiceTest's own
 * behavioral tests would notice: an accidental O(n^2) loop (e.g.
 * liveStoreViewResolver()->all() re-fetched per store view instead of
 * once) or a runaway per-store-view call count, either of which would
 * still pass every existing correctness assertion while silently making
 * every real reporting cycle on a multi-store-view install far slower.
 */
class ReportingCyclePerfTest extends TestCase
{
    private const STORE_VIEW_COUNT = 300;

    /**
     * The 3 rate-based readers (basket_quote/checkout/customer_account)
     * plus RollupRepository::recordHourlyCount() plus
     * DispersionEvaluator::evaluate() must each be called exactly once per
     * category per store view -- STORE_VIEW_COUNT * 3, no more. A quadratic
     * regression (e.g. re-iterating live store views inside the per-
     * category loop) would multiply this well past the expected count
     * while every existing pass/fail-shaped ReportingServiceTest assertion
     * stays green.
     */
    public function testPerCategoryDependenciesAreCalledExactlyOncePerStoreViewPerCategory(): void
    {
        $expectedCalls = self::STORE_VIEW_COUNT * 3;

        $basketQuoteReader = $this->createMock(BasketQuoteReader::class);
        $basketQuoteReader->expects(self::exactly(self::STORE_VIEW_COUNT))
            ->method('countForWindow')->willReturn(0);

        $checkoutReader = $this->createMock(CheckoutReader::class);
        $checkoutReader->expects(self::exactly(self::STORE_VIEW_COUNT))
            ->method('countForWindow')->willReturn(0);

        $customerAccountReader = $this->createMock(CustomerAccountRegistrationReader::class);
        $customerAccountReader->expects(self::exactly(self::STORE_VIEW_COUNT))
            ->method('countForWindow')->willReturn(0);

        $rollupRepository = $this->createMock(RollupRepository::class);
        $rollupRepository->expects(self::exactly($expectedCalls))->method('recordHourlyCount');

        $dispersionEvaluator = $this->createMock(DispersionEvaluator::class);
        $dispersionEvaluator->expects(self::exactly($expectedCalls))
            ->method('evaluate')
            ->willReturn($this->storeViewReport());

        $this->service(
            storeManager: $this->storeManagerWith(self::STORE_VIEW_COUNT),
            basketQuoteReader: $basketQuoteReader,
            checkoutReader: $checkoutReader,
            customerAccountReader: $customerAccountReader,
            rollupRepository: $rollupRepository,
            dispersionEvaluator: $dispersionEvaluator,
        )->run();
    }

    /**
     * StoreManagerInterface::getStores() (the underlying data source
     * LiveStoreViewResolver::all() wraps) must be fetched exactly once for
     * the whole cycle, never once per store view -- the exact shape of
     * regression a naive per-store-view "re-resolve live store views"
     * call would introduce.
     */
    public function testLiveStoreViewsAreResolvedExactlyOnceRegardlessOfStoreViewCount(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects(self::once())
            ->method('getStores')
            ->willReturn($this->buildStores(self::STORE_VIEW_COUNT));

        $this->service(storeManager: $storeManager)->run();
    }

    /**
     * IntegrationHealthConfigRepository::get() (the per-store-view gate
     * deciding whether a 4th, integration_health report is produced) must
     * be called exactly once per store view -- not skipped, and not
     * called redundantly within the same store view's own iteration.
     */
    public function testIntegrationHealthConfigIsCheckedExactlyOncePerStoreView(): void
    {
        $integrationHealthConfigRepository = $this->createMock(IntegrationHealthConfigRepository::class);
        $integrationHealthConfigRepository->expects(self::exactly(self::STORE_VIEW_COUNT))
            ->method('get')
            ->willReturn(null);

        $this->service(
            storeManager: $this->storeManagerWith(self::STORE_VIEW_COUNT),
            integrationHealthConfigRepository: $integrationHealthConfigRepository,
        )->run();
    }

    /**
     * A generous wall-clock ceiling for a fully-mocked (no real I/O)
     * multi-store-view cycle -- not a throughput claim, just a smoke test
     * against a pathological O(n^2)/accidental-sleep regression. Every
     * dependency here is a stub returning instantly, so even a few hundred
     * milliseconds for STORE_VIEW_COUNT stores would already indicate
     * something is quadratic; the threshold is set an order of magnitude
     * above that to stay robust against ordinary CI/container jitter.
     */
    public function testAFullMultiStoreViewCycleCompletesWellUnderASecond(): void
    {
        $start = microtime(true);

        $this->service(storeManager: $this->storeManagerWith(self::STORE_VIEW_COUNT))->run();

        $elapsedSeconds = microtime(true) - $start;

        self::assertLessThan(1.0, $elapsedSeconds, sprintf(
            'Expected a %d-store-view reporting cycle against fully-stubbed dependencies to complete in '
            . 'well under a second; took %.3fs -- possible O(n^2) regression.',
            self::STORE_VIEW_COUNT,
            $elapsedSeconds
        ));
    }

    private function storeManagerWith(int $count): StoreManagerInterface
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($this->buildStores($count));

        return $storeManager;
    }

    /**
     * @param int $count
     * @return Store[]
     */
    private function buildStores(int $count): array
    {
        $website = $this->createStub(Website::class);
        $website->method('getName')->willReturn('Main Website');

        $group = $this->createStub(Group::class);
        $group->method('getName')->willReturn('Main Website Store');

        $stores = [];
        for ($i = 1; $i <= $count; $i++) {
            $store = $this->createStub(Store::class);
            $store->method('getId')->willReturn($i);
            $store->method('getCode')->willReturn('store_view_' . $i);
            $store->method('getName')->willReturn('Store View ' . $i);
            $store->method('getBaseUrl')->willReturn('https://m2.test/');
            $store->method('getIsActive')->willReturn(true);
            $store->method('getWebsite')->willReturn($website);
            $store->method('getGroup')->willReturn($group);
            $stores[] = $store;
        }

        return $stores;
    }

    private function storeViewReport(): MetricReport
    {
        return new MetricReport(
            storeViewCode: 'store_view_1',
            eventType: 'checkout',
            status: SignalStatus::InsufficientData,
            sequenceNumber: 1,
            evaluatedAt: new \DateTimeImmutable('2026-08-14T09:00:00+00:00'),
            reason: ReportReason::Transition,
            rulesetVersion: DispersionEvaluator::RULESET_VERSION,
        );
    }

    private function service(
        StoreManagerInterface $storeManager,
        ?BasketQuoteReader $basketQuoteReader = null,
        ?CheckoutReader $checkoutReader = null,
        ?CustomerAccountRegistrationReader $customerAccountReader = null,
        ?RollupRepository $rollupRepository = null,
        ?DispersionEvaluator $dispersionEvaluator = null,
        ?IntegrationHealthConfigRepository $integrationHealthConfigRepository = null,
        ?HistorySeeder $historySeeder = null,
    ): ReportingService {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn(true);
        $config->method('isEnabled')->willReturn(true);
        $config->method('baseUrl')->willReturn('https://watchtower.test');
        $config->method('apiKey')->willReturn('secret-api-key-value');

        $cronHealthEvaluator = $this->createStub(Evaluator::class);
        $cronHealthEvaluator->method('evaluate')->willReturn(new MetricReport(
            storeViewCode: null,
            eventType: Evaluator::EVENT_TYPE,
            status: SignalStatus::Normal,
            sequenceNumber: 1,
            evaluatedAt: new \DateTimeImmutable('2026-08-14T09:00:00+00:00'),
            reason: ReportReason::Heartbeat,
            rulesetVersion: Evaluator::RULESET_VERSION,
        ));

        $bufferRepository = $this->createStub(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        // A perf smoke test cares about per-store-view call volume, not
        // submission outcome, so the cheapest correct path is "nothing is
        // due yet" -- every fresh report is buffered, no HTTP submission
        // attempted, keeping every stub above purely about the evaluation
        // loop itself.
        $bufferRepository->method('isDue')->willReturn(false);
        $bufferRepository->method('bufferReport')->willReturn(0);

        $integrationHealthEvaluator = $this->createStub(IntegrationHealthEvaluator::class);
        $integrationHealthEvaluator->method('heartbeatRetiredIfPreviouslyReported')->willReturn(null);

        if ($rollupRepository === null) {
            $rollupRepository = $this->createStub(RollupRepository::class);
            // Already-seeded by default, so seedIfNeverSeeded() is a no-op --
            // this suite measures the ordinary evaluation loop's call volume,
            // not the one-time seed path.
            $rollupRepository->method('hasAnyHourlyDataForCategories')->willReturn(true);
        }

        return new ReportingService(
            $config,
            $cronHealthEvaluator,
            $this->createStub(MetricsSubmissionService::class),
            $bufferRepository,
            new LiveStoreViewResolver($storeManager),
            $basketQuoteReader ?? $this->stubBasketQuoteReader(),
            $checkoutReader ?? $this->stubCheckoutReader(),
            $customerAccountReader ?? $this->stubCustomerAccountReader(),
            $rollupRepository,
            $dispersionEvaluator ?? $this->stubDispersionEvaluator(),
            $historySeeder ?? $this->createStub(HistorySeeder::class),
            $this->createStub(SeedCoverageRepository::class),
            $integrationHealthConfigRepository ?? $this->stubIntegrationHealthConfigRepository(),
            $integrationHealthEvaluator,
            $this->createStub(CronJobObserver::class),
            $this->createStub(QueueConsumerObserver::class),
            $this->createStub(ConventionEventReader::class),
            $this->stubOrganizationStateRepository(),
            $this->createStub(LoggerInterface::class),
            $this->createStub(SubmissionOutcomeRepository::class),
            $this->stubConnectorVersionCheckService(),
            $this->stubConnectorVersionStateRepository(),
        );
    }

    private function stubBasketQuoteReader(): BasketQuoteReader
    {
        $reader = $this->createStub(BasketQuoteReader::class);
        $reader->method('countForWindow')->willReturn(0);

        return $reader;
    }

    private function stubCheckoutReader(): CheckoutReader
    {
        $reader = $this->createStub(CheckoutReader::class);
        $reader->method('countForWindow')->willReturn(0);

        return $reader;
    }

    private function stubCustomerAccountReader(): CustomerAccountRegistrationReader
    {
        $reader = $this->createStub(CustomerAccountRegistrationReader::class);
        $reader->method('countForWindow')->willReturn(0);

        return $reader;
    }

    private function stubDispersionEvaluator(): DispersionEvaluator
    {
        $evaluator = $this->createStub(DispersionEvaluator::class);
        $evaluator->method('evaluate')->willReturn($this->storeViewReport());

        return $evaluator;
    }

    private function stubIntegrationHealthConfigRepository(): IntegrationHealthConfigRepository
    {
        $repository = $this->createStub(IntegrationHealthConfigRepository::class);
        $repository->method('get')->willReturn(null);

        return $repository;
    }

    private function stubOrganizationStateRepository(): OrganizationStateRepository
    {
        $repository = $this->createStub(OrganizationStateRepository::class);
        $repository->method('isPaused')->willReturn(false);

        return $repository;
    }

    private function stubConnectorVersionCheckService(): ConnectorVersionCheckService
    {
        $service = $this->createStub(ConnectorVersionCheckService::class);
        $service->method('check')->willReturn(new ConnectorVersionCheckResult(
            succeeded: true,
            installedVersion: '1.2.0',
            minimumVersion: '1.0.0',
            latestVersion: '1.2.0',
        ));

        return $service;
    }

    private function stubConnectorVersionStateRepository(): ConnectorVersionStateRepository
    {
        $repository = $this->createStub(ConnectorVersionStateRepository::class);
        $repository->method('get')->willReturn(new ConnectorVersionState(
            installedVersion: '1.2.0',
            minimumVersion: '1.0.0',
            latestVersion: '1.2.0',
            belowMinimum: false,
            updateAvailable: false,
            checkedAt: new \DateTimeImmutable('2026-08-14T09:00:00+00:00'),
        ));

        return $repository;
    }
}
