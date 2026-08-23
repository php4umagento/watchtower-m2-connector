<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model;

use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Api\ConnectorVersionCheckResult;
use Watchtower\Connector\Model\Api\ConnectorVersionCheckService;
use Watchtower\Connector\Model\Api\MetricReport;
use Watchtower\Connector\Model\Api\MetricsSubmissionResult;
use Watchtower\Connector\Model\Api\MetricsSubmissionService;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Buffer\BufferedReport;
use Watchtower\Connector\Model\Buffer\ReportBufferRepository;
use Watchtower\Connector\Model\Config;
use Watchtower\Connector\Model\CronHealth\Evaluator;
use Watchtower\Connector\Model\Diagnostics\SubmissionOutcomeRepository;
use Watchtower\Connector\Model\Environment\ConnectorVersionState;
use Watchtower\Connector\Model\Environment\ConnectorVersionStateRepository;
use Watchtower\Connector\Model\IntegrationHealth\ConventionEventReader;
use Watchtower\Connector\Model\IntegrationHealth\CronJobObserver;
use Watchtower\Connector\Model\IntegrationHealth\Evaluator as IntegrationHealthEvaluator;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfig;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthConfigRepository;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthState;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthStateRepository;
use Watchtower\Connector\Model\IntegrationHealth\Observation;
use Watchtower\Connector\Model\IntegrationHealth\QueueConsumerObserver;
use Watchtower\Connector\Model\Organization\OrganizationStateRepository;
use Watchtower\Connector\Model\CheckoutFailure\Evaluator as CheckoutFailureEvaluator;
use Watchtower\Connector\Model\RateSignal\DispersionEvaluator;
use Watchtower\Connector\Model\ReportingService;
use Watchtower\Connector\Model\Rollup\RollupRepository;
use Watchtower\Connector\Model\Seed\HistorySeeder;
use Watchtower\Connector\Model\Seed\SeedCoverageRepository;
use Watchtower\Connector\Model\Seed\SeedCoverageResult;
use Watchtower\Connector\Model\Seed\SeedCoverageStatus;
use Watchtower\Connector\Model\Signal\BasketQuoteReader;
use Watchtower\Connector\Model\Signal\CheckoutReader;
use Watchtower\Connector\Model\Signal\CustomerAccountReader;
use Watchtower\Connector\Model\StoreView\LiveStoreViewResolver;
use Watchtower\Connector\Test\Unit\StoreStubTrait;

/**
 * The isEnabled()/isConfigured() gates exist so a store that hasn't set
 * this up (or has deliberately switched it off) never evaluates or
 * submits. "Never evaluates" matters beyond just not making an HTTP call:
 * Evaluator::evaluate() persists a new HealthState (and advances
 * sequence_number) as a side effect of being called at all.
 *
 * Buffering is whole-buffer, not per-report: a submission must NEVER
 * include a fresh report while excluding any currently-buffered one, or
 * the platform's sequence check permanently rejects the older report on
 * its next retry.
 *
 * Tests that don't care about store-view signals wire the store manager
 * to report zero live store views, via configuredAndEnabled()'s default,
 * so $outcome['report'] is always cron_health at index 0.
 */
class ReportingServiceTest extends TestCase
{
    use StoreStubTrait;

    /** The configured integration_health source the evidence-snapshot tests share. */
    private const SNAPSHOT_JOB_CODE = 'partner_feed_export';

    public function testNotConfiguredSkipsWithoutEvaluatingOrTouchingTheBuffer(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn(false);

        $evaluator = $this->createMock(Evaluator::class);
        $evaluator->expects(self::never())->method('evaluate');

        $bufferRepository = $this->createMock(ReportBufferRepository::class);
        $bufferRepository->expects(self::never())->method('discardExpired');
        $bufferRepository->expects(self::never())->method('isDue');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects(self::never())->method('getStores');

        $outcome = $this->service(
            config: $config,
            evaluator: $evaluator,
            bufferRepository: $bufferRepository,
            storeManager: $storeManager,
        )->run();

        self::assertFalse($outcome['ran']);
        self::assertSame('not configured', $outcome['skippedReason']);
    }

    public function testDisabledSkipsWithoutEvaluatingOrTouchingTheBufferEvenWhenConfigured(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn(true);
        $config->method('isEnabled')->willReturn(false);

        $evaluator = $this->createMock(Evaluator::class);
        $evaluator->expects(self::never())->method('evaluate');

        $bufferRepository = $this->createMock(ReportBufferRepository::class);
        $bufferRepository->expects(self::never())->method('isDue');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects(self::never())->method('getStores');

        $outcome = $this->service(
            config: $config,
            evaluator: $evaluator,
            bufferRepository: $bufferRepository,
            storeManager: $storeManager,
        )->run();

        self::assertFalse($outcome['ran']);
        self::assertSame('disabled', $outcome['skippedReason']);
    }

