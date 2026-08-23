<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model;

use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Api\ConnectorVersionCheckService;
use Watchtower\Connector\Model\Api\MetricReport;
use Watchtower\Connector\Model\Api\MetricsSubmissionResult;
use Watchtower\Connector\Model\Api\MetricsSubmissionService;
use Watchtower\Connector\Model\Buffer\ReportBufferRepository;
use Watchtower\Connector\Model\CronHealth\Evaluator;
use Watchtower\Connector\Model\Diagnostics\SubmissionOutcomeRepository;
use Watchtower\Connector\Model\Environment\ConnectorVersionStateRepository;
use Watchtower\Connector\Model\IntegrationHealth\ConventionEventReader;
use Watchtower\Connector\Model\IntegrationHealth\CronJobObserver;
use Watchtower\Connector\Model\IntegrationHealth\Evaluator as IntegrationHealthEvaluator;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfig;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfigRepository;
use Watchtower\Connector\Model\IntegrationHealth\Observation;
use Watchtower\Connector\Model\IntegrationHealth\QueueConsumerObserver;
use Watchtower\Connector\Model\Organization\OrganizationStateRepository;
use Watchtower\Connector\Model\RateSignal\DispersionEvaluator;
use Watchtower\Connector\Model\Rollup\RollupRepository;
use Watchtower\Connector\Model\Seed\HistorySeeder;
use Watchtower\Connector\Model\Seed\SeedCoverageRepository;
use Watchtower\Connector\Model\Seed\SeedCoverageResult;
use Watchtower\Connector\Model\Signal\BasketQuoteReader;
use Watchtower\Connector\Model\Signal\CheckoutReader;
use Watchtower\Connector\Model\Signal\CustomerAccountRegistrationReader;
use Watchtower\Connector\Model\Signal\RateSignalReaderInterface;
use Watchtower\Connector\Model\StoreView\LiveStoreViewResolver;

/**
 * Shared "evaluate then submit" logic behind both watchtower:report and the
 * scheduled cron job. Evaluates cron_health (install-scoped) plus the three
 * rate-based categories and integration_health for every live store view,
 * then submits them together wherever possible. "Live" mirrors
 * StoreViewSyncService's is_active filter.
 *
 * The platform rejects a report whose sequence_number is at or below the
 * highest already accepted for that event type, so a submission may never
 * include a newer report while withholding an older buffered one -- that
 * permanently invalidates the older report. Every attempt therefore carries
 * the whole current buffer first, plus only as many fresh reports as still
 * fit under the per-request cap, never a reordered partial batch.
 */
class ReportingService
{
    /** The platform's per-request cap; a larger backlog is split across requests. */
    private const MAX_REPORTS_PER_SUBMISSION = 500;

    /**
     * Caps submit() calls per cycle. A single submission would lose data
     * permanently past ~166 live store views; an unbounded loop would turn a
     * persistently-failing platform into a request storm. 5 x 500 stays far
     * under the platform's 60 requests/minute limit.
     */
    private const MAX_SUBMISSIONS_PER_CYCLE = 5;

    /**
     * @param Config $config
     * @param Evaluator $cronHealthEvaluator
     * @param MetricsSubmissionService $metricsSubmissionService
     * @param ReportBufferRepository $reportBufferRepository
     * @param LiveStoreViewResolver $liveStoreViewResolver
     * @param BasketQuoteReader $basketQuoteReader
     * @param CheckoutReader $checkoutReader
     * @param CustomerAccountRegistrationReader $customerAccountRegistrationReader
     * @param RollupRepository $rollupRepository
     * @param DispersionEvaluator $dispersionEvaluator
     * @param HistorySeeder $historySeeder
     * @param SeedCoverageRepository $seedCoverageRepository persists seedIfNeverSeeded()'s outcome so the
     *     diagnostics page/CLI can read it back without re-seeding
     * @param IntegrationHealthConfigRepository $integrationHealthConfigRepository
     * @param IntegrationHealthEvaluator $integrationHealthEvaluator
     * @param CronJobObserver $cronJobObserver
     * @param QueueConsumerObserver $queueConsumerObserver
     * @param ConventionEventReader $conventionEventReader
     * @param OrganizationStateRepository $organizationStateRepository
     * @param LoggerInterface $logger
     * @param SubmissionOutcomeRepository $submissionOutcomeRepository
     * @param ConnectorVersionCheckService $connectorVersionCheckService
     * @param ConnectorVersionStateRepository $connectorVersionStateRepository
     */
    public function __construct(
        private readonly Config $config,
        private readonly Evaluator $cronHealthEvaluator,
        private readonly MetricsSubmissionService $metricsSubmissionService,
        private readonly ReportBufferRepository $reportBufferRepository,
        private readonly LiveStoreViewResolver $liveStoreViewResolver,
        private readonly BasketQuoteReader $basketQuoteReader,
        private readonly CheckoutReader $checkoutReader,
        private readonly CustomerAccountRegistrationReader $customerAccountRegistrationReader,
        private readonly RollupRepository $rollupRepository,
        private readonly DispersionEvaluator $dispersionEvaluator,
        private readonly HistorySeeder $historySeeder,
        private readonly SeedCoverageRepository $seedCoverageRepository,
        private readonly IntegrationHealthConfigRepository $integrationHealthConfigRepository,
        private readonly IntegrationHealthEvaluator $integrationHealthEvaluator,
        private readonly CronJobObserver $cronJobObserver,
        private readonly QueueConsumerObserver $queueConsumerObserver,
        private readonly ConventionEventReader $conventionEventReader,
        private readonly OrganizationStateRepository $organizationStateRepository,
        private readonly LoggerInterface $logger,
        private readonly SubmissionOutcomeRepository $submissionOutcomeRepository,
        private readonly ConnectorVersionCheckService $connectorVersionCheckService,
        private readonly ConnectorVersionStateRepository $connectorVersionStateRepository,
    ) {
    }

