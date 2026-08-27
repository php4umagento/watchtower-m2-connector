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
use Watchtower\Connector\Model\AdminAuthFailure\Evaluator as AdminAuthFailureEvaluator;
use Watchtower\Connector\Model\Buffer\ReportBufferRepository;
use Watchtower\Connector\Model\CheckoutFailure\Evaluator as CheckoutFailureEvaluator;
use Watchtower\Connector\Model\CronHealth\Evaluator;
use Watchtower\Connector\Model\CronJobObservation\CadenceEstimator;
use Watchtower\Connector\Model\CronJobObservation\JobRunObservationRepository;
use Watchtower\Connector\Model\Diagnostics\SubmissionOutcomeRepository;
use Watchtower\Connector\Model\Environment\ConnectorVersionStateRepository;
use Watchtower\Connector\Model\IndexerHealth\Evaluator as IndexerHealthEvaluator;
use Watchtower\Connector\Model\IntegrationHealth\ConventionEventReader;
use Watchtower\Connector\Model\IntegrationHealth\CronJobObserver;
use Watchtower\Connector\Model\IntegrationHealth\Evaluator as IntegrationHealthEvaluator;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfig;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfigRepository;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthStateRepository;
use Watchtower\Connector\Model\IntegrationHealth\Observation;
use Watchtower\Connector\Model\IntegrationHealth\QueueConsumerObserver;
use Watchtower\Connector\Model\IntegrationHealth\WatchedJobResolver;
use Watchtower\Connector\Model\IntegrationHealth\WatchedSetEvaluator;
use Watchtower\Connector\Model\Organization\OrganizationStateRepository;
use Watchtower\Connector\Model\QueueHealth\Evaluator as QueueHealthEvaluator;
use Watchtower\Connector\Model\RateSignal\DispersionEvaluator;
use Watchtower\Connector\Model\Rollup\RollupRepository;
use Watchtower\Connector\Model\Seed\HistorySeeder;
use Watchtower\Connector\Model\Seed\SeedCoverageRepository;
use Watchtower\Connector\Model\Seed\SeedCoverageResult;
use Watchtower\Connector\Model\Signal\BasketQuoteReader;
use Watchtower\Connector\Model\Signal\CheckoutReader;
use Watchtower\Connector\Model\Signal\CustomerAccountReader;
use Watchtower\Connector\Model\Signal\RateSignalReaderInterface;
use Watchtower\Connector\Model\StoreView\LiveStoreViewResolver;

