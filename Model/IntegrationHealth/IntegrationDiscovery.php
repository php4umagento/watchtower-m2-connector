<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

use Magento\Cron\Model\ConfigInterface as CronConfigInterface;
use Magento\Framework\MessageQueue\Consumer\ConfigInterface as ConsumerConfigInterface;
use Watchtower\Connector\Model\CronJobObservation\CadenceEstimator;
use Watchtower\Connector\Model\CronJobObservation\JobRunObservationRepository;

/**
 * Builds the list of integrations a merchant can choose to watch, grouping
 * what this install schedules and consumes under the module that ships it.
 *
 * Replaces a picker of 64 bare job codes in which the vendor's name often did
 * not appear at all. See docs/integration-health-redesign.md.
 */
class IntegrationDiscovery
{
    /**
     * Bucket for jobs seen running that no module declares. Still offered: a
     * bespoke integration is exactly what gets scheduled that way.
     */
    public const UNATTRIBUTED_MODULE = '';

    /**
     * @param CronConfigInterface $cronConfig
     * @param ConsumerConfigInterface $consumerConfig
     * @param ModuleAttribution $moduleAttribution
     * @param JobRunObservationRepository $observationRepository
     * @param CadenceEstimator $cadenceEstimator
     */
    public function __construct(
        private readonly CronConfigInterface $cronConfig,
        private readonly ConsumerConfigInterface $consumerConfig,
        private readonly ModuleAttribution $moduleAttribution,
        private readonly JobRunObservationRepository $observationRepository,
        private readonly CadenceEstimator $cadenceEstimator
    ) {
    }

    /**
     * Every integration this install offers, third-party first.
     *
     * @return DiscoveredIntegration[]
     */
    public function discover(): array
    {
        $observations = $this->observationRepository->getAll();

        $jobsByModule = $this->collectJobs($observations);
        $consumersByModule = $this->collectConsumers();

        $integrations = [];

        foreach (array_keys($jobsByModule + $consumersByModule) as $module) {
            $integrations[] = $this->build(
                (string) $module,
                $jobsByModule[$module] ?? [],
                $consumersByModule[$module] ?? []
            );
        }

        usort($integrations, function (DiscoveredIntegration $a, DiscoveredIntegration $b): int {
            // Third-party first: a merchant hunting their ERP sync should not
            // have to scroll past 60 core housekeeping jobs to reach it. The
            // unattributed bucket sorts last within its group rather than
            // alphabetically, where it landed mid-list between real vendors.
            $aLast = $a->moduleName === self::UNATTRIBUTED_MODULE;
            $bLast = $b->moduleName === self::UNATTRIBUTED_MODULE;

            return [$b->isThirdParty, $aLast, $a->displayName, $a->moduleName]
                <=> [$a->isThirdParty, $bLast, $b->displayName, $b->moduleName];
        });

        return $integrations;
    }

    /**
     * Groups every selectable cron job under its declaring module.
     *
     * @param array<string,\Watchtower\Connector\Model\CronJobObservation\JobRunObservation> $observations
     * @return array<string,DiscoveredJob[]>
     */
    private function collectJobs(array $observations): array
    {
        $byModule = [];
        $declared = [];

        foreach ($this->cronConfig->getJobs() as $jobs) {
            foreach ($jobs as $jobCode => $config) {
                $jobCode = (string) $jobCode;
                $declared[$jobCode] = true;

                if ($this->isOwnJob($jobCode)) {
                    continue;
                }

                $instance = is_array($config) ? (string) ($config['instance'] ?? '') : '';

                // A declared job with no instance class cannot be executed, so
                // it is never a usable signal. These are not hand-written: a
                // crontab.xml <config_path> pointing at a different job code
                // than its own <job name> makes Magento's DB config reader
                // mint a second, class-less entry. On a real store that
                // produced catalog_product_alert, which Magento schedules and
                // then fails every single time, and magedelight_facebook,
                // which has never been scheduled at all. Offering either one
                // hands the merchant a job that can only ever look broken.
                if ($instance === '') {
                    continue;
                }

                $module = $this->moduleAttribution->moduleForClass($instance);
                $schedule = is_array($config) && isset($config['schedule']) ? (string) $config['schedule'] : null;

                $byModule[$module ?? self::UNATTRIBUTED_MODULE][] = new DiscoveredJob(
                    jobCode: $jobCode,
                    declaredSchedule: $schedule,
                    cadence: $this->cadenceEstimator->estimate($observations[$jobCode] ?? null),
                );
            }
        }

        // The observation table is a better source for undeclared jobs than
        // cron_schedule is: it is built from the same rows but persists past
        // Magento's hourly purge of them, so a job that ran this morning is
        // still offered this evening.
        foreach ($observations as $jobCode => $observation) {
            if (isset($declared[$jobCode]) || $this->isOwnJob($jobCode)) {
                continue;
            }

            $byModule[self::UNATTRIBUTED_MODULE][] = new DiscoveredJob(
                jobCode: $jobCode,
                declaredSchedule: null,
                cadence: $this->cadenceEstimator->estimate($observation),
            );
        }

        return $byModule;
    }

    /**
     * Groups declared message-queue consumers under the module that handles them.
     *
     * Attribution goes through the handler class: getConsumerInstance()
     * returns the generic framework interface and identifies nothing.
     *
     * @return array<string,string[]>
     */
    private function collectConsumers(): array
    {
        $byModule = [];

        foreach ($this->consumerConfig->getConsumers() as $consumer) {
            $module = null;

            foreach ($consumer->getHandlers() as $handler) {
                $module = $this->moduleAttribution->moduleForClass((string) $handler->getType());

                if ($module !== null) {
                    break;
                }
            }

            if ($module === null) {
                continue;
            }

            $byModule[$module][] = (string) $consumer->getName();
        }

        return $byModule;
    }

    /**
     * Assembles one integration from its jobs and consumers.
     *
     * @param string $module
     * @param DiscoveredJob[] $jobs
     * @param string[] $consumerNames
     * @return DiscoveredIntegration
     */
    private function build(string $module, array $jobs, array $consumerNames): DiscoveredIntegration
    {
        usort($jobs, static fn (DiscoveredJob $a, DiscoveredJob $b): int => strnatcmp($a->jobCode, $b->jobCode));
        sort($consumerNames, SORT_NATURAL);

        $isUnattributed = $module === self::UNATTRIBUTED_MODULE;

        return new DiscoveredIntegration(
            moduleName: $module,
            displayName: $isUnattributed
                ? (string) __('Other scheduled jobs')
                : $this->moduleAttribution->displayNameFor($module),
            vendorLabel: $isUnattributed
                ? ''
                : $this->moduleAttribution->vendorLabelFor($module),
            packageName: $isUnattributed ? null : $this->moduleAttribution->packageFor($module),
            // An unattributed job is by definition not part of stock Magento,
            // so it ranks with the merchant's own things rather than below them.
            isThirdParty: $isUnattributed || !$this->moduleAttribution->isMagentoCore($module),
            jobs: $jobs,
            consumerNames: array_values($consumerNames),
        );
    }

    /**
     * Whether this job code belongs to the connector's own crontab.xml.
     *
     * Watching them as an integration is circular: the job that reports the
     * signal would be the source of the signal.
     *
     * @param string $jobCode
     * @return bool
     */
    private function isOwnJob(string $jobCode): bool
    {
        return str_starts_with($jobCode, 'watchtower_');
    }
}