    /**
     * Evaluates cron_health and every live store view's signals.
     *
     * Submits what's due (up to MAX_SUBMISSIONS_PER_CYCLE requests) and buffers the rest.
     *
     * @return array{
     *     ran: bool,
     *     report?: MetricReport,
     *     storeViewReports?: MetricReport[],
     *     result?: ?MetricsSubmissionResult,
     *     includedBufferedCount?: int,
     *     expiredBufferedCount?: int,
     *     evictedForCapacityCount?: int,
     *     skippedReason?: string,
     *     organizationPaused?: bool,
     *     belowMinimumVersion?: bool,
     * }
     */
    public function run(): array
    {
        if (!$this->config->isConfigured()) {
            return ['ran' => false, 'skippedReason' => 'not configured'];
        }

        if (!$this->config->isEnabled()) {
            return ['ran' => false, 'skippedReason' => 'disabled'];
        }

        $now = new \DateTimeImmutable();

        // PRD FR24: every real cycle, never gated on organization_paused or
        // submission backoff -- a self-disabled connector must keep checking
        // on its own so it recovers automatically once upgraded, the same
        // reason ping() is never paused-gated. A failed check leaves the
        // persisted state untouched; see ConnectorVersionStateRepository's
        // own docblock for why that matters.
        $versionCheck = $this->connectorVersionCheckService->check($this->config->baseUrl(), $this->config->apiKey());

        if ($versionCheck->succeeded) {
            $this->connectorVersionStateRepository->save(
                $versionCheck->installedVersion,
                $versionCheck->minimumVersion,
                $versionCheck->latestVersion,
                $versionCheck->belowMinimum,
                $versionCheck->updateAvailable,
                $now,
            );
        }

        $belowMinimumVersion = $this->connectorVersionStateRepository->get()->belowMinimum;

        // cron_health must stay at index 0; callers read $outcome['report'] from there.
        $freshReports = [$this->cronHealthEvaluator->evaluate($now)];
        array_push($freshReports, ...$this->liveStoreViewReports($now));

        // Before anything is buffered or submitted: an expired report 422s the entire batch it rides in.
        $expiredCount = $this->reportBufferRepository->discardExpired($now);

        // Checked alongside isDue() rather than as a top-level skip, so evaluation
        // still advances state and sequence numbers while paused/below minimum.
        $organizationPaused = $this->organizationStateRepository->isPaused($now);

        if (!$this->reportBufferRepository->isDue($now) || $organizationPaused || $belowMinimumVersion) {
            $this->logger->debug('Watchtower reporting cycle skipped submission.', [
                'reason' => match (true) {
                    $belowMinimumVersion => 'connector_below_minimum_version',
                    $organizationPaused => 'organization_paused',
                    default => 'backoff',
                },
                'freshReportCount' => count($freshReports),
            ]);

            $evictedCount = $this->bufferAll($freshReports);

            return [
                'ran' => true,
                'report' => $freshReports[0],
                'storeViewReports' => array_slice($freshReports, 1),
                'result' => null,
                'includedBufferedCount' => 0,
                'expiredBufferedCount' => $expiredCount,
                'evictedForCapacityCount' => $evictedCount,
                'organizationPaused' => $organizationPaused,
                'belowMinimumVersion' => $belowMinimumVersion,
            ];
        }

        // Each iteration re-fetches the oldest buffered slice so the whole-buffer-first
        // ordering invariant holds across iterations as it does within one.
        $remainingFresh = $freshReports;
        $lastResult = null;
        $totalIncludedBuffered = 0;
        $totalEvictedForCapacity = 0;

        for ($attempt = 1; $attempt <= self::MAX_SUBMISSIONS_PER_CYCLE; $attempt++) {
            $buffered = $this->reportBufferRepository->allBuffered(self::MAX_REPORTS_PER_SUBMISSION);
            // The buffer alone may already fill the request; only fresh reports that
            // still fit go in, never ones that would jump ahead of queued older reports.
            $freshSlots = max(0, self::MAX_REPORTS_PER_SUBMISSION - count($buffered));
            $includedFresh = array_slice($remainingFresh, 0, $freshSlots);

            $batch = array_map(static fn ($item) => $item->report, $buffered);
            array_push($batch, ...$includedFresh);

            if ($batch === []) {
                break;
            }

            $this->logger->debug('Watchtower reporting cycle attempting submission.', [
                'attempt' => $attempt,
                'bufferedCount' => count($buffered),
                'freshIncludedCount' => count($includedFresh),
            ]);

            $lastResult = $this->metricsSubmissionService->submit(
                $this->config->baseUrl(),
                $this->config->apiKey(),
                $batch
            );

            $this->submissionOutcomeRepository->record(
                $lastResult->succeeded,
                $lastResult->accepted,
                $lastResult->rejected,
                $lastResult->errorMessage,
                $now
            );

            if (!$lastResult->succeeded) {
                $this->reportBufferRepository->recordFailure($lastResult->retryAfterSeconds, $now);

                // $remainingFresh deliberately still holds this failed batch's reports; buffered after the loop.
                break;
            }

            if ($buffered !== []) {
                $this->reportBufferRepository->deleteDelivered(
                    array_map(static fn ($item) => $item->bufferId, $buffered)
                );
                $totalIncludedBuffered += count($buffered);
            }

            $remainingFresh = array_slice($remainingFresh, count($includedFresh));
        }

        // Gated on how the cycle ENDED, not on whether any earlier iteration
        // succeeded: clearing after a later failure would overwrite that same-cycle
        // recordFailure() on the shared singleton row, so backoff could never escalate.
        if ($lastResult !== null && $lastResult->succeeded) {
            $this->reportBufferRepository->clearBackoff($now);
        }

        $totalEvictedForCapacity += $this->bufferAll($remainingFresh);

        return [
            'ran' => true,
            'report' => $freshReports[0],
            'storeViewReports' => array_slice($freshReports, 1),
            'result' => $lastResult,
            'includedBufferedCount' => $totalIncludedBuffered,
            'expiredBufferedCount' => $expiredCount,
            'evictedForCapacityCount' => $totalEvictedForCapacity,
            'organizationPaused' => false,
            'belowMinimumVersion' => false,
        ];
    }