    public function testWithAnEmptyBufferAndDueSubmitsJustTheFreshReport(): void
    {
        $config = $this->configuredAndEnabled();
        $report = $this->report(sequenceNumber: 12);

        $evaluator = $this->createMock(Evaluator::class);
        $evaluator->expects(self::once())->method('evaluate')->willReturn($report);

        $bufferRepository = $this->createMock(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(true);
        $bufferRepository->method('allBuffered')->with(500)->willReturn([]);
        $bufferRepository->expects(self::never())->method('bufferReport');
        // Never called: an empty buffer means there is nothing to delete --
        // ReportingService now guards this call rather than issuing a
        // pointless deleteDelivered([]) every iteration a fresh report is
        // submitted alone (also avoids double-counting in a multi-request
        // cycle where a later iteration submits fresh reports with no
        // buffered rows alongside them).
        $bufferRepository->expects(self::never())->method('deleteDelivered');
        $bufferRepository->expects(self::once())->method('clearBackoff');

        $result = new MetricsSubmissionResult(succeeded: true, accepted: 1, rejected: []);
        $submissionService = $this->createMock(MetricsSubmissionService::class);
        $submissionService->expects(self::once())
            ->method('submit')
            ->with('https://watchtower.test', 'secret-api-key-value', [$report])
            ->willReturn($result);

        $outcome = $this->service(
            config: $config,
            evaluator: $evaluator,
            submissionService: $submissionService,
            bufferRepository: $bufferRepository,
        )->run();

        self::assertTrue($outcome['ran']);
        self::assertSame($report, $outcome['report']);
        self::assertSame([], $outcome['storeViewReports']);
        self::assertSame($result, $outcome['result']);
        self::assertSame(0, $outcome['includedBufferedCount']);
        self::assertSame(0, $outcome['expiredBufferedCount']);
    }

    /**
     * Not due: the fresh report must NOT be submitted alone. Sending it
     * by itself would repeat exactly the ordering hazard buffering exists
     * to prevent (it would advance the platform's high-water mark past
     * whatever is still queued).
     */
    public function testWhenNotDueTheFreshReportIsBufferedRatherThanSubmittedAlone(): void
    {
        $config = $this->configuredAndEnabled();
        $report = $this->report(sequenceNumber: 30);

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($report);

        $bufferRepository = $this->createMock(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(false);
        $bufferRepository->expects(self::never())->method('allBuffered');
        $bufferRepository->expects(self::once())->method('bufferReport')->with($report)->willReturn(0);

        $submissionService = $this->createMock(MetricsSubmissionService::class);
        $submissionService->expects(self::never())->method('submit');

        $outcome = $this->service(
            config: $config,
            evaluator: $evaluator,
            submissionService: $submissionService,
            bufferRepository: $bufferRepository,
        )->run();

        self::assertTrue($outcome['ran']);
        self::assertNull($outcome['result']);
        self::assertSame(0, $outcome['includedBufferedCount']);
        self::assertFalse($outcome['organizationPaused'], 'This is the backoff case, not the paused case.');
    }

    /**
     * Even though the buffer itself is due, a known-paused organization
     * must never reach MetricsSubmissionService::submit() at all -- the
     * fresh report still gets buffered (evaluation/state still advance,
     * matching normal backfill-on-reconnect semantics), just never
     * attempted.
     */
    public function testWhenTheOrganizationIsPausedTheFreshReportIsBufferedRatherThanSubmitted(): void
    {
        $config = $this->configuredAndEnabled();
        $report = $this->report(sequenceNumber: 40);

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($report);

        $bufferRepository = $this->createMock(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(true);
        $bufferRepository->expects(self::never())->method('allBuffered');
        $bufferRepository->expects(self::once())->method('bufferReport')->with($report)->willReturn(0);

        $submissionService = $this->createMock(MetricsSubmissionService::class);
        $submissionService->expects(self::never())->method('submit');

        $organizationStateRepository = $this->createStub(OrganizationStateRepository::class);
        $organizationStateRepository->method('isPaused')->willReturn(true);

        $outcome = $this->service(
            config: $config,
            evaluator: $evaluator,
            submissionService: $submissionService,
            bufferRepository: $bufferRepository,
            organizationStateRepository: $organizationStateRepository,
        )->run();

        self::assertTrue($outcome['ran']);
        self::assertNull($outcome['result']);
        self::assertSame(0, $outcome['includedBufferedCount']);
        self::assertTrue($outcome['organizationPaused']);
    }

    /**
     * The skip reason (paused vs backoff) is logged at debug level,
     * distinguishing the two even though they share the same
     * buffer-everything code path.
     */
    public function testLogsWhichReasonCausedTheSkip(): void
    {
        $config = $this->configuredAndEnabled();
        $report = $this->report(sequenceNumber: 41);

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($report);

        $bufferRepository = $this->createStub(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(true);

        $organizationStateRepository = $this->createStub(OrganizationStateRepository::class);
        $organizationStateRepository->method('isPaused')->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug')->with(
            'Watchtower reporting cycle skipped submission.',
            ['reason' => 'organization_paused', 'freshReportCount' => 1]
        );

        $this->service(
            config: $config,
            evaluator: $evaluator,
            bufferRepository: $bufferRepository,
            organizationStateRepository: $organizationStateRepository,
            logger: $logger,
        )->run();
    }

    /**
     * PRD FR25: a connector below the platform's minimum_version stops
     * submitting entirely. It shares the paused branch's
     * buffer-everything-but-never-attempt shape rather than skipping the
     * cycle outright, so evaluation and sequence numbers keep advancing and
     * the backlog is delivered intact once the module is upgraded.
     */
    public function testWhenBelowTheMinimumVersionTheFreshReportIsBufferedRatherThanSubmitted(): void
    {
        $config = $this->configuredAndEnabled();
        $report = $this->report(sequenceNumber: 42);

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($report);

        $bufferRepository = $this->createMock(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(true);
        $bufferRepository->expects(self::never())->method('allBuffered');
        $bufferRepository->expects(self::once())->method('bufferReport')->with($report)->willReturn(0);

        $submissionService = $this->createMock(MetricsSubmissionService::class);
        $submissionService->expects(self::never())->method('submit');

        $outcome = $this->service(
            config: $config,
            evaluator: $evaluator,
            submissionService: $submissionService,
            bufferRepository: $bufferRepository,
            connectorVersionStateRepository: $this->connectorVersionStateRepositoryStub(belowMinimum: true),
        )->run();

        self::assertTrue($outcome['ran']);
        self::assertNull($outcome['result']);
        self::assertTrue($outcome['belowMinimumVersion']);
        self::assertFalse($outcome['organizationPaused'], 'This is the version case, not the paused case.');
    }

    /**
     * Self-disabled outranks paused in the logged reason: both stop
     * submission, but only one of them is fixed by upgrading, so support
     * reading the log needs the actionable reason rather than whichever
     * gate happens to be checked first.
     */
    public function testTheBelowMinimumVersionSkipReasonOutranksThePausedOneInTheLog(): void
    {
        $config = $this->configuredAndEnabled();

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($this->report(sequenceNumber: 43));

        $bufferRepository = $this->createStub(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(true);

        $organizationStateRepository = $this->createStub(OrganizationStateRepository::class);
        $organizationStateRepository->method('isPaused')->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug')->with(
            'Watchtower reporting cycle skipped submission.',
            ['reason' => 'connector_below_minimum_version', 'freshReportCount' => 1]
        );

        $this->service(
            config: $config,
            evaluator: $evaluator,
            bufferRepository: $bufferRepository,
            organizationStateRepository: $organizationStateRepository,
            logger: $logger,
            connectorVersionStateRepository: $this->connectorVersionStateRepositoryStub(belowMinimum: true),
        )->run();
    }

    /**
     * FR24 ties the check to the report cycle and deliberately does not gate
     * it on organization_paused: a paused install that is also below the
     * minimum still has to learn it must upgrade, and a self-disabled one
     * has to keep checking so it recovers on its own once upgraded.
     */
    public function testTheVersionCheckRunsAndIsPersistedEvenWhileTheOrganizationIsPaused(): void
    {
        $config = $this->configuredAndEnabled();

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($this->report(sequenceNumber: 44));

        $bufferRepository = $this->createStub(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(true);

        $organizationStateRepository = $this->createStub(OrganizationStateRepository::class);
        $organizationStateRepository->method('isPaused')->willReturn(true);

        $connectorVersionCheckService = $this->createMock(ConnectorVersionCheckService::class);
        $connectorVersionCheckService->expects(self::once())->method('check')
            ->with('https://watchtower.test', 'secret-api-key-value')
            ->willReturn(new ConnectorVersionCheckResult(
                succeeded: true,
                installedVersion: '1.0.0',
                minimumVersion: '1.2.0',
                latestVersion: '1.3.0',
                belowMinimum: true,
                updateAvailable: true,
            ));

        $connectorVersionStateRepository = $this->createMock(ConnectorVersionStateRepository::class);
        $connectorVersionStateRepository->expects(self::once())->method('save')->with(
            '1.0.0',
            '1.2.0',
            '1.3.0',
            true,
            true,
            self::isInstanceOf(\DateTimeImmutable::class)
        );
        $connectorVersionStateRepository->method('get')->willReturn(new ConnectorVersionState(
            installedVersion: '1.0.0',
            minimumVersion: '1.2.0',
            latestVersion: '1.3.0',
            belowMinimum: true,
            updateAvailable: true,
            checkedAt: new \DateTimeImmutable('2026-08-13T15:00:00+00:00'),
        ));

        $this->service(
            config: $config,
            evaluator: $evaluator,
            bufferRepository: $bufferRepository,
            organizationStateRepository: $organizationStateRepository,
            connectorVersionCheckService: $connectorVersionCheckService,
            connectorVersionStateRepository: $connectorVersionStateRepository,
        )->run();
    }

    /**
     * A failed check (network error, non-200) carries no verdict at all, so
     * it must leave the persisted state alone: overwriting it would either
     * silently un-disable a connector the platform has disowned, or -- worse
     * -- self-disable a healthy one every time the platform is briefly
     * unreachable.
     */
    public function testAFailedVersionCheckNeverOverwritesThePersistedState(): void
    {
        $config = $this->configuredAndEnabled();

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($this->report(sequenceNumber: 45));

        $bufferRepository = $this->createStub(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(true);

        $connectorVersionCheckService = $this->createStub(ConnectorVersionCheckService::class);
        $connectorVersionCheckService->method('check')->willReturn(new ConnectorVersionCheckResult(
            succeeded: false,
            installedVersion: '1.0.0',
            errorMessage: 'Connection refused',
        ));

        $connectorVersionStateRepository = $this->createMock(ConnectorVersionStateRepository::class);
        $connectorVersionStateRepository->expects(self::never())->method('save');
        $connectorVersionStateRepository->method('get')->willReturn(new ConnectorVersionState(
            installedVersion: '1.0.0',
            minimumVersion: '1.2.0',
            latestVersion: '1.2.0',
            belowMinimum: true,
            updateAvailable: true,
            checkedAt: new \DateTimeImmutable('2026-08-13T09:00:00+00:00'),
        ));

        $outcome = $this->service(
            config: $config,
            evaluator: $evaluator,
            bufferRepository: $bufferRepository,
            connectorVersionCheckService: $connectorVersionCheckService,
            connectorVersionStateRepository: $connectorVersionStateRepository,
        )->run();

        self::assertTrue(
            $outcome['belowMinimumVersion'],
            'The last successful verdict still stands; a failed check is not a recovery.'
        );
    }

    public function testDueWithBufferedReportsSubmitsThemAllTogetherWithTheFreshReportAndClearsOnSuccess(): void
    {
        $config = $this->configuredAndEnabled();
        $freshReport = $this->report(sequenceNumber: 25);

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($freshReport);

        $buffered1 = new BufferedReport(bufferId: 3, report: $this->report(sequenceNumber: 22));
        $buffered2 = new BufferedReport(bufferId: 4, report: $this->report(sequenceNumber: 23));

        $bufferRepository = $this->createMock(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(true);
        // 2nd call empty: the whole buffer plus the single fresh report fit
        // in one request, so nothing is left for a 2nd loop iteration to
        // find -- simulates the 1st request's deleteDelivered() removing
        // these rows from the real table.
        $bufferRepository->method('allBuffered')->willReturnOnConsecutiveCalls([$buffered1, $buffered2], []);
        $bufferRepository->expects(self::once())->method('deleteDelivered')->with([3, 4]);
        $bufferRepository->expects(self::once())->method('clearBackoff');
        $bufferRepository->expects(self::never())->method('bufferReport');
        $bufferRepository->expects(self::never())->method('recordFailure');

        $result = new MetricsSubmissionResult(succeeded: true, accepted: 3, rejected: []);
        $submissionService = $this->createMock(MetricsSubmissionService::class);
        $submissionService->expects(self::once())
            ->method('submit')
            ->with(
                'https://watchtower.test',
                'secret-api-key-value',
                [$buffered1->report, $buffered2->report, $freshReport]
            )
            ->willReturn($result);

        $outcome = $this->service(
            config: $config,
            evaluator: $evaluator,
            submissionService: $submissionService,
            bufferRepository: $bufferRepository,
        )->run();

        self::assertTrue($outcome['result']->succeeded);
        self::assertSame(2, $outcome['includedBufferedCount']);
    }

    public function testATransportFailureRecordsOneBackoffAndBuffersTheFreshReport(): void
    {
        $config = $this->configuredAndEnabled();
        $freshReport = $this->report(sequenceNumber: 20);

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($freshReport);

        $buffered = new BufferedReport(bufferId: 7, report: $this->report(sequenceNumber: 18));

        $bufferRepository = $this->createMock(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(true);
        $bufferRepository->method('allBuffered')->willReturn([$buffered]);
        $bufferRepository->expects(self::never())->method('deleteDelivered');
        $bufferRepository->expects(self::never())->method('clearBackoff');
        $bufferRepository->expects(self::once())
            ->method('recordFailure')
            ->with(30, self::isInstanceOf(\DateTimeImmutable::class));
        // The already-buffered report stays as-is (no per-row bookkeeping
        // to touch); only the fresh report, which was part of the same
        // failed attempt, needs to be added to the buffer.
        $bufferRepository->expects(self::once())->method('bufferReport')->with($freshReport)->willReturn(0);

        $result = new MetricsSubmissionResult(succeeded: false, errorMessage: 'HTTP 429', retryAfterSeconds: 30);
        $submissionService = $this->createMock(MetricsSubmissionService::class);
        $submissionService->expects(self::once())
            ->method('submit')
            ->with('https://watchtower.test', 'secret-api-key-value', [$buffered->report, $freshReport])
            ->willReturn($result);

        $outcome = $this->service(
            config: $config,
            evaluator: $evaluator,
            submissionService: $submissionService,
            bufferRepository: $bufferRepository,
        )->run();

        self::assertFalse($outcome['result']->succeeded);
        // 0, not 1: includedBufferedCount counts buffered reports CONFIRMED
        // delivered this cycle, not merely attempted -- this attempt failed,
        // so the already-buffered report was not durably delivered (it stays
        // in the buffer untouched, to be retried next cycle).
        self::assertSame(0, $outcome['includedBufferedCount']);
    }

    /**
     * The buffer alone already fills the 500-report cap, so the fresh
     * report cannot ride along in the SAME request -- appending it would
     * push the batch over the cap or let it jump ahead of older still-queued
     * reports. run() loops submit() within the same cycle
     * (ReportingService::MAX_SUBMISSIONS_PER_CYCLE), so this resolves in TWO
     * requests in the same run(), never buffering the fresh report at all.
     */
    public function testWhenTheBufferAloneFillsTheCapTheFreshReportGoesOutInASecondRequestTheSameCycle(): void
    {
        $config = $this->configuredAndEnabled();
        $freshReport = $this->report(sequenceNumber: 999);
        $fullBuffer = array_map(
            fn (int $i) => new BufferedReport(bufferId: $i, report: $this->report(sequenceNumber: $i)),
            range(1, 500)
        );

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($freshReport);

        $bufferRepository = $this->createMock(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(true);
        // 1st call (this cycle's own attempt): the full, capacity-filling
        // buffer. 2nd/3rd calls: empty, simulating that the 1st request's
        // deleteDelivered() actually removed those rows from the real table.
        $bufferRepository->method('allBuffered')->willReturnOnConsecutiveCalls($fullBuffer, [], []);
        $bufferRepository->expects(self::once())->method('deleteDelivered')->with(range(1, 500));
        $bufferRepository->expects(self::once())->method('clearBackoff');
        // Never buffered: the 2nd request within this same cycle delivers
        // it instead, now that the buffer that filled the cap is gone.
        $bufferRepository->expects(self::never())->method('bufferReport');

        $result = new MetricsSubmissionResult(succeeded: true, accepted: 500, rejected: []);
        $capturedBatches = [];
        $submissionService = $this->createMock(MetricsSubmissionService::class);
        $submissionService->expects(self::exactly(2))
            ->method('submit')
            ->willReturnCallback(function (
                string $baseUrl,
                string $apiKey,
                array $batch
            ) use (
                &$capturedBatches,
                $result
            ) {
                $capturedBatches[] = $batch;

                return $result;
            });

        $outcome = $this->service(
            config: $config,
            evaluator: $evaluator,
            submissionService: $submissionService,
            bufferRepository: $bufferRepository,
        )->run();

        self::assertCount(500, $capturedBatches[0], 'First request: the full buffer, no fresh report.');
        self::assertFalse(in_array($freshReport, $capturedBatches[0], true));
        self::assertSame(
            [$freshReport],
            $capturedBatches[1],
            'Second request: the fresh report alone, once the buffer is drained.'
        );
        self::assertSame(500, $outcome['includedBufferedCount']);
    }

    /**
     * When an EARLIER iteration this cycle already succeeded and a LATER
     * one then fails (a full buffer delivers in request 1, a held-back
     * fresh report then hits a 429/5xx in request 2), the failure's
     * recordFailure() must survive rather than being overwritten by a
     * clearBackoff() gated on "did anything succeed THIS CYCLE" rather
     * than "how did the cycle actually end". Such a gate would discard the
     * Retry-After header, reset attempt_count so exponential backoff could
     * never escalate, and stamp last_success_at for a cycle that ended in
     * failure -- all three checked explicitly below.
     */
    public function testAFailureAfterAnEarlierSuccessTheSameCycleStillRecordsBackoffCorrectly(): void
    {
        $config = $this->configuredAndEnabled();
        $freshReport = $this->report(sequenceNumber: 999);
        $fullBuffer = array_map(
            fn (int $i) => new BufferedReport(bufferId: $i, report: $this->report(sequenceNumber: $i)),
            range(1, 500)
        );

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($freshReport);

        $bufferRepository = $this->createMock(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(true);
        // 1st call: the full, capacity-filling buffer (delivered in
        // request 1). 2nd call: empty, since request 1's deleteDelivered()
        // removed those rows -- request 2 then carries the fresh report
        // alone and fails.
        $bufferRepository->method('allBuffered')->willReturnOnConsecutiveCalls($fullBuffer, []);
        $bufferRepository->expects(self::once())->method('deleteDelivered')->with(range(1, 500));
        // The whole point of this test: NOT called, even though request 1
        // succeeded -- the cycle as a whole ended in failure.
        $bufferRepository->expects(self::never())->method('clearBackoff');
        $bufferRepository->expects(self::once())
            ->method('recordFailure')
            ->with(45, self::isInstanceOf(\DateTimeImmutable::class));
        // Not durably delivered (request 2 failed) -- buffered for retry.
        $bufferRepository->expects(self::once())->method('bufferReport')->with($freshReport)->willReturn(0);

        $succeeded = new MetricsSubmissionResult(succeeded: true, accepted: 500, rejected: []);
        $failed = new MetricsSubmissionResult(succeeded: false, errorMessage: 'HTTP 429', retryAfterSeconds: 45);
        $submissionService = $this->createMock(MetricsSubmissionService::class);
        $submissionService->expects(self::exactly(2))
            ->method('submit')
            ->willReturnOnConsecutiveCalls($succeeded, $failed);

        $outcome = $this->service(
            config: $config,
            evaluator: $evaluator,
            submissionService: $submissionService,
            bufferRepository: $bufferRepository,
        )->run();

        self::assertFalse(
            $outcome['result']->succeeded,
            'The cycle ended in failure, even though it began with a success.'
        );
        self::assertSame(500, $outcome['includedBufferedCount']);
    }

    /**
     * The partial case: the buffer does NOT already fill the cap on its
     * own, so SOME but not all of this cycle's fresh reports fit in the
     * FIRST request. 498 buffered + 4 fresh (cron_health plus 3 store-view
     * reports) leaves exactly 2 slots in that first request; the first 2
     * fresh reports (in the same order run() always produces them:
     * cron_health first, then basket_quote/checkout/customer_account) go
     * out there. The remaining 2 go out in a SECOND request the same
     * run(), never touching the buffer.
     */
    public function testWhenSomeFreshReportsDontFitTheFirstRequestTheyGoOutInASecondRequestSameCycle(): void
    {
        $config = $this->configuredAndEnabled();
        $cronHealthReport = $this->report(sequenceNumber: 999);
        $basketQuoteStoreReport = $this->storeViewReport(HistorySeeder::CATEGORY_BASKET_QUOTE, 'default');
        $checkoutStoreReport = $this->storeViewReport(HistorySeeder::CATEGORY_CHECKOUT, 'default');
        $customerAccountStoreReport = $this->storeViewReport(HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT, 'default');
        $checkoutFailureStoreReport = $this->storeViewReport('checkout_failure', 'default');

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($cronHealthReport);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $basketQuoteReader = $this->createStub(BasketQuoteReader::class);
        $basketQuoteReader->method('countForWindow')->willReturn(3);
        $checkoutReader = $this->createStub(CheckoutReader::class);
        $checkoutReader->method('countForWindow')->willReturn(2);
        $customerAccountReader = $this->createStub(CustomerAccountReader::class);
        $customerAccountReader->method('countForWindow')->willReturn(1);

        $dispersionEvaluator = $this->createStub(DispersionEvaluator::class);
        $dispersionEvaluator->method('evaluate')->willReturnCallback(
            fn (int $storeViewId, string $storeViewCode, string $category): MetricReport => match ($category) {
                HistorySeeder::CATEGORY_BASKET_QUOTE => $basketQuoteStoreReport,
                HistorySeeder::CATEGORY_CHECKOUT => $checkoutStoreReport,
                HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT => $customerAccountStoreReport,
            }
        );

        $bufferedCount = 498;
        $buffered = array_map(
            fn (int $i) => new BufferedReport(bufferId: $i, report: $this->report(sequenceNumber: $i)),
            range(1, $bufferedCount)
        );

        $bufferRepository = $this->createMock(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(true);
        // 1st call: the 498-row buffer. 2nd/3rd calls: empty, simulating
        // that the 1st request's deleteDelivered() removed those rows.
        $bufferRepository->method('allBuffered')->willReturnOnConsecutiveCalls($buffered, [], []);
        $bufferRepository->expects(self::once())->method('deleteDelivered')->with(range(1, $bufferedCount));
        $bufferRepository->expects(self::once())->method('clearBackoff');
        // Never buffered: both go out in the 2nd request this same cycle.
        $bufferRepository->expects(self::never())->method('bufferReport');

        $result = new MetricsSubmissionResult(succeeded: true, accepted: 500, rejected: []);
        $capturedBatches = [];
        $submissionService = $this->createMock(MetricsSubmissionService::class);
        $submissionService->expects(self::exactly(2))
            ->method('submit')
            ->willReturnCallback(function (
                string $baseUrl,
                string $apiKey,
                array $batch
            ) use (
                &$capturedBatches,
                $result
            ) {
                $capturedBatches[] = $batch;

                return $result;
            });

        $this->service(
            config: $config,
            evaluator: $evaluator,
            submissionService: $submissionService,
            bufferRepository: $bufferRepository,
            storeManager: $storeManager,
            basketQuoteReader: $basketQuoteReader,
            checkoutReader: $checkoutReader,
            customerAccountReader: $customerAccountReader,
            dispersionEvaluator: $dispersionEvaluator,
            checkoutFailureEvaluator: $this->checkoutFailureEvaluatorReturning($checkoutFailureStoreReport),
        )->run();

        self::assertCount(500, $capturedBatches[0]);
        self::assertTrue(in_array($cronHealthReport, $capturedBatches[0], true));
        self::assertTrue(in_array($basketQuoteStoreReport, $capturedBatches[0], true));
        self::assertFalse(in_array($checkoutStoreReport, $capturedBatches[0], true));
        self::assertFalse(in_array($customerAccountStoreReport, $capturedBatches[0], true));
        self::assertFalse(in_array($checkoutFailureStoreReport, $capturedBatches[0], true));
        self::assertSame(
            [$checkoutStoreReport, $customerAccountStoreReport, $checkoutFailureStoreReport],
            $capturedBatches[1]
        );
    }

    /**
     * A 200 response clears buffered reports from the retry queue even when
     * some come back in rejected[]: delivery succeeded, so retrying is
     * pointless. The rejection is still surfaced via $result->rejected for
     * whichever caller logs it (ReportJob/ReportCommand), not silently
     * dropped.
     */
    public function testABatchThatDeliversButPartiallyRejectsStillClearsAllIncludedBufferedReports(): void
    {
        $config = $this->configuredAndEnabled();
        $freshReport = $this->report(sequenceNumber: 30);

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($freshReport);

        $buffered = new BufferedReport(bufferId: 9, report: $this->report(sequenceNumber: 29));

        $bufferRepository = $this->createMock(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(true);
        // 2nd call empty, simulating that the 1st request's
        // deleteDelivered() removed this row from the real table.
        $bufferRepository->method('allBuffered')->willReturnOnConsecutiveCalls([$buffered], []);
        $bufferRepository->expects(self::once())->method('deleteDelivered')->with([9]);
        $bufferRepository->expects(self::once())->method('clearBackoff');

        $result = new MetricsSubmissionResult(
            succeeded: true,
            accepted: 1,
            rejected: [['event_type' => 'cron_health', 'sequence_number' => 29, 'reason' => 'stale_evaluation']],
        );
        $submissionService = $this->createStub(MetricsSubmissionService::class);
        $submissionService->method('submit')->willReturn($result);

        $outcome = $this->service(
            config: $config,
            evaluator: $evaluator,
            submissionService: $submissionService,
            bufferRepository: $bufferRepository,
        )->run();

        self::assertSame(
            [['event_type' => 'cron_health', 'sequence_number' => 29, 'reason' => 'stale_evaluation']],
            $outcome['result']->rejected
        );
    }

    public function testExpiredBufferedCountIsSurfacedRegardlessOfWhichBranchIsTaken(): void
    {
        $config = $this->configuredAndEnabled();
        $report = $this->report(sequenceNumber: 1);

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($report);

        $bufferRepository = $this->createMock(ReportBufferRepository::class);
        $bufferRepository->expects(self::once())->method('discardExpired')->willReturn(4);
        $bufferRepository->method('isDue')->willReturn(false);
        $bufferRepository->method('bufferReport')->willReturn(0);

        $outcome = $this->service(
            config: $config,
            evaluator: $evaluator,
            bufferRepository: $bufferRepository,
        )->run();

        self::assertSame(4, $outcome['expiredBufferedCount']);
    }

    /**
     * Every live store view's basket_quote/checkout/customer_account gets
     * recorded into the rollup store and evaluated, alongside cron_health,
     * in the same cycle; a disabled store view is skipped entirely, same
     * filter as StoreViewSyncService.
     *
     * The evaluation must cover the last COMPLETE hour, never the hour
     * still in progress -- comparing a partial hour against full-hour
     * historical baselines produces a structural false DROP proportional
     * to how much of the hour remains. $expectedWindowStart/
     * $expectedWindowEnd below are computed independently of
     * ReportingService's own logic and asserted against every
     * countForWindow()/recordHourlyCount()/evaluate() call, rather than
     * merely asserting "some DateTimeImmutable".
     */
    public function testEvaluatesAndSubmitsStoreViewSignalReportsForEveryLiveStoreView(): void
    {
        $config = $this->configuredAndEnabled();
        $cronHealthReport = $this->report(sequenceNumber: 5);

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($cronHealthReport);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([
            $this->activeStore('default'),
            $this->inactiveStore('disabled_view'),
        ]);

        // Computed independently of ReportingService's own window logic, at
        // the same top-of-hour granularity: the current hour has NOT yet
        // been reached by the window under test, only the one before it.
        $currentHourStart = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            (new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:00:00'),
            new \DateTimeZone('UTC')
        );
        $expectedWindowEnd = $currentHourStart;
        $expectedWindowStart = $currentHourStart->modify('-1 hour');

        $basketQuoteReader = $this->createMock(BasketQuoteReader::class);
        $basketQuoteReader->expects(self::once())->method('countForWindow')
            ->with(1, $expectedWindowStart, $expectedWindowEnd)->willReturn(3);

        $checkoutReader = $this->createMock(CheckoutReader::class);
        $checkoutReader->expects(self::once())->method('countForWindow')
            ->with(1, $expectedWindowStart, $expectedWindowEnd)->willReturn(2);

        $customerAccountReader = $this->createMock(CustomerAccountReader::class);
        $customerAccountReader->expects(self::once())->method('countForWindow')
            ->with(1, $expectedWindowStart, $expectedWindowEnd)->willReturn(1);

        $rollupRepository = $this->createMock(RollupRepository::class);
        $rollupRepository->expects(self::exactly(3))->method('recordHourlyCount')->with(
            1,
            self::isString(),
            $expectedWindowStart,
            self::isInt()
        );

        $basketQuoteStoreReport = $this->storeViewReport(HistorySeeder::CATEGORY_BASKET_QUOTE, 'default');
        $checkoutStoreReport = $this->storeViewReport(HistorySeeder::CATEGORY_CHECKOUT, 'default');
        $customerAccountStoreReport = $this->storeViewReport(HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT, 'default');
        $checkoutFailureStoreReport = $this->storeViewReport('checkout_failure', 'default');

        // A callback (not willReturnMap) since the exact \DateTimeImmutable
        // instance passed as $evaluatedAt (real wall-clock time) can't be
        // predicted as a literal map key; $evaluatedHourStart CAN be and is
        // asserted exactly, since it must be the computed H-1, not "now".
        $dispersionEvaluator = $this->createMock(DispersionEvaluator::class);
        $dispersionEvaluator->expects(self::exactly(3))
            ->method('evaluate')
            ->willReturnCallback(
                function (
                    int $storeViewId,
                    string $storeViewCode,
                    string $category,
                    int $observedCount,
                    \DateTimeImmutable $evaluatedHourStart,
                    \DateTimeImmutable $evaluatedAt
                ) use (
                    $expectedWindowStart,
                    $basketQuoteStoreReport,
                    $checkoutStoreReport,
                    $customerAccountStoreReport
                ): MetricReport {
                    self::assertSame(1, $storeViewId);
                    self::assertSame('default', $storeViewCode);
                    self::assertEquals($expectedWindowStart, $evaluatedHourStart);

                    return match ($category) {
                        HistorySeeder::CATEGORY_BASKET_QUOTE => $basketQuoteStoreReport,
                        HistorySeeder::CATEGORY_CHECKOUT => $checkoutStoreReport,
                        HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT => $customerAccountStoreReport,
                    };
                }
            );

        $bufferRepository = $this->createMock(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(true);
        $bufferRepository->method('allBuffered')->with(500)->willReturn([]);
        // An empty buffer means there is nothing to delete -- see this
        // file's other "never called" deleteDelivered() assertions for why.
        $bufferRepository->expects(self::never())->method('deleteDelivered');
        $bufferRepository->expects(self::once())->method('clearBackoff');

        $result = new MetricsSubmissionResult(succeeded: true, accepted: 4, rejected: []);
        $submissionService = $this->createMock(MetricsSubmissionService::class);
        $submissionService->expects(self::once())
            ->method('submit')
            ->with(
                'https://watchtower.test',
                'secret-api-key-value',
                [
                    $cronHealthReport,
                    $basketQuoteStoreReport,
                    $checkoutStoreReport,
                    $customerAccountStoreReport,
                    $checkoutFailureStoreReport,
                ]
            )
            ->willReturn($result);

        $outcome = $this->service(
            config: $config,
            evaluator: $evaluator,
            submissionService: $submissionService,
            bufferRepository: $bufferRepository,
            storeManager: $storeManager,
            basketQuoteReader: $basketQuoteReader,
            checkoutReader: $checkoutReader,
            customerAccountReader: $customerAccountReader,
            rollupRepository: $rollupRepository,
            dispersionEvaluator: $dispersionEvaluator,
            checkoutFailureEvaluator: $this->checkoutFailureEvaluatorReturning($checkoutFailureStoreReport),
        )->run();

        self::assertSame($cronHealthReport, $outcome['report']);
        self::assertSame(
            [
                $basketQuoteStoreReport,
                $checkoutStoreReport,
                $customerAccountStoreReport,
                $checkoutFailureStoreReport,
            ],
            $outcome['storeViewReports']
        );
    }

    /**
     * Regression coverage for the false-anomaly bug this closes: a store
     * view with no local rollup history yet (a fresh Watchtower activation,
     * or a newly-added store view) must be seeded automatically, not only
     * via the manual `watchtower:coverage` command -- an install that never
     * had it run by hand previously cold-started DispersionEvaluator on a
     * near-empty baseline and could confirm a false SEVERE_DROP off noise.
     */
    public function testSeedsHistoricalBaselineForAStoreViewWithNoExistingRollupData(): void
    {
        $config = $this->configuredAndEnabled();

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($this->report(sequenceNumber: 1));

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $rollupRepository = $this->createMock(RollupRepository::class);
        $rollupRepository->expects(self::once())
            ->method('hasAnyHourlyDataForCategories')
            ->with(1, [
                HistorySeeder::CATEGORY_BASKET_QUOTE,
                HistorySeeder::CATEGORY_CHECKOUT,
                HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT,
            ])
            ->willReturn(false);

        $historySeeder = $this->createMock(HistorySeeder::class);
        // Production code computes seed()'s 3rd argument by calling
        // defaultBaselineWindowDays() on this same mock -- an unconfigured
        // mocked method defaults to 0, not the real formula's 84, so this
        // must be stubbed for the ->with() assertion below to reflect what
        // ReportingService actually passes.
        $historySeeder->method('defaultBaselineWindowDays')->willReturn(84);
        $seedResult = new SeedCoverageResult(
            category: HistorySeeder::CATEGORY_BASKET_QUOTE,
            requestedDays: 84,
            daysSeeded: 26,
            status: SeedCoverageStatus::Seeded,
        );
        $historySeeder->expects(self::once())
            ->method('seed')
            ->with(1, self::isInstanceOf(\DateTimeImmutable::class), 84)
            ->willReturn([HistorySeeder::CATEGORY_BASKET_QUOTE => $seedResult]);

        $seedCoverageRepository = $this->createMock(SeedCoverageRepository::class);
        $seedCoverageRepository->expects(self::once())->method('save')->with(1, $seedResult);

        $dispersionEvaluator = $this->createStub(DispersionEvaluator::class);
        $dispersionEvaluator->method('evaluate')->willReturn(
            $this->storeViewReport(HistorySeeder::CATEGORY_BASKET_QUOTE, 'default')
        );

        $this->service(
            config: $config,
            evaluator: $evaluator,
            storeManager: $storeManager,
            rollupRepository: $rollupRepository,
            dispersionEvaluator: $dispersionEvaluator,
            historySeeder: $historySeeder,
            seedCoverageRepository: $seedCoverageRepository,
        )->run();
    }

    /**
     * The common-case counterpart: a store view that already has rollup
     * data (from a previous cycle, or a prior manual coverage run) must
     * never be re-seeded on every hourly tick -- HistorySeeder::seed() is
     * idempotent but not free, and re-running it every cycle would waste a
     * full historical walk for no benefit.
     */
    public function testDoesNotReSeedAStoreViewThatAlreadyHasRollupData(): void
    {
        $config = $this->configuredAndEnabled();

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($this->report(sequenceNumber: 1));

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $rollupRepository = $this->createStub(RollupRepository::class);
        $rollupRepository->method('hasAnyHourlyDataForCategories')->willReturn(true);

        $historySeeder = $this->createMock(HistorySeeder::class);
        $historySeeder->expects(self::never())->method('seed');

        $seedCoverageRepository = $this->createMock(SeedCoverageRepository::class);
        $seedCoverageRepository->expects(self::never())->method('save');

        $dispersionEvaluator = $this->createStub(DispersionEvaluator::class);
        $dispersionEvaluator->method('evaluate')->willReturn(
            $this->storeViewReport(HistorySeeder::CATEGORY_BASKET_QUOTE, 'default')
        );

        $this->service(
            config: $config,
            evaluator: $evaluator,
            storeManager: $storeManager,
            rollupRepository: $rollupRepository,
            dispersionEvaluator: $dispersionEvaluator,
            historySeeder: $historySeeder,
            seedCoverageRepository: $seedCoverageRepository,
        )->run();
    }

    /**
     * A submission failure must buffer this ENTIRE cycle's reports
     * together (cron_health plus every store-view/category report),
     * not just cron_health alone. Nothing distinguishes "this cycle's
     * reports" from each other once an attempt has failed, so leaving any
     * of them unbuffered would silently lose that signal's transition/
     * heartbeat with no retry path.
     */
    public function testASubmissionFailureBuffersEveryReportFromTheCycleTogether(): void
    {
        $config = $this->configuredAndEnabled();
        $cronHealthReport = $this->report(sequenceNumber: 8);

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($cronHealthReport);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $basketQuoteReader = $this->createStub(BasketQuoteReader::class);
        $basketQuoteReader->method('countForWindow')->willReturn(3);

        $checkoutReader = $this->createStub(CheckoutReader::class);
        $checkoutReader->method('countForWindow')->willReturn(2);

        $customerAccountReader = $this->createStub(CustomerAccountReader::class);
        $customerAccountReader->method('countForWindow')->willReturn(1);

        $rollupRepository = $this->createStub(RollupRepository::class);

        $basketQuoteStoreReport = $this->storeViewReport(HistorySeeder::CATEGORY_BASKET_QUOTE, 'default');
        $checkoutStoreReport = $this->storeViewReport(HistorySeeder::CATEGORY_CHECKOUT, 'default');
        $customerAccountStoreReport = $this->storeViewReport(HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT, 'default');
        $checkoutFailureStoreReport = $this->storeViewReport('checkout_failure', 'default');

        $dispersionEvaluator = $this->createStub(DispersionEvaluator::class);
        $dispersionEvaluator->method('evaluate')->willReturnCallback(
            fn (int $storeViewId, string $storeViewCode, string $category): MetricReport => match ($category) {
                HistorySeeder::CATEGORY_BASKET_QUOTE => $basketQuoteStoreReport,
                HistorySeeder::CATEGORY_CHECKOUT => $checkoutStoreReport,
                HistorySeeder::CATEGORY_CUSTOMER_ACCOUNT => $customerAccountStoreReport,
            }
        );

        $bufferRepository = $this->createMock(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(true);
        $bufferRepository->method('allBuffered')->willReturn([]);
        $bufferRepository->expects(self::never())->method('deleteDelivered');
        $bufferRepository->expects(self::never())->method('clearBackoff');
        $bufferRepository->expects(self::once())
            ->method('recordFailure')
            ->with(null, self::isInstanceOf(\DateTimeImmutable::class));

        $bufferedReports = [];
        // cron_health plus four store-view reports: three rate categories and checkout_failure.
        $bufferRepository->expects(self::exactly(5))
            ->method('bufferReport')
            ->willReturnCallback(function (MetricReport $report) use (&$bufferedReports): int {
                $bufferedReports[] = $report;

                return 0;
            });

        $result = new MetricsSubmissionResult(succeeded: false, errorMessage: 'Connection refused');
        $submissionService = $this->createStub(MetricsSubmissionService::class);
        $submissionService->method('submit')->willReturn($result);

        $outcome = $this->service(
            config: $config,
            evaluator: $evaluator,
            submissionService: $submissionService,
            bufferRepository: $bufferRepository,
            storeManager: $storeManager,
            basketQuoteReader: $basketQuoteReader,
            checkoutReader: $checkoutReader,
            customerAccountReader: $customerAccountReader,
            rollupRepository: $rollupRepository,
            dispersionEvaluator: $dispersionEvaluator,
            checkoutFailureEvaluator: $this->checkoutFailureEvaluatorReturning($checkoutFailureStoreReport),
        )->run();

        self::assertFalse($outcome['result']->succeeded);
        self::assertSame(
            [
                $cronHealthReport,
                $basketQuoteStoreReport,
                $checkoutStoreReport,
                $customerAccountStoreReport,
                $checkoutFailureStoreReport,
            ],
            $bufferedReports
        );
    }

    /**
     * A store view with a configured cron_job source gets its
     * source observed via CronJobObserver (never the other two observers),
     * and the resulting report is evaluated and included in the submitted
     * batch alongside the three rate-based category reports.
     */
    public function testAStoreViewWithAConfiguredCronJobSourceIsEvaluatedViaCronJobObserver(): void
    {
        $config = $this->configuredAndEnabled();
        $cronHealthReport = $this->report(sequenceNumber: 1);

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($cronHealthReport);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $basketQuoteReader = $this->createStub(BasketQuoteReader::class);
        $basketQuoteReader->method('countForWindow')->willReturn(0);
        $checkoutReader = $this->createStub(CheckoutReader::class);
        $checkoutReader->method('countForWindow')->willReturn(0);
        $customerAccountReader = $this->createStub(CustomerAccountReader::class);
        $customerAccountReader->method('countForWindow')->willReturn(0);

        $dispersionEvaluator = $this->createStub(DispersionEvaluator::class);
        $dispersionEvaluator->method('evaluate')->willReturnCallback(
            fn (int $storeViewId, string $storeViewCode, string $category): MetricReport
                => $this->storeViewReport($category, $storeViewCode)
        );

        $integrationHealthConfig = new IntegrationHealthConfig(
            storeViewId: 1,
            sourceType: IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB,
            sourceIdentifier: 'watchtower_example_cron',
            expectedMaxIntervalMinutes: 60,
        );
        $integrationHealthConfigRepository = $this->createStub(IntegrationHealthConfigRepository::class);
        $integrationHealthConfigRepository->method('get')->willReturn($integrationHealthConfig);

        $observation = new Observation(
            latestSuccessAt: new \DateTimeImmutable('2026-08-13T14:30:00+00:00'),
            latestFailureAt: null,
        );
        $cronJobObserver = $this->createMock(CronJobObserver::class);
        $cronJobObserver->expects(self::once())->method('observe')
            ->with('watchtower_example_cron', self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn($observation);

        $queueConsumerObserver = $this->createMock(QueueConsumerObserver::class);
        $queueConsumerObserver->expects(self::never())->method('observe');

        $conventionEventReader = $this->createMock(ConventionEventReader::class);
        $conventionEventReader->expects(self::never())->method('observe');

        $integrationHealthReport = $this->integrationHealthReport('default');
        $integrationHealthEvaluator = $this->createMock(IntegrationHealthEvaluator::class);
        $integrationHealthEvaluator->expects(self::once())
            ->method('evaluate')
            ->with(
                1,
                'default',
                IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB,
                'watchtower_example_cron',
                $observation->latestSuccessAt,
                $observation->latestFailureAt,
                60,
                self::isInstanceOf(\DateTimeImmutable::class)
            )
            ->willReturn($integrationHealthReport);

        $bufferRepository = $this->createStub(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(true);
        $bufferRepository->method('allBuffered')->willReturn([]);

        $result = new MetricsSubmissionResult(succeeded: true, accepted: 5, rejected: []);
        $submissionService = $this->createMock(MetricsSubmissionService::class);
        $submissionService->expects(self::once())
            ->method('submit')
            ->with(
                'https://watchtower.test',
                'secret-api-key-value',
                self::callback(fn (array $reports) => in_array($integrationHealthReport, $reports, true))
            )
            ->willReturn($result);

        $outcome = $this->service(
            config: $config,
            evaluator: $evaluator,
            submissionService: $submissionService,
            bufferRepository: $bufferRepository,
            storeManager: $storeManager,
            basketQuoteReader: $basketQuoteReader,
            checkoutReader: $checkoutReader,
            customerAccountReader: $customerAccountReader,
            dispersionEvaluator: $dispersionEvaluator,
            integrationHealthConfigRepository: $integrationHealthConfigRepository,
            integrationHealthEvaluator: $integrationHealthEvaluator,
            cronJobObserver: $cronJobObserver,
            queueConsumerObserver: $queueConsumerObserver,
            conventionEventReader: $conventionEventReader,
        )->run();

        self::assertContains($integrationHealthReport, $outcome['storeViewReports']);
    }

    /**
     * source_type dispatch for the other two source kinds --
     * queue_consumer routes to QueueConsumerObserver and convention_event
     * routes to ConventionEventReader, never CronJobObserver.
     */
    public function testAConfiguredQueueConsumerSourceIsEvaluatedViaQueueConsumerObserver(): void
    {
        $outcome = $this->runWithIntegrationHealthSource(
            IntegrationHealthConfig::SOURCE_TYPE_QUEUE_CONSUMER,
            'async.operations.all'
        );

        self::assertContains($outcome['report'], $outcome['storeViewReports']);
    }

    public function testAConfiguredConventionEventSourceIsEvaluatedViaConventionEventReader(): void
    {
        $outcome = $this->runWithIntegrationHealthSource(
            IntegrationHealthConfig::SOURCE_TYPE_CONVENTION_EVENT,
            'erp_sync'
        );

        self::assertContains($outcome['report'], $outcome['storeViewReports']);
    }

    /**
     * A store view with no configured integration_health source
     * (the default in every other test's no-op wiring) produces exactly the
     * three rate-based category reports and nothing else -- the evaluator
     * and all three observers/readers are never touched.
     */
    public function testAStoreViewWithNoConfiguredIntegrationHealthSourceProducesNoIntegrationHealthReport(): void
    {
        $config = $this->configuredAndEnabled();
        $cronHealthReport = $this->report(sequenceNumber: 1);

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($cronHealthReport);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $basketQuoteReader = $this->createStub(BasketQuoteReader::class);
        $basketQuoteReader->method('countForWindow')->willReturn(0);
        $checkoutReader = $this->createStub(CheckoutReader::class);
        $checkoutReader->method('countForWindow')->willReturn(0);
        $customerAccountReader = $this->createStub(CustomerAccountReader::class);
        $customerAccountReader->method('countForWindow')->willReturn(0);

        $dispersionEvaluator = $this->createStub(DispersionEvaluator::class);
        $dispersionEvaluator->method('evaluate')->willReturnCallback(
            fn (int $storeViewId, string $storeViewCode, string $category): MetricReport
                => $this->storeViewReport($category, $storeViewCode)
        );

        $integrationHealthConfigRepository = $this->createMock(IntegrationHealthConfigRepository::class);
        $integrationHealthConfigRepository->expects(self::once())->method('get')->with(1)->willReturn(null);

        $integrationHealthEvaluator = $this->createMock(IntegrationHealthEvaluator::class);
        $integrationHealthEvaluator->expects(self::never())->method('evaluate');
        // Never-configured (returns null, no prior state to keep alive) --
        // still called, just returns nothing, so the batch stays at 3.
        $integrationHealthEvaluator->expects(self::once())
            ->method('heartbeatRetiredIfPreviouslyReported')
            ->with(1, 'default')
            ->willReturn(null);

        $bufferRepository = $this->createStub(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(true);
        $bufferRepository->method('allBuffered')->willReturn([]);

        $submissionService = $this->createStub(MetricsSubmissionService::class);
        $submissionService->method('submit')
            ->willReturn(new MetricsSubmissionResult(succeeded: true, accepted: 4, rejected: []));

        $outcome = $this->service(
            config: $config,
            evaluator: $evaluator,
            submissionService: $submissionService,
            bufferRepository: $bufferRepository,
            storeManager: $storeManager,
            basketQuoteReader: $basketQuoteReader,
            checkoutReader: $checkoutReader,
            customerAccountReader: $customerAccountReader,
            dispersionEvaluator: $dispersionEvaluator,
            integrationHealthConfigRepository: $integrationHealthConfigRepository,
            integrationHealthEvaluator: $integrationHealthEvaluator,
        )->run();

        // Three rate categories plus checkout_failure; integration_health absent.
        self::assertCount(4, $outcome['storeViewReports']);
    }

    /**
     * A store view that WAS configured and had its source cleared (or
     * whose source_type no longer resolves) must keep heartbeating its
     * last confirmed integration_health status, never go fully silent --
     * see Evaluator::heartbeatRetiredIfPreviouslyReported()'s own docblock
     * for why (the platform's staleness sweep has no concept of a
     * deliberately-retired signal). Distinguishing this from "never
     * configured" is the whole point of this test: both produce
     * config === null, but only one has a report to keep alive.
     */
    public function testAClearedIntegrationHealthSourceKeepsHeartbeatingRatherThanGoingSilent(): void
    {
        $config = $this->configuredAndEnabled();
        $cronHealthReport = $this->report(sequenceNumber: 1);

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($cronHealthReport);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $basketQuoteReader = $this->createStub(BasketQuoteReader::class);
        $basketQuoteReader->method('countForWindow')->willReturn(0);
        $checkoutReader = $this->createStub(CheckoutReader::class);
        $checkoutReader->method('countForWindow')->willReturn(0);
        $customerAccountReader = $this->createStub(CustomerAccountReader::class);
        $customerAccountReader->method('countForWindow')->willReturn(0);

        $dispersionEvaluator = $this->createStub(DispersionEvaluator::class);
        $dispersionEvaluator->method('evaluate')->willReturnCallback(
            fn (int $storeViewId, string $storeViewCode, string $category): MetricReport
                => $this->storeViewReport($category, $storeViewCode)
        );

        $integrationHealthConfigRepository = $this->createStub(IntegrationHealthConfigRepository::class);
        $integrationHealthConfigRepository->method('get')->willReturn(null);

        $retirementHeartbeat = $this->integrationHealthReport('default');
        $integrationHealthEvaluator = $this->createMock(IntegrationHealthEvaluator::class);
        $integrationHealthEvaluator->expects(self::never())->method('evaluate');
        $integrationHealthEvaluator->expects(self::once())
            ->method('heartbeatRetiredIfPreviouslyReported')
            ->with(1, 'default')
            ->willReturn($retirementHeartbeat);

        $bufferRepository = $this->createStub(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(true);
        $bufferRepository->method('allBuffered')->willReturn([]);

        $result = new MetricsSubmissionResult(succeeded: true, accepted: 4, rejected: []);
        $submissionService = $this->createMock(MetricsSubmissionService::class);
        $submissionService->expects(self::once())
            ->method('submit')
            ->with(
                'https://watchtower.test',
                'secret-api-key-value',
                self::callback(fn (array $reports) => in_array($retirementHeartbeat, $reports, true))
            )
            ->willReturn($result);

        $outcome = $this->service(
            config: $config,
            evaluator: $evaluator,
            submissionService: $submissionService,
            bufferRepository: $bufferRepository,
            storeManager: $storeManager,
            basketQuoteReader: $basketQuoteReader,
            checkoutReader: $checkoutReader,
            customerAccountReader: $customerAccountReader,
            dispersionEvaluator: $dispersionEvaluator,
            integrationHealthConfigRepository: $integrationHealthConfigRepository,
            integrationHealthEvaluator: $integrationHealthEvaluator,
        )->run();

        self::assertContains($retirementHeartbeat, $outcome['storeViewReports']);
    }

    /**
     * The reason the snapshot exists at all: Magento drops a succeeded
     * cron_schedule row roughly an hour after it finishes, so a success seen
     * on a 5-minute tick has to be persisted then, not an hour later when the
     * evaluation cycle next runs and the row is already gone.
     */
    public function testTheEvidenceSnapshotPersistsANewlyObservedSuccess(): void
    {
        $observedSuccessAt = new \DateTimeImmutable('2026-08-13T14:30:00+00:00');

        $stateRepository = $this->createMock(IntegrationHealthStateRepository::class);
        $stateRepository->method('get')->willReturn($this->integrationHealthState(
            lastSuccessAt: new \DateTimeImmutable('2026-08-12T14:30:00+00:00'),
            lastFailureAt: null,
        ));
        $stateRepository->expects(self::once())
            ->method('saveObservedEvidence')
            ->with(1, $observedSuccessAt, null);

        $this->snapshotService(
            $stateRepository,
            $this->cronJobObserverReturning($observedSuccessAt, null)
        )->snapshotIntegrationHealthEvidence(new \DateTimeImmutable('2026-08-13T14:35:00+00:00'));
    }

    /**
     * Evidence is a high-water mark, not a snapshot of the lookback window:
     * an observation that is older than what is already stored (or missing
     * entirely, once the source table has been pruned) must leave the stored
     * value alone rather than ageing it back into a false DOWN.
     */
    public function testTheEvidenceSnapshotNeverMovesStoredEvidenceBackwards(): void
    {
        $storedSuccessAt = new \DateTimeImmutable('2026-08-13T14:30:00+00:00');
        $storedFailureAt = new \DateTimeImmutable('2026-08-13T12:00:00+00:00');

        $stateRepository = $this->createMock(IntegrationHealthStateRepository::class);
        $stateRepository->method('get')->willReturn($this->integrationHealthState(
            lastSuccessAt: $storedSuccessAt,
            lastFailureAt: $storedFailureAt,
        ));
        $stateRepository->expects(self::once())
            ->method('saveObservedEvidence')
            ->with(1, $storedSuccessAt, $storedFailureAt);

        $this->snapshotService(
            $stateRepository,
            // Older success, and no failure at all this tick.
            $this->cronJobObserverReturning(new \DateTimeImmutable('2026-08-13T09:00:00+00:00'), null)
        )->snapshotIntegrationHealthEvidence(new \DateTimeImmutable('2026-08-13T14:35:00+00:00'));
    }

    /**
     * A merchant who repointed the store view at a different source has state
     * describing the old one. Re-seeding that is the evaluator's job, so the
     * snapshot skips the store view rather than writing fresh evidence under
     * a stale fingerprint.
     */
    public function testTheEvidenceSnapshotIsSkippedWhenTheStateDescribesADifferentSource(): void
    {
        $stateRepository = $this->createMock(IntegrationHealthStateRepository::class);
        $stateRepository->method('get')->willReturn($this->integrationHealthState(
            lastSuccessAt: new \DateTimeImmutable('2026-08-13T14:30:00+00:00'),
            lastFailureAt: null,
            sourceIdentifier: 'a_job_the_merchant_has_since_replaced',
        ));
        $stateRepository->expects(self::never())->method('saveObservedEvidence');

        $cronJobObserver = $this->createMock(CronJobObserver::class);
        $cronJobObserver->expects(self::never())->method('observe');

        $this->snapshotService($stateRepository, $cronJobObserver)
            ->snapshotIntegrationHealthEvidence(new \DateTimeImmutable('2026-08-13T14:35:00+00:00'));
    }

    /**
     * The snapshot is evidence capture only. Anything that would advance the
     * debounce state machine (a full save(), an evaluation, a submission) runs
     * 12x too often here and would both spam sequence numbers and confirm
     * status changes on a cadence the ruleset was never designed around.
     */
    public function testTheEvidenceSnapshotWritesNoStatusOrSequenceNumberAndSubmitsNothing(): void
    {
        $stateRepository = $this->createMock(IntegrationHealthStateRepository::class);
        $stateRepository->method('get')->willReturn($this->integrationHealthState(
            lastSuccessAt: null,
            lastFailureAt: null,
        ));
        $stateRepository->expects(self::once())->method('saveObservedEvidence');
        $stateRepository->expects(self::never())->method('save');

        $integrationHealthEvaluator = $this->createMock(IntegrationHealthEvaluator::class);
        $integrationHealthEvaluator->expects(self::never())->method('evaluate');
        $integrationHealthEvaluator->expects(self::never())->method('heartbeatRetiredIfPreviouslyReported');

        $submissionService = $this->createMock(MetricsSubmissionService::class);
        $submissionService->expects(self::never())->method('submit');

        $this->snapshotService(
            $stateRepository,
            $this->cronJobObserverReturning(new \DateTimeImmutable('2026-08-13T14:30:00+00:00'), null),
            $integrationHealthEvaluator,
            $submissionService
        )->snapshotIntegrationHealthEvidence(new \DateTimeImmutable('2026-08-13T14:35:00+00:00'));
    }

    /**
     * Same gate run() uses: an install that never set this up, or deliberately
     * switched it off, must not have its tables read every 5 minutes.
     *
     * @param bool $isConfigured
     * @param bool $isEnabled
     * @return void
     */
    #[TestWith([false, true])]
    #[TestWith([true, false])]
    public function testTheEvidenceSnapshotDoesNothingWhenNotConfiguredOrDisabled(
        bool $isConfigured,
        bool $isEnabled
    ): void {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn($isConfigured);
        $config->method('isEnabled')->willReturn($isEnabled);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects(self::never())->method('getStores');

        $stateRepository = $this->createMock(IntegrationHealthStateRepository::class);
        $stateRepository->expects(self::never())->method('get');
        $stateRepository->expects(self::never())->method('saveObservedEvidence');

        $this->service(
            config: $config,
            evaluator: $this->createStub(Evaluator::class),
            storeManager: $storeManager,
            integrationHealthStateRepository: $stateRepository,
        )->snapshotIntegrationHealthEvidence(new \DateTimeImmutable('2026-08-13T14:35:00+00:00'));
    }

    /**
     * A store view with no integration_health source configured must cost
     * nothing beyond the config lookup itself, since this runs every tick.
     */
    public function testTheEvidenceSnapshotSkipsAStoreViewWithNoConfiguredSource(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $stateRepository = $this->createMock(IntegrationHealthStateRepository::class);
        $stateRepository->expects(self::never())->method('get');
        $stateRepository->expects(self::never())->method('saveObservedEvidence');

        $cronJobObserver = $this->createMock(CronJobObserver::class);
        $cronJobObserver->expects(self::never())->method('observe');

        $this->service(
            config: $this->configuredAndEnabled(),
            evaluator: $this->createStub(Evaluator::class),
            storeManager: $storeManager,
            integrationHealthStateRepository: $stateRepository,
            cronJobObserver: $cronJobObserver,
        )->snapshotIntegrationHealthEvidence(new \DateTimeImmutable('2026-08-13T14:35:00+00:00'));
    }

    /**
     * Builds a ReportingService with one live store view whose
     * integration_health source is the cron job SNAPSHOT_JOB_CODE names.
     *
     * @param IntegrationHealthStateRepository $stateRepository
     * @param CronJobObserver $cronJobObserver
     * @param IntegrationHealthEvaluator|null $integrationHealthEvaluator
     * @param MetricsSubmissionService|null $submissionService
     * @return ReportingService
     */
    private function snapshotService(
        IntegrationHealthStateRepository $stateRepository,
        CronJobObserver $cronJobObserver,
        ?IntegrationHealthEvaluator $integrationHealthEvaluator = null,
        ?MetricsSubmissionService $submissionService = null
    ): ReportingService {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $integrationHealthConfigRepository = $this->createStub(IntegrationHealthConfigRepository::class);
        $integrationHealthConfigRepository->method('get')->willReturn(new IntegrationHealthConfig(
            storeViewId: 1,
            sourceType: IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB,
            sourceIdentifier: self::SNAPSHOT_JOB_CODE,
            expectedMaxIntervalMinutes: 1440,
        ));

        return $this->service(
            config: $this->configuredAndEnabled(),
            evaluator: $this->createStub(Evaluator::class),
            submissionService: $submissionService,
            storeManager: $storeManager,
            integrationHealthConfigRepository: $integrationHealthConfigRepository,
            integrationHealthEvaluator: $integrationHealthEvaluator,
            integrationHealthStateRepository: $stateRepository,
            cronJobObserver: $cronJobObserver,
        );
    }

    private function cronJobObserverReturning(
        ?\DateTimeImmutable $latestSuccessAt,
        ?\DateTimeImmutable $latestFailureAt
    ): CronJobObserver {
        $observer = $this->createStub(CronJobObserver::class);
        $observer->method('observe')->willReturn(
            new Observation(latestSuccessAt: $latestSuccessAt, latestFailureAt: $latestFailureAt)
        );

        return $observer;
    }

    private function integrationHealthState(
        ?\DateTimeImmutable $lastSuccessAt,
        ?\DateTimeImmutable $lastFailureAt,
        string $sourceIdentifier = self::SNAPSHOT_JOB_CODE
    ): IntegrationHealthState {
        return new IntegrationHealthState(
            storeViewId: 1,
            lastSuccessAt: $lastSuccessAt,
            lastFailureAt: $lastFailureAt,
            pendingStatus: null,
            confirmedStatus: SignalStatus::Normal,
            sequenceNumber: 7,
            lastReportedReason: ReportReason::Heartbeat,
            sourceType: IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB,
            sourceIdentifier: $sourceIdentifier,
            observingSince: new \DateTimeImmutable('2026-08-01T00:00:00+00:00'),
        );
    }

    /**
     * Shared body for the queue_consumer/convention_event dispatch tests
     * above: wires a single live store view with the given source_type/
     * identifier configured, asserts only the matching observer/reader is
     * called (the other two are asserted never-called), and returns the
     * run() outcome plus the produced integration_health report.
     *
     * @param string $sourceType
     * @param string $sourceIdentifier
     * @return array{report: MetricReport, storeViewReports: MetricReport[]}
     */
    private function runWithIntegrationHealthSource(string $sourceType, string $sourceIdentifier): array
    {
        $config = $this->configuredAndEnabled();
        $cronHealthReport = $this->report(sequenceNumber: 1);

        $evaluator = $this->createStub(Evaluator::class);
        $evaluator->method('evaluate')->willReturn($cronHealthReport);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore('default')]);

        $basketQuoteReader = $this->createStub(BasketQuoteReader::class);
        $basketQuoteReader->method('countForWindow')->willReturn(0);
        $checkoutReader = $this->createStub(CheckoutReader::class);
        $checkoutReader->method('countForWindow')->willReturn(0);
        $customerAccountReader = $this->createStub(CustomerAccountReader::class);
        $customerAccountReader->method('countForWindow')->willReturn(0);

        $dispersionEvaluator = $this->createStub(DispersionEvaluator::class);
        $dispersionEvaluator->method('evaluate')->willReturnCallback(
            fn (int $storeViewId, string $storeViewCode, string $category): MetricReport
                => $this->storeViewReport($category, $storeViewCode)
        );

        $integrationHealthConfig = new IntegrationHealthConfig(
            storeViewId: 1,
            sourceType: $sourceType,
            sourceIdentifier: $sourceIdentifier,
            expectedMaxIntervalMinutes: 60,
        );
        $integrationHealthConfigRepository = $this->createStub(IntegrationHealthConfigRepository::class);
        $integrationHealthConfigRepository->method('get')->willReturn($integrationHealthConfig);

        $observation = new Observation(
            latestSuccessAt: new \DateTimeImmutable('2026-08-13T14:30:00+00:00'),
            latestFailureAt: null,
        );

        $cronJobObserver = $this->createMock(CronJobObserver::class);
        $queueConsumerObserver = $this->createMock(QueueConsumerObserver::class);
        $conventionEventReader = $this->createMock(ConventionEventReader::class);

        $cronJobObserver->expects($sourceType === IntegrationHealthConfig::SOURCE_TYPE_CRON_JOB
            ? self::once() : self::never())->method('observe')->willReturn($observation);
        $queueConsumerObserver->expects($sourceType === IntegrationHealthConfig::SOURCE_TYPE_QUEUE_CONSUMER
            ? self::once() : self::never())->method('observe')->willReturn($observation);
        $conventionEventReader->expects($sourceType === IntegrationHealthConfig::SOURCE_TYPE_CONVENTION_EVENT
            ? self::once() : self::never())->method('observe')->willReturn($observation);

        $integrationHealthReport = $this->integrationHealthReport('default');
        $integrationHealthEvaluator = $this->createStub(IntegrationHealthEvaluator::class);
        $integrationHealthEvaluator->method('evaluate')->willReturn($integrationHealthReport);

        $bufferRepository = $this->createStub(ReportBufferRepository::class);
        $bufferRepository->method('discardExpired')->willReturn(0);
        $bufferRepository->method('isDue')->willReturn(true);
        $bufferRepository->method('allBuffered')->willReturn([]);

        $submissionService = $this->createStub(MetricsSubmissionService::class);
        $submissionService->method('submit')
            ->willReturn(new MetricsSubmissionResult(succeeded: true, accepted: 5, rejected: []));

        $outcome = $this->service(
            config: $config,
            evaluator: $evaluator,
            submissionService: $submissionService,
            bufferRepository: $bufferRepository,
            storeManager: $storeManager,
            basketQuoteReader: $basketQuoteReader,
            checkoutReader: $checkoutReader,
            customerAccountReader: $customerAccountReader,
            dispersionEvaluator: $dispersionEvaluator,
            integrationHealthConfigRepository: $integrationHealthConfigRepository,
            integrationHealthEvaluator: $integrationHealthEvaluator,
            cronJobObserver: $cronJobObserver,
            queueConsumerObserver: $queueConsumerObserver,
            conventionEventReader: $conventionEventReader,
        )->run();

        return ['report' => $integrationHealthReport, 'storeViewReports' => $outcome['storeViewReports']];
    }

    private function configuredAndEnabled(): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn(true);
        $config->method('isEnabled')->willReturn(true);
        $config->method('baseUrl')->willReturn('https://watchtower.test');
        $config->method('apiKey')->willReturn('secret-api-key-value');

        return $config;
    }

    private function report(int $sequenceNumber): MetricReport
    {
        return new MetricReport(
            storeViewCode: null,
            eventType: Evaluator::EVENT_TYPE,
            status: SignalStatus::Normal,
            sequenceNumber: $sequenceNumber,
            evaluatedAt: new \DateTimeImmutable('2026-08-13T15:00:00+00:00'),
            reason: ReportReason::Heartbeat,
            rulesetVersion: Evaluator::RULESET_VERSION,
        );
    }

    private function storeViewReport(string $category, string $storeViewCode): MetricReport
    {
        return new MetricReport(
            storeViewCode: $storeViewCode,
            eventType: $category,
            status: SignalStatus::InsufficientData,
            sequenceNumber: 1,
            evaluatedAt: new \DateTimeImmutable('2026-08-13T15:00:00+00:00'),
            reason: ReportReason::Transition,
            rulesetVersion: DispersionEvaluator::RULESET_VERSION,
        );
    }

    /**
     * Builds a ReportingService with sensible no-op defaults for the
     * store-view-signal dependencies (zero live store views), so tests
     * that only care about cron_health's own buffering behavior don't need
     * to wire every new constructor argument by hand.
     *
     * @param Config $config
     * @param Evaluator $evaluator
     * @param MetricsSubmissionService|null $submissionService
     * @param ReportBufferRepository $bufferRepository
     * @param StoreManagerInterface|null $storeManager
     * @param BasketQuoteReader|null $basketQuoteReader
     * @param CheckoutReader|null $checkoutReader
     * @param CustomerAccountReader|null $customerAccountReader
     * @param RollupRepository|null $rollupRepository
     * @param DispersionEvaluator|null $dispersionEvaluator
     * @param CheckoutFailureEvaluator|null $checkoutFailureEvaluator
     * @param HistorySeeder|null $historySeeder
     * @param SeedCoverageRepository|null $seedCoverageRepository
     * @param IntegrationHealthConfigRepository|null $integrationHealthConfigRepository
     * @param IntegrationHealthEvaluator|null $integrationHealthEvaluator
     * @param IntegrationHealthStateRepository|null $integrationHealthStateRepository
     * @param CronJobObserver|null $cronJobObserver
     * @param QueueConsumerObserver|null $queueConsumerObserver
     * @param ConventionEventReader|null $conventionEventReader
     * @param OrganizationStateRepository|null $organizationStateRepository
     * @param SubmissionOutcomeRepository|null $submissionOutcomeRepository
     * @param ConnectorVersionCheckService|null $connectorVersionCheckService
     * @param ConnectorVersionStateRepository|null $connectorVersionStateRepository
     * @return ReportingService
     */
    private function service(
        Config $config,
        Evaluator $evaluator,
        ?MetricsSubmissionService $submissionService = null,
        ?ReportBufferRepository $bufferRepository = null,
        ?StoreManagerInterface $storeManager = null,
        ?BasketQuoteReader $basketQuoteReader = null,
        ?CheckoutReader $checkoutReader = null,
        ?CustomerAccountReader $customerAccountReader = null,
        ?RollupRepository $rollupRepository = null,
        ?DispersionEvaluator $dispersionEvaluator = null,
        ?CheckoutFailureEvaluator $checkoutFailureEvaluator = null,
        ?HistorySeeder $historySeeder = null,
        ?SeedCoverageRepository $seedCoverageRepository = null,
        ?IntegrationHealthConfigRepository $integrationHealthConfigRepository = null,
        ?IntegrationHealthEvaluator $integrationHealthEvaluator = null,
        ?IntegrationHealthStateRepository $integrationHealthStateRepository = null,
        ?CronJobObserver $cronJobObserver = null,
        ?QueueConsumerObserver $queueConsumerObserver = null,
        ?ConventionEventReader $conventionEventReader = null,
        ?OrganizationStateRepository $organizationStateRepository = null,
        ?LoggerInterface $logger = null,
        ?SubmissionOutcomeRepository $submissionOutcomeRepository = null,
        ?ConnectorVersionCheckService $connectorVersionCheckService = null,
        ?ConnectorVersionStateRepository $connectorVersionStateRepository = null,
    ): ReportingService {
        if ($storeManager === null) {
            $storeManager = $this->createStub(StoreManagerInterface::class);
            $storeManager->method('getStores')->willReturn([]);
        }

        if ($rollupRepository === null) {
            $rollupRepository = $this->createStub(RollupRepository::class);
            // Already-seeded by default, so seedIfNeverSeeded() is a no-op for
            // every test that isn't specifically exercising the seeding path --
            // matching this suite's behavior before that path existed.
            $rollupRepository->method('hasAnyHourlyDataForCategories')->willReturn(true);
        }

        if ($historySeeder === null) {
            $historySeeder = $this->createStub(HistorySeeder::class);
        }

        if ($seedCoverageRepository === null) {
            $seedCoverageRepository = $this->createStub(SeedCoverageRepository::class);
        }

        if ($integrationHealthConfigRepository === null) {
            $integrationHealthConfigRepository = $this->createStub(IntegrationHealthConfigRepository::class);
            $integrationHealthConfigRepository->method('get')->willReturn(null);
        }

        if ($organizationStateRepository === null) {
            $organizationStateRepository = $this->createStub(OrganizationStateRepository::class);
            $organizationStateRepository->method('isPaused')->willReturn(false);
        }

        if ($connectorVersionCheckService === null) {
            $connectorVersionCheckService = $this->createStub(ConnectorVersionCheckService::class);
            $connectorVersionCheckService->method('check')->willReturn($this->upToDateCheckResult());
        }

        if ($connectorVersionStateRepository === null) {
            $connectorVersionStateRepository = $this->connectorVersionStateRepositoryStub();
        }

        return new ReportingService(
            $config,
            $evaluator,
            $submissionService ?? $this->createStub(MetricsSubmissionService::class),
            $bufferRepository ?? $this->createStub(ReportBufferRepository::class),
            new LiveStoreViewResolver($storeManager),
            $basketQuoteReader ?? $this->createStub(BasketQuoteReader::class),
            $checkoutReader ?? $this->createStub(CheckoutReader::class),
            $customerAccountReader ?? $this->createStub(CustomerAccountReader::class),
            $rollupRepository,
            $dispersionEvaluator ?? $this->createStub(DispersionEvaluator::class),
            $checkoutFailureEvaluator ?? $this->createStub(CheckoutFailureEvaluator::class),
            $historySeeder,
            $seedCoverageRepository,
            $integrationHealthConfigRepository,
            $integrationHealthEvaluator ?? $this->createStub(IntegrationHealthEvaluator::class),
            $integrationHealthStateRepository ?? $this->createStub(IntegrationHealthStateRepository::class),
            $cronJobObserver ?? $this->createStub(CronJobObserver::class),
            $queueConsumerObserver ?? $this->createStub(QueueConsumerObserver::class),
            $conventionEventReader ?? $this->createStub(ConventionEventReader::class),
            $organizationStateRepository,
            $logger ?? $this->createStub(LoggerInterface::class),
            $submissionOutcomeRepository ?? $this->createStub(SubmissionOutcomeRepository::class),
            $connectorVersionCheckService,
            $connectorVersionStateRepository,
        );
    }

    private function upToDateCheckResult(): ConnectorVersionCheckResult
    {
        return new ConnectorVersionCheckResult(
            succeeded: true,
            installedVersion: '1.2.0',
            minimumVersion: '1.0.0',
            latestVersion: '1.2.0',
        );
    }

    private function connectorVersionStateRepositoryStub(
        bool $belowMinimum = false
    ): ConnectorVersionStateRepository {
        $repository = $this->createStub(ConnectorVersionStateRepository::class);
        $repository->method('get')->willReturn(new ConnectorVersionState(
            installedVersion: $belowMinimum ? '1.0.0' : '1.2.0',
            minimumVersion: $belowMinimum ? '1.2.0' : '1.0.0',
            latestVersion: '1.2.0',
            belowMinimum: $belowMinimum,
            updateAvailable: $belowMinimum,
            checkedAt: new \DateTimeImmutable('2026-08-13T15:00:00+00:00'),
        ));

        return $repository;
    }

    private function integrationHealthReport(string $storeViewCode): MetricReport
    {
        return new MetricReport(
            storeViewCode: $storeViewCode,
            eventType: IntegrationHealthEvaluator::EVENT_TYPE,
            status: SignalStatus::Normal,
            sequenceNumber: 1,
            evaluatedAt: new \DateTimeImmutable('2026-08-13T15:00:00+00:00'),
            reason: ReportReason::Transition,
            rulesetVersion: IntegrationHealthEvaluator::RULESET_VERSION,
        );
    }

    /**
     * A CheckoutFailureEvaluator returning one known report per store view, so
     * the identity assertions above can name it rather than counting past it.
     */
    private function checkoutFailureEvaluatorReturning(MetricReport $report): CheckoutFailureEvaluator
    {
        $evaluator = $this->createStub(CheckoutFailureEvaluator::class);
        $evaluator->method('evaluate')->willReturn($report);

        return $evaluator;
    }
}