/**
 * Shared "evaluate then submit" logic behind both watchtower:report and the
 * scheduled cron job. Evaluates the install-scoped signals (cron_health,
 * admin_auth_failure, indexer_health, queue_health -- no store view) plus the
 * three rate-based categories, checkout_failure, and integration_health for
 * every live store view, then submits them together wherever possible. "Live"
 * mirrors StoreViewSyncService's is_active filter.
 *
 * $freshReports is always [cronHealth, adminAuthFailure, indexerHealth,
 * queueHealth, ...storeViewReports]. Each install-scoped report is captured
 * into its own variable rather than derived positionally from that array, so
 * adding one cannot silently corrupt the store-view slice.
 *
 * It is not free, though, and the earlier version of this note overstated it:
 * adding indexer_health, and then queue_health, each still required naming the
 * new signal in both 'installReports' returns below. Named capture makes the
 * addition a compile-visible edit rather than an off-by-one; it does not make
 * it a no-op.
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
     * @param AdminAuthFailureEvaluator $adminAuthFailureEvaluator
     * @param MetricsSubmissionService $metricsSubmissionService
     * @param ReportBufferRepository $reportBufferRepository
     * @param LiveStoreViewResolver $liveStoreViewResolver
     * @param BasketQuoteReader $basketQuoteReader
     * @param CheckoutReader $checkoutReader
     * @param CustomerAccountReader $customerAccountReader
     * @param RollupRepository $rollupRepository
     * @param DispersionEvaluator $dispersionEvaluator
     * @param CheckoutFailureEvaluator $checkoutFailureEvaluator
     * @param HistorySeeder $historySeeder
     * @param SeedCoverageRepository $seedCoverageRepository persists seedIfNeverSeeded()'s outcome so the
     *     diagnostics page/CLI can read it back without re-seeding
     * @param IntegrationHealthConfigRepository $integrationHealthConfigRepository
     * @param IntegrationHealthEvaluator $integrationHealthEvaluator
     * @param IntegrationHealthStateRepository $integrationHealthStateRepository used only by the evidence
     *     snapshot; the evaluator owns every other write to that table
     * @param CronJobObserver $cronJobObserver
     * @param QueueConsumerObserver $queueConsumerObserver
     * @param ConventionEventReader $conventionEventReader
     * @param OrganizationStateRepository $organizationStateRepository
     * @param LoggerInterface $logger
     * @param SubmissionOutcomeRepository $submissionOutcomeRepository
     * @param ConnectorVersionCheckService $connectorVersionCheckService
     * @param ConnectorVersionStateRepository $connectorVersionStateRepository
     * @param IndexerHealthEvaluator $indexerHealthEvaluator
     * @param QueueHealthEvaluator $queueHealthEvaluator
     * @param JobRunObservationRepository $jobRunObservationRepository measured run history behind the threshold
     * @param CadenceEstimator $cadenceEstimator
     * @param WatchedJobResolver $watchedJobResolver expands the merchant's watched set to job codes
     * @param WatchedSetEvaluator $watchedSetEvaluator rolls that set up to one status per store view
     */
    public function __construct(
        private readonly Config $config,
        private readonly Evaluator $cronHealthEvaluator,
        private readonly AdminAuthFailureEvaluator $adminAuthFailureEvaluator,
        private readonly MetricsSubmissionService $metricsSubmissionService,
        private readonly ReportBufferRepository $reportBufferRepository,
        private readonly LiveStoreViewResolver $liveStoreViewResolver,
        private readonly BasketQuoteReader $basketQuoteReader,
        private readonly CheckoutReader $checkoutReader,
        private readonly CustomerAccountReader $customerAccountReader,
        private readonly RollupRepository $rollupRepository,
        private readonly DispersionEvaluator $dispersionEvaluator,
        private readonly CheckoutFailureEvaluator $checkoutFailureEvaluator,
        private readonly HistorySeeder $historySeeder,
        private readonly SeedCoverageRepository $seedCoverageRepository,
        private readonly IntegrationHealthConfigRepository $integrationHealthConfigRepository,
        private readonly IntegrationHealthEvaluator $integrationHealthEvaluator,
        private readonly IntegrationHealthStateRepository $integrationHealthStateRepository,
        private readonly CronJobObserver $cronJobObserver,
        private readonly QueueConsumerObserver $queueConsumerObserver,
        private readonly ConventionEventReader $conventionEventReader,
        private readonly OrganizationStateRepository $organizationStateRepository,
        private readonly LoggerInterface $logger,
        private readonly SubmissionOutcomeRepository $submissionOutcomeRepository,
        private readonly ConnectorVersionCheckService $connectorVersionCheckService,
        private readonly ConnectorVersionStateRepository $connectorVersionStateRepository,
        // Appended rather than grouped with the other install-scoped evaluators
        // above: every unit test builds this class positionally, so inserting a
        // parameter mid-list silently shifts a dozen unrelated arguments.
        private readonly IndexerHealthEvaluator $indexerHealthEvaluator,
        private readonly QueueHealthEvaluator $queueHealthEvaluator,
        private readonly JobRunObservationRepository $jobRunObservationRepository,
        private readonly CadenceEstimator $cadenceEstimator,
        private readonly WatchedJobResolver $watchedJobResolver,
        private readonly WatchedSetEvaluator $watchedSetEvaluator,
    ) {
    }

    /**
     * How long this source's last success may go unrenewed before it is stalled.
     *
     * Derived from what the job has actually been measured doing rather than
     * from a hand-typed interval. The retired field defaulted to 60 minutes,
     * which put a healthy nightly ERP sync in SevereDrop for 23 hours out of
     * every 24, and no merchant could reasonably be expected to get that
     * number right for every integration they run.
     *
     * Null means the cadence is not established yet, which the evaluator
     * reports as INSUFFICIENT_DATA rather than guessing a window.
     *
     * Only cron jobs are measurable this way. A queue consumer's activity is
     * read from magento_operation and a convention event's from the
     * merchant's own dispatches, neither of which the run recorder watches,
     * so both keep the interval their configuration already carries.
     *
     * @param IntegrationHealthConfig $config
     * @return int|null
     */
    private function thresholdSecondsFor(IntegrationHealthConfig $config): ?int
    {
        if ($config->sourceType !== IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB) {
            return $config->expectedMaxIntervalMinutes * 60;
        }

        return $this->cadenceEstimator
            ->estimate($this->jobRunObservationRepository->get($config->sourceIdentifier))
            ->thresholdSeconds;
    }

    /**
     * Evaluates cron_health and every live store view's signals.
     *
     * Submits what's due (up to MAX_SUBMISSIONS_PER_CYCLE requests) and buffers the rest.
     *
     * @return array{
     *     ran: bool,
     *     report?: MetricReport,
     *     installReports?: MetricReport[],
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

        // cron_health must stay at index 0; callers read $outcome['report'] from
        // there. The other install-scoped signals follow it -- each captured
        // into its own variable rather than assembled positionally, because
        // "storeViewReports is everything after the install-scoped ones"
        // stopped being expressible as a single array_slice offset the moment a
        // second one existed, and there are three now.
        $cronHealthReport = $this->cronHealthEvaluator->evaluate($now);
        $adminAuthFailureReport = $this->adminAuthFailureEvaluator->evaluate($this->lastCompleteHourStart($now), $now);
        $indexerHealthReport = $this->indexerHealthEvaluator->evaluate($now);
        $queueHealthReport = $this->queueHealthEvaluator->evaluate($now);
        $storeViewReports = $this->liveStoreViewReports($now);

        $freshReports = [
            $cronHealthReport,
            $adminAuthFailureReport,
            $indexerHealthReport,
            $queueHealthReport,
            ...$storeViewReports,
        ];

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
                'installReports' => [
                    $cronHealthReport,
                    $adminAuthFailureReport,
                    $indexerHealthReport,
                    $queueHealthReport,
                ],
                'storeViewReports' => $storeViewReports,
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
            'installReports' => [$cronHealthReport, $adminAuthFailureReport, $indexerHealthReport, $queueHealthReport],
            'storeViewReports' => $storeViewReports,
            'result' => $lastResult,
            'includedBufferedCount' => $totalIncludedBuffered,
            'expiredBufferedCount' => $expiredCount,
            'evictedForCapacityCount' => $totalEvictedForCapacity,
            'organizationPaused' => false,
            'belowMinimumVersion' => false,
        ];
    }

    /**
     * Captures integration_health success/failure evidence without evaluating anything.
     *
     * Magento purges succeeded cron_schedule rows about an hour after they
     * finish, so a source observed only once per evaluation cycle can have its
     * single daily success vanish between two ticks and be reported DOWN while
     * perfectly healthy. Cron\ReportJob therefore calls this every 5-minute
     * tick; it writes only the two evidence columns and never produces a
     * report, advances a sequence number, or touches a status.
     *
     * @param \DateTimeImmutable $now
     * @return void
     */
    public function snapshotIntegrationHealthEvidence(\DateTimeImmutable $now): void
    {
        if (!$this->config->isConfigured() || !$this->config->isEnabled()) {
            return;
        }

        foreach ($this->liveStoreViewResolver->all() as $store) {
            $storeViewId = (int) $store->getId();
            $config = $this->integrationHealthConfigRepository->get($storeViewId);

            if ($config === null) {
                continue;
            }

            $state = $this->integrationHealthStateRepository->get($storeViewId);

            // A source change must be re-seeded by the evaluator, not have fresh
            // evidence written under the previous source's fingerprint.
            if (!$state->describesSource($config->sourceType, $config->sourceIdentifier)) {
                continue;
            }

            $observation = $this->observeConfiguredSource($config, $now);

            if ($observation === null) {
                continue;
            }

            $this->integrationHealthStateRepository->saveObservedEvidence(
                $storeViewId,
                $this->later($observation->latestSuccessAt, $state->lastSuccessAt),
                $this->later($observation->latestFailureAt, $state->lastFailureAt)
            );
        }
    }

    /**
     * The later of two nullable timestamps, so persisted evidence only ever moves forward.
     *
     * @param \DateTimeImmutable|null $observed
     * @param \DateTimeImmutable|null $stored
     * @return \DateTimeImmutable|null
     */
    private function later(?\DateTimeImmutable $observed, ?\DateTimeImmutable $stored): ?\DateTimeImmutable
    {
        if ($observed === null || $stored === null) {
            return $observed ?? $stored;
        }

        return $observed > $stored ? $observed : $stored;
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
     * The last COMPLETE top-of-hour instant before $now, UTC.
     *
     * Shared by liveStoreViewReports() and run()'s admin_auth_failure
     * evaluation: both read an hour-bucketed counter, and both would
     * structurally under-count if handed the still-accumulating current hour
     * instead. Extracted to one place after this exact defect was found and
     * fixed once already for the rate/ratio signals (metrics spec's own
     * changelog, "the evaluation example evaluated a partially-elapsed
     * hour") -- a second, separately-computed copy for the next
     * hour-bucketed signal is exactly how that class of bug comes back.
     *
     * @param \DateTimeImmutable $now
     * @return \DateTimeImmutable
     */
    private function lastCompleteHourStart(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $currentHourStart = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:00:00'),
            new \DateTimeZone('UTC')
        );

        return $currentHourStart->modify('-1 hour');
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
        $evaluatedHourStart = $this->lastCompleteHourStart($now);
        $windowStart = $evaluatedHourStart;
        $windowEnd = $evaluatedHourStart->modify('+1 hour');

        // Resolved once for the whole cycle, never inside the store view loop.
        // The watched set is install-level, and expanding it walks every
        // declared cron job, so resolving per store view would turn a fixed
        // cost into one multiplied by store view count.
        $watchedJobCodes = $this->watchedJobResolver->resolve();

        $readersByCategory = [
            HistorySeeder::CATEGORY_BASKET_QUOTE => $this->basketQuoteReader,
            HistorySeeder::CATEGORY_CHECKOUT => $this->checkoutReader,
            HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT => $this->customerAccountReader,
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
            $checkoutCount = 0;

            foreach ($readersByCategory as $category => $reader) {
                $observedCount = $reader->countForWindow($storeViewId, $windowStart, $windowEnd);

                if ($category === HistorySeeder::CATEGORY_CHECKOUT) {
                    $checkoutCount = $observedCount;
                }

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

            // Reuses the checkout count just read rather than querying
            // sales_order again: the ratio's denominator must be the SAME
            // hour's successes as its numerator's failures, and a second read
            // could straddle a late-arriving row and disagree with the first.
            // No rollup row is written for this category -- it has no baseline
            // to accumulate, and a stored ratio would only invite someone to
            // build one.
            $reports[] = $this->checkoutFailureEvaluator->evaluate(
                $storeViewId,
                $storeViewCode,
                $checkoutCount,
                $evaluatedHourStart,
                $now
            );

            $integrationHealthReport = $this->integrationHealthReportFor(
                $storeViewId,
                $storeViewCode,
                $watchedJobCodes,
                $now
            );

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
     * @param string[] $watchedJobCodes resolved once per cycle, never per store view
     * @param \DateTimeImmutable $now
     * @return MetricReport|null
     */
    private function integrationHealthReportFor(
        int $storeViewId,
        string $storeViewCode,
        array $watchedJobCodes,
        \DateTimeImmutable $now
    ): ?MetricReport {
        // The watched set is the current model and takes precedence. The
        // per-source path below stays only for an install whose rows have not
        // been migrated yet; once the set is populated it is never consulted.
        if ($watchedJobCodes !== []) {
            return $this->watchedSetEvaluator->evaluate($storeViewId, $storeViewCode, $watchedJobCodes, $now);
        }

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
            $this->thresholdSecondsFor($config),
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
