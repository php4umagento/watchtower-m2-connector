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
use Watchtower\Connector\Model\Config;
use Watchtower\Connector\Model\ReportingService;

/**
 * Covers Cron\ReportJob's own submission-time jitter guard -- since
 * etc/crontab.xml polls this job every 5 minutes rather than once an
 * hour, ReportingService::run() must only actually be called on this
 * install's own deterministic offset minute, never on the other 11 of
 * every 12 ticks. The outcome-handling/logging behavior
 * (buffered/failed/rejected/backfill) is exercised here only
 * incidentally, via the "runs on the right minute" tests.
 *
 * "a-real-api-key" (the same fixture literal SyncJobTest already uses)
 * hashes to a jitter offset of :50; "test-key-B" hashes to :25 -- both
 * computed once via Cron\ReportJob::jitterOffsetMinutes()'s own formula and
 * pinned here as fixtures, proving the offset is a deterministic function
 * of the API key rather than depending on wall-clock time.
 */
class ReportJobTest extends TestCase
{
    private const OFFSET_FOR_A_REAL_API_KEY = 50;
    private const OFFSET_FOR_TEST_KEY_B = 25;

    public function testRunsTheReportingCycleOnItsOwnJitterMinute(): void
    {
        $config = $this->configWithApiKey('a-real-api-key');

        $reportingService = $this->createMock(ReportingService::class);
        $reportingService->expects(self::once())->method('run')->willReturn(['ran' => false]);

        $now = new \DateTimeImmutable('2026-08-13 14:'.self::OFFSET_FOR_A_REAL_API_KEY.':00');

        (new ReportJob($reportingService, $config, $this->createStub(LoggerInterface::class)))->executeAt($now);
    }

    public function testDoesNothingOnAnyOtherMinute(): void
    {
        $config = $this->configWithApiKey('a-real-api-key');

        $reportingService = $this->createMock(ReportingService::class);
        $reportingService->expects(self::never())->method('run');

        $notMyMinute = (self::OFFSET_FOR_A_REAL_API_KEY + 5) % 60;
        $now = new \DateTimeImmutable('2026-08-13 14:'.$notMyMinute.':00');

        (new ReportJob($reportingService, $config, $this->createStub(LoggerInterface::class)))->executeAt($now);
    }

    public function testDifferentInstallsLandOnDifferentOffsets(): void
    {
        $config = $this->configWithApiKey('test-key-B');

        $reportingService = $this->createMock(ReportingService::class);
        $reportingService->expects(self::once())->method('run')->willReturn(['ran' => false]);

        $now = new \DateTimeImmutable('2026-08-13 14:'.self::OFFSET_FOR_TEST_KEY_B.':00');

        (new ReportJob($reportingService, $config, $this->createStub(LoggerInterface::class)))->executeAt($now);
    }

    public function testFallsBackToOffsetZeroWhenNotConfigured(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('apiKey')->willReturn(null);

        $reportingService = $this->createMock(ReportingService::class);
        $reportingService->expects(self::once())->method('run')->willReturn(['ran' => false]);

        $now = new \DateTimeImmutable('2026-08-13 14:00:00');

        (new ReportJob($reportingService, $config, $this->createStub(LoggerInterface::class)))->executeAt($now);
    }

