<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Watchtower\Connector\Cron\ReportJob;
use Watchtower\Connector\Model\Api\MetricReport;
use Watchtower\Connector\Model\Api\MetricsSubmissionResult;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Reporting\ReportCycleState;
use Watchtower\Connector\Model\Reporting\ReportCycleStateRepository;
use Watchtower\Connector\Model\ReportingService;

/**
 * Covers Cron\ReportJob's own elapsed-time guard -- since etc/crontab.xml
 * polls this job every 5 minutes rather than once an hour,
 * ReportingService::run() must only actually be called once
 * MIN_INTERVAL_SECONDS has passed since the real cycle last ran, never on
 * every tick. The outcome-handling/logging behavior
 * (buffered/failed/rejected/backfill) is exercised here only incidentally,
 * via the "is due" tests.
 */
class ReportJobTest extends TestCase
{
    public function testRunsWhenTheCycleHasNeverRunBefore(): void
    {
        $reportingService = $this->createMock(ReportingService::class);
        $reportingService->expects(self::once())->method('run')->willReturn(['ran' => false]);

        $repository = $this->repositoryReturning(lastRunAt: null);
        $now = new \DateTimeImmutable('2026-08-13 14:00:00');

        (new ReportJob($reportingService, $repository, $this->createStub(LoggerInterface::class)))->executeAt($now);
    }

    public function testDoesNotRunWhenLessThanAnHourHasPassedSinceTheLastRun(): void
    {
        $reportingService = $this->createMock(ReportingService::class);
        $reportingService->expects(self::never())->method('run');

        $repository = $this->repositoryReturning(lastRunAt: new \DateTimeImmutable('2026-08-13 14:00:00'));
        $now = new \DateTimeImmutable('2026-08-13 14:59:59');

        (new ReportJob($reportingService, $repository, $this->createStub(LoggerInterface::class)))->executeAt($now);
    }

    /**
     * The two cadences are deliberately decoupled: cron_schedule drops a
     * succeeded row roughly an hour after it finishes, so evidence has to be
     * captured on every 5-minute tick even though the evaluate-and-submit
     * cycle stays hourly. A snapshot that only ran when the cycle was due
     * would reintegrate the exact miss it exists to prevent.
     */
    public function testSnapshotsIntegrationHealthEvidenceEvenOnATickThatIsNotDue(): void
    {
        $now = new \DateTimeImmutable('2026-08-13 14:05:00');

        $reportingService = $this->createMock(ReportingService::class);
        $reportingService->expects(self::once())->method('snapshotIntegrationHealthEvidence')->with($now);
        $reportingService->expects(self::never())->method('run');

        $repository = $this->createMock(ReportCycleStateRepository::class);
        $repository->method('get')->willReturn(new ReportCycleState(
            lastRunAt: new \DateTimeImmutable('2026-08-13 14:00:00')
        ));
        $repository->expects(self::never())->method('save');

        (new ReportJob($reportingService, $repository, $this->createStub(LoggerInterface::class)))->executeAt($now);
    }

    public function testSnapshotsIntegrationHealthEvidenceOnADueTickToo(): void
    {
        $now = new \DateTimeImmutable('2026-08-13 15:00:00');

        $reportingService = $this->createMock(ReportingService::class);
        $reportingService->expects(self::once())->method('snapshotIntegrationHealthEvidence')->with($now);
        $reportingService->method('run')->willReturn(['ran' => false]);

        $repository = $this->repositoryReturning(lastRunAt: new \DateTimeImmutable('2026-08-13 14:00:00'));

        (new ReportJob($reportingService, $repository, $this->createStub(LoggerInterface::class)))->executeAt($now);
    }

    public function testRunsOnceExactlyAnHourHasPassedSinceTheLastRun(): void
    {
        $reportingService = $this->createMock(ReportingService::class);
        $reportingService->expects(self::once())->method('run')->willReturn(['ran' => false]);

        $repository = $this->repositoryReturning(lastRunAt: new \DateTimeImmutable('2026-08-13 14:00:00'));
        $now = new \DateTimeImmutable('2026-08-13 15:00:00');

        (new ReportJob($reportingService, $repository, $this->createStub(LoggerInterface::class)))->executeAt($now);
    }

