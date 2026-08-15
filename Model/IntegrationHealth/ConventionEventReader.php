<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

/**
 * Thin wrapper over IntegrationHealthEventRepository matching CronJobObserver/
 * QueueConsumerObserver's observe()-shaped call site, so ReportingService can
 * dispatch uniformly to whichever of the three sources a store view has
 * configured. Unlike those two, observe() here also takes $storeViewId: a
 * cron job's or queue topic's success/failure is an install-global fact,
 * but a convention-event dispatch is attributed to one store view at
 * observation time, so reading it back requires the same filter.
 */
class ConventionEventReader
{
    /**
     * @param IntegrationHealthEventRepository $repository
     */
    public function __construct(
        private readonly IntegrationHealthEventRepository $repository
    ) {
    }

    /**
     * Fetches the freshest success/failure evidence for one store view's configured integration label.
     *
     * @param int $storeViewId
     * @param string $integrationLabel
     * @param \DateTimeImmutable $now
     * @return Observation
     */
    public function observe(int $storeViewId, string $integrationLabel, \DateTimeImmutable $now): Observation
    {
        return $this->repository->latestObservation($storeViewId, $integrationLabel, $now);
    }
}