    /**
     * Buffers every given report.
     *
     * @param MetricReport[] $reports
     * @return int total number of OTHER reports evicted for capacity
     */
    private function bufferAll(array $reports): int
    {
        $totalEvicted = 0;

        foreach ($reports as $report) {
            $totalEvicted += $this->reportBufferRepository->bufferReport($report);
        }

        return $totalEvicted;
    }

    /**
     * Evaluates basket_quote/checkout/customer_account for every live store view.
     *
     * The evaluated hour is always the last COMPLETE hour: comparing a
     * partially-elapsed hour against full-hour historical windows produces a
     * structural false DROP, ~50% at the midpoint. $now is carried separately
     * only for the wire evaluated_at field.
     *
     * @param \DateTimeImmutable $now
     * @return MetricReport[]
     */
    private function liveStoreViewReports(\DateTimeImmutable $now): array
    {
        $currentHourStart = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:00:00'),
            new \DateTimeZone('UTC')
        );
        $evaluatedHourStart = $currentHourStart->modify('-1 hour');
        $windowStart = $evaluatedHourStart;
        $windowEnd = $currentHourStart;

        $readersByCategory = [
            HistorySeeder::CATEGORY_BASKET_QUOTE => $this->basketQuoteReader,
            HistorySeeder::CATEGORY_CHECKOUT => $this->checkoutReader,
            HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT => $this->customerAccountRegistrationReader,
        ];

        $reports = [];

