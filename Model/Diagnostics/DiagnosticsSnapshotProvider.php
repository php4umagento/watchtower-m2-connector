<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Diagnostics;

use Watchtower\Connector\Model\Api\PingService;
use Watchtower\Connector\Model\Buffer\ReportBufferRepository;
use Watchtower\Connector\Model\Config;
use Watchtower\Connector\Model\CronHealth\Evaluator as CronHealthEvaluator;
use Watchtower\Connector\Model\EventCounter\EventCounterRepository;
use Watchtower\Connector\Model\HealthState\HealthStateRepository;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfigRepository;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthStateRepository;
use Watchtower\Connector\Model\RateSignal\DispersionStateRepository;
use Watchtower\Connector\Model\Seed\HistorySeeder;
use Watchtower\Connector\Model\StoreView\LiveStoreViewResolver;

/**
 * The read-only data-assembly layer shared by the watchtower:status command
 * and the admin diagnostics page.
 *
 * Everything here must stay read-only: HistorySeeder::seed(), for example,
 * writes rollup rows as a side effect, so seed coverage cannot be reported
 * from a snapshot without re-seeding real data on every page view.
 */
class DiagnosticsSnapshotProvider
{
    private const CATEGORIES = [
        HistorySeeder::CATEGORY_BASKET_QUOTE,
        HistorySeeder::CATEGORY_CHECKOUT,
        HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT,
    ];

    /**
     * @param Config $config
     * @param PingService $pingService
     * @param ReportBufferRepository $reportBufferRepository
     * @param EventCounterRepository $eventCounterRepository
     * @param HealthStateRepository $healthStateRepository
     * @param DispersionStateRepository $dispersionStateRepository
     * @param IntegrationHealthStateRepository $integrationHealthStateRepository
     * @param IntegrationHealthConfigRepository $integrationHealthConfigRepository
     * @param LiveStoreViewResolver $liveStoreViewResolver
     * @param SubmissionOutcomeRepository $submissionOutcomeRepository
     */
    public function __construct(
        private readonly Config $config,
        private readonly PingService $pingService,
        private readonly ReportBufferRepository $reportBufferRepository,
        private readonly EventCounterRepository $eventCounterRepository,
        private readonly HealthStateRepository $healthStateRepository,
        private readonly DispersionStateRepository $dispersionStateRepository,
        private readonly IntegrationHealthStateRepository $integrationHealthStateRepository,
        private readonly IntegrationHealthConfigRepository $integrationHealthConfigRepository,
        private readonly LiveStoreViewResolver $liveStoreViewResolver,
        private readonly SubmissionOutcomeRepository $submissionOutcomeRepository,
    ) {
    }

    /**
     * Assembles a full diagnostics snapshot from live/current state.
     *
     * The isConfigured() guard belongs here rather than in each caller: on an
     * unconfigured install ping() would receive a null baseUrl/apiKey and
     * fatal with a TypeError under strict_types.
     *
     * @param \DateTimeImmutable $now
     * @param int $recentOutcomeLimit
     * @return DiagnosticsSnapshot
     */
    public function snapshot(\DateTimeImmutable $now, int $recentOutcomeLimit = 20): DiagnosticsSnapshot
    {
        if (!$this->config->isConfigured()) {
            return new DiagnosticsSnapshot(
                reachable: false,
                unreachableError: 'Watchtower is not configured.',
                keyValid: null,
                organizationPaused: null,
                lastSuccessfulSubmissionAt: null,
                bufferedReportCount: 0,
                droppedEventCountLast24Hours: 0,
                cronHealth: new SignalSnapshot(CronHealthEvaluator::EVENT_TYPE, null, 0),
                storeViews: [],
                recentSubmissionOutcomes: [],
            );
        }

        $ping = $this->pingService->ping($this->config->baseUrl(), $this->config->apiKey());

        $cronHealthState = $this->healthStateRepository->get(CronHealthEvaluator::EVENT_TYPE);
        $cronHealth = new SignalSnapshot(
            category: CronHealthEvaluator::EVENT_TYPE,
            status: $cronHealthState->confirmedStatus,
            sequenceNumber: $cronHealthState->sequenceNumber,
        );

        return new DiagnosticsSnapshot(
            reachable: $ping->reachable,
            unreachableError: $ping->reachable ? null : $ping->errorMessage,
            keyValid: $ping->reachable ? $ping->keyValid() : null,
            organizationPaused: $ping->reachable ? $ping->organizationPaused : null,
            lastSuccessfulSubmissionAt: $this->reportBufferRepository->lastSuccessfulSubmissionAt(),
            bufferedReportCount: $this->reportBufferRepository->bufferedCount(),
            droppedEventCountLast24Hours: $this->eventCounterRepository->totalDroppedInLast24Hours($now),
            cronHealth: $cronHealth,
            storeViews: $this->storeViewSnapshots(),
            recentSubmissionOutcomes: $this->submissionOutcomeRepository->recent($recentOutcomeLimit),
        );
    }

    /**
     * Assembles each live store view's per-category signal snapshots.
     *
     * @return StoreViewSnapshot[]
     */
    private function storeViewSnapshots(): array
    {
        $snapshots = [];

        foreach ($this->liveStoreViewResolver->all() as $store) {
            $storeViewId = (int) $store->getId();
            $signals = [];

            foreach (self::CATEGORIES as $category) {
                $state = $this->dispersionStateRepository->get($storeViewId, $category);
                $signals[] = new SignalSnapshot(
                    category: $category,
                    status: $state->confirmedStatus,
                    sequenceNumber: $state->sequenceNumber,
                );
            }

            if ($this->integrationHealthConfigRepository->get($storeViewId) !== null) {
                $state = $this->integrationHealthStateRepository->get($storeViewId);
                $signals[] = new SignalSnapshot(
                    category: 'integration_health',
                    status: $state->confirmedStatus,
                    sequenceNumber: $state->sequenceNumber,
                );
            }

            $snapshots[] = new StoreViewSnapshot(
                storeViewId: $storeViewId,
                storeViewCode: (string) $store->getCode(),
                signals: $signals,
            );
        }

        return $snapshots;
    }
}