    /**
     * The whole point of this guard: it must not care what minute-of-hour
     * "now" falls on, only how much time elapsed since the last real run --
     * unlike the old per-install jitter-minute design, which silently never
     * ran at all on a real install whose host only invoked cron:run every
     * 10 minutes (see this class's git history / Cron\ReportJob's docblock).
     */
    public function testRunsRegardlessOfWallClockMinuteAsLongAsTheIntervalHasElapsed(): void
    {
        $reportingService = $this->createMock(ReportingService::class);
        $reportingService->expects(self::once())->method('run')->willReturn(['ran' => false]);

        $repository = $this->repositoryReturning(lastRunAt: new \DateTimeImmutable('2026-08-13 13:47:00'));
        $now = new \DateTimeImmutable('2026-08-13 14:52:00');

        (new ReportJob($reportingService, $repository, $this->createStub(LoggerInterface::class)))->executeAt($now);
    }

    public function testDoesNotPersistARunWhenNotConfiguredOrDisabled(): void
    {
        $reportingService = $this->createStub(ReportingService::class);
        $reportingService->method('run')->willReturn(['ran' => false]);

        $repository = $this->createMock(ReportCycleStateRepository::class);
        $repository->method('get')->willReturn(new ReportCycleState(lastRunAt: null));
        $repository->expects(self::never())->method('save');

        $now = new \DateTimeImmutable('2026-08-13 14:00:00');

        (new ReportJob($reportingService, $repository, $this->createStub(LoggerInterface::class)))->executeAt($now);
    }

    public function testPersistsTheRunWhenTheCycleActuallyRan(): void
    {
        $reportingService = $this->createStub(ReportingService::class);
        $reportingService->method('run')->willReturn([
            'ran' => true,
            'report' => $this->cronHealthReport(),
            'storeViewReports' => [],
            'result' => null,
            'includedBufferedCount' => 0,
            'expiredBufferedCount' => 0,
            'evictedForCapacityCount' => 0,
        ]);

        $now = new \DateTimeImmutable('2026-08-13 14:00:00');

        $repository = $this->createMock(ReportCycleStateRepository::class);
        $repository->method('get')->willReturn(new ReportCycleState(lastRunAt: null));
        $repository->expects(self::once())->method('save')->with($now);

        (new ReportJob($reportingService, $repository, $this->createStub(LoggerInterface::class)))->executeAt($now);
    }

