<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\ViewModel\IntegrationHealth;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Data\Form\FormKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\CronJobObservation\Cadence;
use Watchtower\Connector\Model\IntegrationHealth\DiscoveredIntegration;
use Watchtower\Connector\Model\IntegrationHealth\DiscoveredJob;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationDiscovery;
use Watchtower\Connector\Model\IntegrationHealth\WatchedIntegrationRepository;
use Watchtower\Connector\ViewModel\IntegrationHealth\CadenceDescriber;
use Watchtower\Connector\ViewModel\IntegrationHealth\Integrations;

/**
 * Modelled on the integrations actually present on the Avalon Guns
 * production store, the same fixtures IntegrationDiscoveryTest uses.
 */
class IntegrationsTest extends TestCase
{
    public function testListsTheMerchantsOwnIntegrationsSeparatelyFromMagentosOwn(): void
    {
        $viewModel = $this->viewModel();

        self::assertSame(
            ['Ebizmarts_MailChimp', ''],
            array_map(static fn ($i) => $i->moduleName, $viewModel->getAddedIntegrations())
        );
        self::assertSame(
            ['Magento_Catalog'],
            array_map(static fn ($i) => $i->moduleName, $viewModel->getMagentoIntegrations())
        );
    }

    /**
     * discover() already ranks third-party first so an ERP sync is not buried
     * under core housekeeping. Re-sorting here would silently undo that.
     */
    public function testPreservesDiscoveryOrderWithinEachGroup(): void
    {
        $integrations = [
            $this->integration('B_Later', isThirdParty: true),
            $this->integration('A_Earlier', isThirdParty: true),
        ];

        $viewModel = $this->viewModel($integrations);

        self::assertSame(
            ['B_Later', 'A_Earlier'],
            array_map(static fn ($i) => $i->moduleName, $viewModel->getAddedIntegrations())
        );
    }

    public function testReflectsTheStoredWatchedSet(): void
    {
        $viewModel = $this->viewModel(watchedModules: ['Ebizmarts_MailChimp'], watchedJobCodes: ['avalon_conditions']);

        $mailchimp = $viewModel->getAddedIntegrations()[0];
        $unattributed = $viewModel->getAddedIntegrations()[1];

        self::assertTrue($viewModel->isWatched($mailchimp));
        self::assertFalse($viewModel->isWatched($viewModel->getMagentoIntegrations()[0]));
        self::assertTrue($viewModel->isJobWatched($unattributed->jobs[0]));
        self::assertFalse($viewModel->isJobWatched($mailchimp->jobs[0]));
    }

    /**
     * The unattributed bucket is not one integration, it is everything no
     * installed module claims, so there is no module name to store for it and
     * nothing a merchant would recognize as "all of it".
     */
    public function testOffersTheUnattributedBucketOnlyAsIndividualJobs(): void
    {
        $viewModel = $this->viewModel();
        [$mailchimp, $unattributed] = $viewModel->getAddedIntegrations();

        self::assertTrue($viewModel->isSelectableAsWhole($mailchimp));
        self::assertFalse($viewModel->isSelectableAsWhole($unattributed));
        self::assertTrue($viewModel->isDetailOpen($unattributed));
    }

    public function testExpandsTheJobListWhenAnIndividualJobIsWatched(): void
    {
        $viewModel = $this->viewModel(watchedJobCodes: ['ebizmarts_clean_batches']);

        self::assertTrue($viewModel->isDetailOpen($viewModel->getAddedIntegrations()[0]));
    }

    public function testKeepsTheJobListCollapsedWhenNothingInsideIsPickedIndividually(): void
    {
        $viewModel = $this->viewModel(watchedModules: ['Ebizmarts_MailChimp']);

        self::assertFalse($viewModel->isDetailOpen($viewModel->getAddedIntegrations()[0]));
    }

    public function testExpandsMagentosOwnJobsOnlyWhenSomethingInThereIsWatched(): void
    {
        self::assertFalse($this->viewModel()->isMagentoSectionOpen());
        self::assertTrue($this->viewModel(watchedModules: ['Magento_Catalog'])->isMagentoSectionOpen());
    }

    /**
     * @param string[] $consumers
     * @param int $jobCount
     * @param string $expected
     */
    #[DataProvider('contentsProvider')]
    public function testSummarizesWhatAnIntegrationContains(array $consumers, int $jobCount, string $expected): void
    {
        $jobs = [];

        for ($i = 0; $i < $jobCount; $i++) {
            $jobs[] = new DiscoveredJob('job_' . $i, '*/5 * * * *', $this->confidentCadence());
        }

        $integration = $this->integration('Vendor_Module', jobs: $jobs, consumerNames: $consumers);

        self::assertSame($expected, $this->viewModel([$integration])->getContentsSummary($integration));
    }

    /**
     * @return array<string, array{string[], int, string}>
     */
    public static function contentsProvider(): array
    {
        return [
            'one job' => [[], 1, '1 scheduled job'],
            'several jobs' => [[], 4, '4 scheduled jobs'],
            'jobs and one consumer' => [['a.topic'], 2, '2 scheduled jobs, 1 queue consumer'],
            'consumers only' => [['a.topic', 'b.topic'], 0, '2 queue consumers'],
        ];
    }

    public function testTellsTheMerchantAnIntegrationIsStillBeingMeasuredWithoutBlockingSelection(): void
    {
        $integration = $this->integration('Avalon_ConditionsCron', jobs: [
            new DiscoveredJob('avalon_conditions', '*/15 * * * *', $this->learningCadence()),
        ]);

        $notes = array_map('strval', $this->viewModel([$integration])->getNotes($integration));

        self::assertSame(
            ['Learning cadence. You can select it now and we will start alerting once we know it.'],
            $notes
        );
    }

