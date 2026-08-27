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
 * which is the worst failure a monitoring product has. Only cron_job rows can
 * carry over; the other two source types are logged rather than dropped
 * silently.
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
        $unmigratable = [];

        foreach ($this->configRepository->getAll() as $config) {
            if ($config->sourceType === IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB) {
                $jobCodes[] = $config->sourceIdentifier;

                continue;
            }

            $unmigratable[] = $config->sourceType . ':' . $config->sourceIdentifier;
        }

        if ($jobCodes !== []) {
            $this->watchedIntegrationRepository->save([], array_values(array_unique($jobCodes)));
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