        foreach ($this->liveStoreViewResolver->all() as $store) {
            $storeViewId = (int) $store->getId();
            $storeViewCode = (string) $store->getCode();

            $this->seedIfNeverSeeded($storeViewId, array_keys($readersByCategory), $now);

            /**
             * @var string $category
             * @var RateSignalReaderInterface $reader
             */
            foreach ($readersByCategory as $category => $reader) {
                $observedCount = $reader->countForWindow($storeViewId, $windowStart, $windowEnd);
                $this->rollupRepository->recordHourlyCount(
                    $storeViewId,
                    $category,
                    $evaluatedHourStart,
                    $observedCount
                );
                $reports[] = $this->dispersionEvaluator->evaluate(
                    $storeViewId,
                    $storeViewCode,
                    $category,
                    $observedCount,
                    $evaluatedHourStart,
                    $now
                );
            }

            $integrationHealthReport = $this->integrationHealthReportFor($storeViewId, $storeViewCode, $now);

            if ($integrationHealthReport !== null) {
                $reports[] = $integrationHealthReport;
            }
        }

        return $reports;
    }

    /**
     * Backfills a store view's local rollup history the first time it's ever
     * evaluated, so DispersionEvaluator classifies against real weeks of
     * history instead of cold-starting on whatever this cycle records live.
     * HistorySeeder::seed() was previously only reachable via the manual
     * `watchtower:coverage` command -- an install that never had it run by
     * hand cold-started on a near-empty local baseline, exactly the
     * false-anomaly risk this closes.
     *
     * Gated on rollup data already existing for any of the given categories,
     * not a separate persisted flag: HistorySeeder::seed() is an idempotent
     * upsert (see CoverageCommand's own docblock), so a redundant re-seed
     * costs only wasted queries, and this reads the same table the seed
     * itself writes to rather than inventing new state that could drift out
     * of sync with it.
     *
     * @param int $storeViewId
     * @param string[] $categories
     * @param \DateTimeImmutable $now
     * @return void
     */
    private function seedIfNeverSeeded(int $storeViewId, array $categories, \DateTimeImmutable $now): void
    {
        if ($this->rollupRepository->hasAnyHourlyDataForCategories($storeViewId, $categories)) {
            return;
        }

        $results = $this->historySeeder->seed($storeViewId, $now, $this->historySeeder->defaultBaselineWindowDays());

        foreach ($results as $result) {
            $this->seedCoverageRepository->save($storeViewId, $result);
        }

        $this->logger->info('Watchtower seeded historical baseline for a newly-tracked store view.', [
            'storeViewId' => $storeViewId,
            'coverage' => array_map(
                static fn (SeedCoverageResult $result): array => [
                    'category' => $result->category,
                    'status' => $result->status->value,
                    'daysSeeded' => $result->daysSeeded,
                ],
                $results
            ),
        ]);
    }

    /**
     * Evaluates integration_health, which unlike the rate-based categories is
     * optional per store view: never configured means never evaluated, but one
     * configured and later cleared must keep heartbeating its last known status
     * or the platform's staleness sweep alerts on a deliberately retired signal.
     *
     * @param int $storeViewId
     * @param string $storeViewCode
     * @param \DateTimeImmutable $now
     * @return MetricReport|null
     */
    private function integrationHealthReportFor(
        int $storeViewId,
        string $storeViewCode,
        \DateTimeImmutable $now
    ): ?MetricReport {
        $config = $this->integrationHealthConfigRepository->get($storeViewId);
        // An unreadable source means "we can't tell right now", so it falls back to
        // the retirement heartbeat rather than a fabricated DOWN observation.
        $observation = $config !== null ? $this->observeConfiguredSource($config, $now) : null;

        if ($config === null || $observation === null) {
            return $this->integrationHealthEvaluator->heartbeatRetiredIfPreviouslyReported(
                $storeViewId,
                $storeViewCode,
                $now
            );
        }

        return $this->integrationHealthEvaluator->evaluate(
            $storeViewId,
            $storeViewCode,
            $config->sourceType,
            $config->sourceIdentifier,
            $observation->latestSuccessAt,
            $observation->latestFailureAt,
            $config->expectedMaxIntervalMinutes,
            $now
        );
    }

    /**
     * Dispatches to whichever configured state-poll source this store view has, or null if unrecognized.
     *
     * @param IntegrationHealthConfig $config
     * @param \DateTimeImmutable $now
     * @return Observation|null
     */
    private function observeConfiguredSource(IntegrationHealthConfig $config, \DateTimeImmutable $now): ?Observation
    {
        return match ($config->sourceType) {
            IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB
                => $this->cronJobObserver->observe($config->sourceIdentifier, $now),
            IntegrationHealthConfig::SOURCE_TYPE_QUEUE_CONSUMER
                => $this->queueConsumerObserver->observe($config->sourceIdentifier, $now),
            IntegrationHealthConfig::SOURCE_TYPE_CONVENTION_EVENT
                => $this->conventionEventReader->observe($config->storeViewId, $config->sourceIdentifier, $now),
            default => null,
        };
    }
}
