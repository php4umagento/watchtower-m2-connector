<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\IntegrationHealth;

use Magento\Cron\Model\ConfigInterface as CronConfigInterface;
use Magento\Framework\MessageQueue\Consumer\Config\ConsumerConfigItem\HandlerInterface;
use Magento\Framework\MessageQueue\Consumer\Config\ConsumerConfigItemInterface;
use Magento\Framework\MessageQueue\Consumer\ConfigInterface as ConsumerConfigInterface;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\CronJobObservation\CadenceEstimator;
use Watchtower\Connector\Model\CronJobObservation\JobRunObservation;
use Watchtower\Connector\Model\CronJobObservation\JobRunObservationRepository;
use Watchtower\Connector\Model\IntegrationHealth\DiscoveredIntegration;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationDiscovery;
use Watchtower\Connector\Model\IntegrationHealth\ModuleAttribution;

/**
 * Modelled on the integrations actually present on the Avalon Guns
 * production store: Mailchimp shipping four jobs whose codes say ebizmarts,
 * M2E Pro shipping one, and a bespoke Avalon feed.
 */
class IntegrationDiscoveryTest extends TestCase
{
    private const JOBS = [
        'default' => [
            'ebizmarts_ecommerce' => ['instance' => 'Ebizmarts\MailChimp\Cron\Sync', 'schedule' => '*/5 * * * *'],
            'ebizmarts_clean_batches' => ['instance' => 'Ebizmarts\MailChimp\Cron\Clean', 'schedule' => '0 * * * *'],
            'catalog_index_refresh_price' => ['instance' => 'Magento\Catalog\Cron\Refresh', 'schedule' => '0 * * * *'],
            'watchtower_report' => ['instance' => 'Watchtower\Connector\Cron\ReportJob', 'schedule' => '*/5 * * * *'],
            // A crontab.xml <config_path> naming a different job code than its
            // own <job name> makes Magento mint a second, class-less entry.
            'catalog_product_alert' => ['schedule' => '0 * * * *'],
        ],
    ];

    private const MODULE_FOR_CLASS = [
        'Ebizmarts\MailChimp\Cron\Sync' => 'Ebizmarts_MailChimp',
        'Ebizmarts\MailChimp\Cron\Clean' => 'Ebizmarts_MailChimp',
        'Magento\Catalog\Cron\Refresh' => 'Magento_Catalog',
        'Magento\Catalog\Model\Attribute\Backend\Consumer' => 'Magento_Catalog',
        'Ebizmarts\MailChimp\Model\Consumer' => 'Ebizmarts_MailChimp',
    ];

    public function testGroupsJobsUnderTheirDeclaringModule(): void
    {
        $mailchimp = $this->integrationFor($this->discover(), 'Ebizmarts_MailChimp');

        self::assertSame('Mailchimp', $mailchimp->vendorLabel);
        self::assertSame('mailchimp/mc-magento2', $mailchimp->packageName);
        self::assertSame(
            ['ebizmarts_clean_batches', 'ebizmarts_ecommerce'],
            array_map(static fn ($j) => $j->jobCode, $mailchimp->jobs)
        );
    }

    /**
     * A declared job with no instance class cannot run, so offering it hands
     * the merchant something that can only ever look broken. On a real store
     * this produced catalog_product_alert, which Magento schedules and then
     * fails every time, and magedelight_facebook, which never ran at all.
     */
    public function testExcludesDeclaredJobsThatHaveNoInstanceClass(): void
    {
        foreach ($this->discover() as $integration) {
            foreach ($integration->jobs as $job) {
                self::assertNotSame('catalog_product_alert', $job->jobCode);
            }
        }
    }

    /**
     * Watching the job that reports the signal as an integration would be
     * circular, so it is never offered.
     */
    public function testExcludesTheConnectorsOwnJobs(): void
    {
        foreach ($this->discover() as $integration) {
            foreach ($integration->jobs as $job) {
                self::assertStringStartsNotWith('watchtower_', $job->jobCode);
            }
        }
    }

    /**
     * A merchant hunting their ERP sync should not have to scroll past sixty
     * core housekeeping jobs to reach it.
     */
    public function testRanksThirdPartyIntegrationsAboveMagentoCore(): void
    {
        $discovered = $this->discover();

        self::assertTrue($discovered[0]->isThirdParty);
        self::assertSame('Ebizmarts_MailChimp', $discovered[0]->moduleName);
        self::assertFalse($discovered[array_key_last($discovered)]->isThirdParty);
    }

    /**
     * Jobs inserted straight into cron_schedule appear in no crontab.xml, so
     * they have no instance class to attribute through. They are still
     * offered, because a bespoke integration is exactly what gets scheduled
     * that way. The observation table is the source rather than cron_schedule
     * because it survives Magento's hourly purge of succeeded rows.
     */
    public function testOffersUndeclaredJobsSeenRunningInTheObservationTable(): void
    {
        $discovered = $this->discover(['avalon_conditions' => $this->observation('avalon_conditions', 900)]);
        $unattributed = $this->integrationFor($discovered, IntegrationDiscovery::UNATTRIBUTED_MODULE);

        self::assertSame(['avalon_conditions'], array_map(static fn ($j) => $j->jobCode, $unattributed->jobs));
        self::assertNull($unattributed->jobs[0]->declaredSchedule);
        // Not part of stock Magento, so it ranks with the merchant's own things.
        self::assertTrue($unattributed->isThirdParty);
    }

    public function testAttributesConsumersThroughTheirHandlerClass(): void
    {
        $mailchimp = $this->integrationFor($this->discover(), 'Ebizmarts_MailChimp');

        self::assertSame(['mailchimp.sync'], $mailchimp->consumerNames);
    }