    public function testWarnsThatAnErraticIntegrationMayAlertUnreliably(): void
    {
        $integration = $this->integration('Salesfire_Salesfire', jobs: [
            new DiscoveredJob('salesfire_sync', '*/5 * * * *', $this->confidentCadence(regular: false)),
        ]);

        $notes = array_map('strval', $this->viewModel([$integration])->getNotes($integration));

        self::assertSame(['Runs irregularly, alerting may be unreliable.'], $notes);
    }

    public function testAnnotatesEachJobWithItsMeasuredCadence(): void
    {
        $viewModel = $this->viewModel();
        $job = $viewModel->getAddedIntegrations()[0]->jobs[0];

        self::assertSame('every 5 min (observed, 240 runs)', (string) $viewModel->getCadenceLabel($job));
        self::assertNull($viewModel->getJobWarning($job));
    }

    public function testNamesTheConsumersWatchedAlongsideAnIntegration(): void
    {
        $integration = $this->integration('Vendor_Module', consumerNames: ['a.topic', 'b.topic']);

        self::assertSame('a.topic, b.topic', $this->viewModel([$integration])->getConsumerList($integration));
    }

    /**
     * Discovery walks every declared cron job and consumer, so a render that
     * asks for both groups plus per-entry state must not repeat it.
     */
    public function testDiscoversOncePerRender(): void
    {
        $discovery = $this->createMock(IntegrationDiscovery::class);
        $discovery->expects(self::once())->method('discover')->willReturn($this->fixtures());

        $viewModel = new Integrations(
            $discovery,
            $this->watchedRepository(),
            new CadenceDescriber(),
            $this->createStub(UrlInterface::class),
            $this->createStub(FormKey::class)
        );

        $viewModel->getAddedIntegrations();
        $viewModel->getMagentoIntegrations();
        $viewModel->isMagentoSectionOpen();
    }

    /**
     * @param DiscoveredIntegration[]|null $integrations
     * @param string[] $watchedModules
     * @param string[] $watchedJobCodes
     * @return Integrations
     */
    private function viewModel(
        ?array $integrations = null,
        array $watchedModules = [],
        array $watchedJobCodes = []
    ): Integrations {
        $discovery = $this->createStub(IntegrationDiscovery::class);
        $discovery->method('discover')->willReturn($integrations ?? $this->fixtures());

        return new Integrations(
            $discovery,
            $this->watchedRepository($watchedModules, $watchedJobCodes),
            new CadenceDescriber(),
            $this->createStub(UrlInterface::class),
            $this->createStub(FormKey::class)
        );
    }

    /**
     * @param string[] $modules
     * @param string[] $jobCodes
     * @return WatchedIntegrationRepository
     */
    private function watchedRepository(array $modules = [], array $jobCodes = []): WatchedIntegrationRepository
    {
        $repository = $this->createStub(WatchedIntegrationRepository::class);
        $repository->method('watchedModules')->willReturn($modules);
        $repository->method('watchedJobCodes')->willReturn($jobCodes);

        return $repository;
    }

    /**
     * A third-party extension, the unattributed bucket, and a core module,
     * in the order discover() returns them.
     *
     * @return DiscoveredIntegration[]
     */
    private function fixtures(): array
    {
        return [
            $this->integration('Ebizmarts_MailChimp', vendorLabel: 'Mailchimp', jobs: [
                new DiscoveredJob('ebizmarts_ecommerce', '*/5 * * * *', $this->confidentCadence()),
                new DiscoveredJob('ebizmarts_clean_batches', '0 * * * *', $this->confidentCadence(3600)),
            ], consumerNames: ['ebizmarts.sync']),
            $this->integration(IntegrationDiscovery::UNATTRIBUTED_MODULE, vendorLabel: 'Other scheduled jobs', jobs: [
                new DiscoveredJob('avalon_conditions', null, $this->learningCadence()),
            ]),
            $this->integration('Magento_Catalog', vendorLabel: 'Magento', isThirdParty: false, jobs: [
                new DiscoveredJob('catalog_index_refresh_price', '0 * * * *', $this->confidentCadence(3600)),
            ]),
        ];
    }

    /**
     * @param string $moduleName
     * @param string $vendorLabel
     * @param bool $isThirdParty
     * @param DiscoveredJob[] $jobs
     * @param string[] $consumerNames
     * @return DiscoveredIntegration
     */
    private function integration(
        string $moduleName,
        string $vendorLabel = 'Vendor',
        bool $isThirdParty = true,
        array $jobs = [],
        array $consumerNames = []
    ): DiscoveredIntegration {
        return new DiscoveredIntegration(
            moduleName: $moduleName,
            vendorLabel: $vendorLabel,
            packageName: null,
            isThirdParty: $isThirdParty,
            jobs: $jobs,
            consumerNames: $consumerNames,
        );
    }

    /**
     * @param int $periodSeconds
     * @param bool $regular
     * @return Cadence
     */
    private function confidentCadence(int $periodSeconds = 300, bool $regular = true): Cadence
    {
        return new Cadence(
            periodSeconds: $periodSeconds,
            thresholdSeconds: 3600,
            isConfident: true,
            isRegular: $regular,
            sampleCount: 20,
            observedRunCount: 240,
        );
    }

    /**
     * @return Cadence
     */
    private function learningCadence(): Cadence
    {
        return new Cadence(
            periodSeconds: null,
            thresholdSeconds: null,
            isConfident: false,
            isRegular: false,
            sampleCount: 1,
            observedRunCount: 2,
        );
    }
}