    public function testOnItsOwnMinuteAFailedSubmissionIsStillLoggedAndBuffered(): void
    {
        $config = $this->configWithApiKey('a-real-api-key');

        $report = new MetricReport(
            storeViewCode: null,
            eventType: 'cron_health',
            status: SignalStatus::Normal,
            sequenceNumber: 1,
            evaluatedAt: new \DateTimeImmutable(),
            reason: ReportReason::Heartbeat,
            rulesetVersion: '1.0.1',
        );

        $reportingService = $this->createStub(ReportingService::class);
        $reportingService->method('run')->willReturn([
            'ran' => true,
            'report' => $report,
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

        $now = new \DateTimeImmutable('2026-08-13 14:'.self::OFFSET_FOR_A_REAL_API_KEY.':00');

        (new ReportJob($reportingService, $config, $logger))->executeAt($now);
    }

    /**
     * Magento's own cron dispatcher
     * (Magento\Cron\Observer\ProcessCronQueueObserver) always calls a job's
     * method positionally with the running \Magento\Cron\Model\Schedule as
     * its first argument. Every earlier test in this file called
     * executeAt($now) directly and could never have caught a signature
     * mismatch on execute() itself -- this test calls execute() exactly the
     * way Magento's dispatcher does, extra positional argument included,
     * and would have thrown a TypeError under the previous
     * ?\DateTimeImmutable-typed signature.
     */
    public function testExecuteAcceptsAnExtraPositionalArgumentTheWayMagentosCronDispatcherCallsIt(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('apiKey')->willReturn(null);

        $reportingService = $this->createStub(ReportingService::class);

        // A real Magento\Cron\Model\Schedule needs full DI construction
        // (context, registry, ...), which is unnecessary here: the bug was
        // a type mismatch between execute()'s declared parameter and
        // WHATEVER Magento passes, not something specific to Schedule's own
        // shape. Any non-DateTimeImmutable object reproduces the exact
        // failure mode -- what matters is that execute() has no required
        // typed first parameter for PHP to reject this argument against.
        $scheduleStandIn = new \stdClass();

        // apiKey() stubbed to null means the jitter guard defaults to offset
        // 0, which real wall-clock "now" DOES occasionally land on (a 1-in-12
        // chance every run) -- stub a valid outcome shape so that coincidence
        // can never turn this into a flaky "Undefined array key" error; the
        // test's own purpose is proving execute()'s signature, not exercising
        // ReportingService::run()'s outcome handling.
        $reportingService->method('run')->willReturn(['ran' => false]);

        // call_user_func_array($callback, [$schedule]) is what Magento's own
        // dispatcher does; reproduce that exact call shape rather than a
        // plain method call, since PHP only silently discards an unused
        // trailing argument when invoked this way, same as a normal call --
        // the point is proving execute()'s own signature accepts it, not
        // exercising a different invocation mechanism.
        $job = new ReportJob($reportingService, $config, $this->createStub(LoggerInterface::class));
        call_user_func_array([$job, 'execute'], [$scheduleStandIn]);

        // No assertion beyond "did not throw" -- that IS the regression this
        // test exists to lock.
        self::assertTrue(true);
    }

    /**
     * The behavioral fix for the second live-reproduced bug: comparing
     * exact minutes silently missed an install's entire hourly report
     * whenever real Magento cron execution drifted past the scheduled
     * minute (measured on a real install: most executions land 60+ seconds
     * late, some by over ten minutes). Bucket comparison tolerates drift
     * within the bucket width -- this proves a tick a few minutes AFTER the
     * install's own offset, still inside the same JITTER_BUCKET_MINUTES
     * window, still triggers.
     */
    public function testToleratesDriftWithinTheJitterBucketWidth(): void
    {
        $config = $this->configWithApiKey('a-real-api-key');

        $reportingService = $this->createMock(ReportingService::class);
        $reportingService->expects(self::once())->method('run')->willReturn(['ran' => false]);

        // Offset is :50; a tick landing at :53 (still within the [50,55)
        // bucket) must still count as this install's own tick.
        $driftedMinute = self::OFFSET_FOR_A_REAL_API_KEY + 3;
        $now = new \DateTimeImmutable('2026-08-13 14:'.$driftedMinute.':00');

        (new ReportJob($reportingService, $config, $this->createStub(LoggerInterface::class)))->executeAt($now);
    }

    /**
     * A dedup rejection ("sequence_number is out of order or already
     * recorded") is proof of prior delivery, not a genuine problem -- it
     * must be logged separately at info level, never lumped into the same
     * warning as an unrecognized store view code or similar.
     */
    public function testDedupRejectionsAreLoggedSeparatelyFromGenuineRejections(): void
    {
        $config = $this->configWithApiKey('a-real-api-key');

        $report = new MetricReport(
            storeViewCode: null,
            eventType: 'cron_health',
            status: SignalStatus::Normal,
            sequenceNumber: 1,
            evaluatedAt: new \DateTimeImmutable(),
            reason: ReportReason::Heartbeat,
            rulesetVersion: '1.0.1',
        );

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
            'report' => $report,
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

        $now = new \DateTimeImmutable('2026-08-13 14:'.self::OFFSET_FOR_A_REAL_API_KEY.':00');

        (new ReportJob($reportingService, $config, $logger))->executeAt($now);
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
        $config = $this->configWithApiKey('a-real-api-key');

        $report = new MetricReport(
            storeViewCode: null,
            eventType: 'cron_health',
            status: SignalStatus::Normal,
            sequenceNumber: 1,
            evaluatedAt: new \DateTimeImmutable(),
            reason: ReportReason::Heartbeat,
            rulesetVersion: '1.0.1',
        );

        $reportingService = $this->createStub(ReportingService::class);
        $reportingService->method('run')->willReturn([
            'ran' => true,
            'report' => $report,
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

        $now = new \DateTimeImmutable('2026-08-13 14:'.self::OFFSET_FOR_A_REAL_API_KEY.':00');

        (new ReportJob($reportingService, $config, $logger))->executeAt($now);
    }

    private function configWithApiKey(string $apiKey): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('apiKey')->willReturn($apiKey);

        return $config;
    }
}
