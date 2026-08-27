<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

/**
 * Turns the merchant's watched set into the cron job codes to judge.
 *
 * Modules expand at evaluation time rather than being flattened on save, so a
 * job added by an extension upgrade starts being watched without the merchant
 * revisiting the page.
 */
class WatchedJobResolver
{
    /**
     * @param WatchedIntegrationRepository $watchedIntegrationRepository
     * @param IntegrationDiscovery $integrationDiscovery
     */
    public function __construct(
        private readonly WatchedIntegrationRepository $watchedIntegrationRepository,
        private readonly IntegrationDiscovery $integrationDiscovery
    ) {
    }

    /**
     * Every cron job code the watched set currently covers.
     *
     * @return string[]
     */
    public function resolve(): array
    {
        $jobCodes = $this->watchedIntegrationRepository->watchedJobCodes();
        $modules = $this->watchedIntegrationRepository->watchedModules();

        if ($modules !== []) {
            $wanted = array_flip($modules);

            foreach ($this->integrationDiscovery->discover() as $integration) {
                if (!isset($wanted[$integration->moduleName])) {
                    continue;
                }

                foreach ($integration->jobs as $job) {
                    $jobCodes[] = $job->jobCode;
                }
            }
        }

        return array_values(array_unique($jobCodes));
    }

    /**
     * Watched convention event labels, which expand from nothing and pass straight through.
     *
     * @return string[]
     */
    public function resolveEventLabels(): array
    {
        return $this->watchedIntegrationRepository->watchedEventLabels();
    }
}
