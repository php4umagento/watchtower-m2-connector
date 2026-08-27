<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfig;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfigRepository;
use Watchtower\Connector\Model\IntegrationHealth\WatchedIntegrationRepository;

/**
 * Carries existing per-store-view integration_health sources over to the
 * install-level watched set.
 *
 * Without it, upgrading silently stops monitoring whatever was configured,
 * which is the worst failure a monitoring product has. Cron jobs and
 * convention events carry over; only queue_consumer cannot, and that is logged
 * rather than dropped silently.
 */
class MigrateIntegrationHealthSources implements DataPatchInterface
{
    /**
     * @param IntegrationHealthConfigRepository $configRepository
     * @param WatchedIntegrationRepository $watchedIntegrationRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly IntegrationHealthConfigRepository $configRepository,
        private readonly WatchedIntegrationRepository $watchedIntegrationRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Migrates configured sources into the watched set.
     *
     * @return $this
     */
    public function apply(): self
    {
        // An install that already chose something through the rebuilt picker
        // must not have that replaced by stale pre-upgrade rows.
        if ($this->watchedIntegrationRepository->hasAnyWatched()) {
            return $this;
        }

        $jobCodes = [];
        $eventLabels = [];
        $unmigratable = [];

        foreach ($this->configRepository->getAll() as $config) {
            match ($config->sourceType) {
                IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB => $jobCodes[] = $config->sourceIdentifier,
                IntegrationHealthConfig::SOURCE_TYPE_CONVENTION_EVENT => $eventLabels[] = $config->sourceIdentifier,
                // Only queue_consumer is left, and it named a magento_operation
                // topic the new model does not watch at all.
                default => $unmigratable[] = $config->sourceType . ':' . $config->sourceIdentifier,
            };
        }

        if ($jobCodes !== [] || $eventLabels !== []) {
            $this->watchedIntegrationRepository->save(
                [],
                array_values(array_unique($jobCodes)),
                array_values(array_unique($eventLabels))
            );
        }

        if ($unmigratable !== []) {
            $this->logger->warning(
                'Watchtower could not migrate some integration health sources to the new watched set. '
                . 'Re-select these integrations under Watchtower > Integrations.',
                ['sources' => $unmigratable]
            );
        }

        return $this;
    }

    /**
     * No dependencies: the schema this reads and writes is declarative.
     *
     * @return array
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * This patch has never been renamed, so it has no historical aliases.
     *
     * @return array
     */
    public function getAliases(): array
    {
        return [];
    }
}