    public function testADueRunWithAFailedSubmissionIsStillLoggedAndBuffered(): void
    {
        $reportingService = $this->createStub(ReportingService::class);
        $reportingService->method('run')->willReturn([
            'ran' => true,
            'report' => $this->cronHealthReport(),
            'storeViewReports' => [],
            'result' => new MetricsSubmissionResult(succeeded: false, errorMessage: 'Connection refused'),
            'includedBufferedCount' => 0,
            'expiredBufferedCount' => 0,
            'evictedForCapacityCount' => 0,
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Watchtower report submission failed; buffered for retry.',
            ['error' => 'Connection refused']
        );

        $repository = $this->repositoryReturning(lastRunAt: null);
        $now = new \DateTimeImmutable('2026-08-13 14:00:00');

        (new ReportJob($reportingService, $repository, $logger))->executeAt($now);
    }

    /**
     * Magento's own cron dispatcher
     * (Magento\Cron\Observer\ProcessCronQueueObserver) always calls a job's
     * method positionally with the running \Magento\Cron\Model\Schedule as
     * its first argument. Every earlier test in this file called
     * executeAt($now) directly and could never have caught a signature
     * mismatch on execute() itself -- this test calls execute() exactly the
     * way Magento's dispatcher does, extra positional argument included,
     * and would have thrown a TypeError under a DateTimeImmutable-typed
     * signature.
     */
    public function testExecuteAcceptsAnExtraPositionalArgumentTheWayMagentosCronDispatcherCallsIt(): void
    {
        $reportingService = $this->createStub(ReportingService::class);
        $reportingService->method('run')->willReturn(['ran' => false]);

        $repository = $this->repositoryReturning(lastRunAt: null);

        // A real Magento\Cron\Model\Schedule needs full DI construction
        // (context, registry, ...), which is unnecessary here: the bug was
        // a type mismatch between execute()'s declared parameter and
        // WHATEVER Magento passes, not something specific to Schedule's own
        // shape. Any non-DateTimeImmutable object reproduces the exact
        // failure mode -- what matters is that execute() has no required
        // typed first parameter for PHP to reject this argument against.
        $scheduleStandIn = new \stdClass();

        // call_user_func_array($callback, [$schedule]) is what Magento's own
        // dispatcher does; reproduce that exact call shape rather than a
        // plain method call, since PHP only silently discards an unused
        // trailing argument when invoked this way, same as a normal call --
        // the point is proving execute()'s own signature accepts it, not
        // exercising a different invocation mechanism.
        $job = new ReportJob($reportingService, $repository, $this->createStub(LoggerInterface::class));
        call_user_func_array([$job, 'execute'], [$scheduleStandIn]);

        // No assertion beyond "did not throw" -- that IS the regression this
        // test exists to lock.
        self::assertTrue(true);
    }

    /**
     * A dedup rejection ("sequence_number is out of order or already
     * recorded") is proof of prior delivery, not a genuine problem -- it
     * must be logged separately at info level, never lumped into the same
     * warning as an unrecognized store view code or similar.
     */
    public function testDedupRejectionsAreLoggedSeparatelyFromGenuineRejections(): void
    {
        $dedupRejection = [
            'event_type' => 'cron_health',
            'sequence_number' => 1,
            'reason' => MetricsSubmissionResult::DEDUP_REJECTION_REASON,
        ];
        $genuineRejection = [
            'event_type' => 'basket_quote',
            'sequence_number' => 1,
            'reason' => 'store view not recognised for this install',
        ];

        $reportingService = $this->createStub(ReportingService::class);
        $reportingService->method('run')->willReturn([
            'ran' => true,
            'report' => $this->cronHealthReport(),
            'storeViewReports' => [],
            'result' => new MetricsSubmissionResult(
                succeeded: true,
                accepted: 0,
                rejected: [$dedupRejection, $genuineRejection],
            ),
            'includedBufferedCount' => 0,
            'expiredBufferedCount' => 0,
            'evictedForCapacityCount' => 0,
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'Watchtower report(s) already delivered (dedup).',
            ['rejected' => [$dedupRejection]]
        );
        $logger->expects(self::once())->method('warning')->with(
            'Watchtower report was rejected by the platform.',
            ['rejected' => [$genuineRejection]]
        );

        $repository = $this->repositoryReturning(lastRunAt: null);
        $now = new \DateTimeImmutable('2026-08-13 14:00:00');

        (new ReportJob($reportingService, $repository, $logger))->executeAt($now);
    }

    /**
     * A null result (still backing off, OR the organization is known to be
     * paused -- see ReportingService::run()) must never log anything on its
     * own: the original failure already logged a warning (backoff case),
     * and the paused state is a persistent, queryable cache rather than a
     * transient failure needing repeated alerting.
     */
    public function testANullResultNeverLogsRegardlessOfWhyItWasSkipped(): void
    {
        $reportingService = $this->createStub(ReportingService::class);
        $reportingService->method('run')->willReturn([
            'ran' => true,
            'report' => $this->cronHealthReport(),
            'storeViewReports' => [],
            'result' => null,
            'includedBufferedCount' => 0,
            'expiredBufferedCount' => 0,
            'evictedForCapacityCount' => 0,
            'organizationPaused' => true,
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::never())->method('info');

        $repository = $this->repositoryReturning(lastRunAt: null);
        $now = new \DateTimeImmutable('2026-08-13 14:00:00');

        (new ReportJob($reportingService, $repository, $logger))->executeAt($now);
    }

    private function cronHealthReport(): MetricReport
    {
        return new MetricReport(
            storeViewCode: null,
            eventType: 'cron_health',
            status: SignalStatus::Normal,
            sequenceNumber: 1,
            evaluatedAt: new \DateTimeImmutable(),
            reason: ReportReason::Heartbeat,
            rulesetVersion: '1.0.1',
        );
    }

    private function repositoryReturning(?\DateTimeImmutable $lastRunAt): ReportCycleStateRepository
    {
        $repository = $this->createStub(ReportCycleStateRepository::class);
        $repository->method('get')->willReturn(new ReportCycleState(lastRunAt: $lastRunAt));

        return $repository;
    }
}