    public function testAModuleWithOnlyConsumersAndNoCronJobsStillAppears(): void
    {
        $discovered = $this->discover();
        $catalog = $this->integrationFor($discovered, 'Magento_Catalog');

        self::assertSame(['catalog.attribute.update'], $catalog->consumerNames);
    }

    public function testAnnotatesEachJobWithItsMeasuredCadence(): void
    {
        $discovered = $this->discover([
            'ebizmarts_ecommerce' => $this->observation('ebizmarts_ecommerce', 300),
        ]);
        $mailchimp = $this->integrationFor($discovered, 'Ebizmarts_MailChimp');
        $jobs = [];

        foreach ($mailchimp->jobs as $job) {
            $jobs[$job->jobCode] = $job;
        }

        self::assertTrue($jobs['ebizmarts_ecommerce']->cadence->isConfident);
        self::assertSame(300, $jobs['ebizmarts_ecommerce']->cadence->periodSeconds);
        self::assertTrue($mailchimp->hasConfidentCadence());

        // The other job has never been seen run, so it stays in the learning
        // state rather than inheriting its sibling's cadence.
        self::assertFalse($jobs['ebizmarts_clean_batches']->cadence->isConfident);
    }

    public function testKeepsTheDeclaredScheduleAlongsideTheMeasuredOne(): void
    {
        $mailchimp = $this->integrationFor($this->discover(), 'Ebizmarts_MailChimp');
        $schedules = [];

        foreach ($mailchimp->jobs as $job) {
            $schedules[$job->jobCode] = $job->declaredSchedule;
        }

        self::assertSame('*/5 * * * *', $schedules['ebizmarts_ecommerce']);
    }

    /**
     * Runs discovery over the fixed fake install.
     *
     * @param array<string,JobRunObservation> $observations
     * @return DiscoveredIntegration[]
     */
    private function discover(array $observations = []): array
    {
        $cronConfig = $this->createStub(CronConfigInterface::class);
        $cronConfig->method('getJobs')->willReturn(self::JOBS);

        $consumerConfig = $this->createStub(ConsumerConfigInterface::class);
        $consumerConfig->method('getConsumers')->willReturn([
            $this->consumer('mailchimp.sync', 'Ebizmarts\MailChimp\Model\Consumer'),
            $this->consumer('catalog.attribute.update', 'Magento\Catalog\Model\Attribute\Backend\Consumer'),
            // No handler resolves to a module, so it is skipped rather than
            // inventing an integration nobody could recognize.
            $this->consumer('orphan.consumer', 'Some\Unregistered\Handler'),
        ]);

        $repository = $this->createStub(JobRunObservationRepository::class);
        $repository->method('getAll')->willReturn($observations);

        return (new IntegrationDiscovery(
            $cronConfig,
            $consumerConfig,
            $this->attribution(),
            $repository,
            new CadenceEstimator()
        ))->discover();
    }

    /**
     * A stubbed attribution over the fake install's modules.
     *
     * @return ModuleAttribution
     */
    private function attribution(): ModuleAttribution
    {
        $attribution = $this->createStub(ModuleAttribution::class);
        $attribution->method('moduleForClass')->willReturnCallback(
            static fn (string $class): ?string => self::MODULE_FOR_CLASS[$class] ?? null
        );
        $attribution->method('packageFor')->willReturnCallback(
            static fn (string $module): ?string => $module === 'Ebizmarts_MailChimp' ? 'mailchimp/mc-magento2' : null
        );
        $attribution->method('vendorLabelFor')->willReturnCallback(
            static fn (string $module): string => $module === 'Ebizmarts_MailChimp' ? 'Mailchimp' : 'Magento'
        );
        $attribution->method('isMagentoCore')->willReturnCallback(
            static fn (string $module): bool => str_starts_with($module, 'Magento_')
        );

        return $attribution;
    }

    /**
     * One declared consumer with a single handler.
     *
     * @param string $name
     * @param string $handlerType
     * @return ConsumerConfigItemInterface
     */
    private function consumer(string $name, string $handlerType): ConsumerConfigItemInterface
    {
        $handler = $this->createStub(HandlerInterface::class);
        $handler->method('getType')->willReturn($handlerType);

        $consumer = $this->createStub(ConsumerConfigItemInterface::class);
        $consumer->method('getName')->willReturn($name);
        $consumer->method('getHandlers')->willReturn([$handler]);

        return $consumer;
    }

    /**
     * An observation with enough tight gaps to yield a confident cadence.
     *
     * @param string $jobCode
     * @param int $gapSeconds
     * @return JobRunObservation
     */
    private function observation(string $jobCode, int $gapSeconds): JobRunObservation
    {
        return new JobRunObservation(
            jobCode: $jobCode,
            firstObservedAt: new \DateTimeImmutable('2026-08-13T10:00:00+00:00'),
            lastSuccessAt: new \DateTimeImmutable('2026-08-13T15:00:00+00:00'),
            observedRunCount: 9,
            gapSamples: array_fill(0, 8, $gapSeconds),
        );
    }

    /**
     * Picks one discovered integration out by module name.
     *
     * @param DiscoveredIntegration[] $discovered
     * @param string $moduleName
     * @return DiscoveredIntegration
     */
    private function integrationFor(array $discovered, string $moduleName): DiscoveredIntegration
    {
        foreach ($discovered as $integration) {
            if ($integration->moduleName === $moduleName) {
                return $integration;
            }
        }

        self::fail(sprintf('No integration discovered for module "%s".', $moduleName));
    }
}
