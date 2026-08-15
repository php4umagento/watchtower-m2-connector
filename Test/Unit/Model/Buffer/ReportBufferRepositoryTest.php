<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Buffer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\MetricReport;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Buffer\ReportBufferRepository;

/**
 * Backoff is tracked ONCE for the whole buffer
 * (watchtower_submission_backoff), not per report: a per-row design would
 * let a submission advance the platform's sequence high-water mark while
 * an older buffered report was still excluded, permanently destroying
 * that older report on its next retry. These tests lock the whole-buffer
 * semantics: a single due/not-due gate, an oldest-first cap pushed into
 * the query itself, and horizon-based eviction so an indefinitely-failing
 * submission can't wedge the connector forever.
 */
class ReportBufferRepositoryTest extends TestCase
{
    private const NOW_STRING = '2026-08-13T15:00:00+00:00';

    public function testIsDueIsTrueWhenNoBackoffRowExistsYet(): void
    {
        $repository = $this->repositoryWithBackoffRow(false);

        self::assertTrue($repository->isDue($this->now()));
    }

    public function testIsDueIsFalseBeforeTheStoredNextAttemptAt(): void
    {
        $repository = $this->repositoryWithBackoffRow([
            'id' => '1',
            'attempt_count' => '2',
            'next_attempt_at' => '2026-08-13 15:10:00',
        ]);

        self::assertFalse($repository->isDue($this->now()));
    }

    public function testIsDueIsTrueOnceNextAttemptAtHasPassed(): void
    {
        $repository = $this->repositoryWithBackoffRow([
            'id' => '1',
            'attempt_count' => '2',
            'next_attempt_at' => '2026-08-13 14:59:00',
        ]);

        self::assertTrue($repository->isDue($this->now()));
    }

    public function testRecordFailureStartsAtAttemptOneWithTheBaseBackoffWhenNoPriorRowExists(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn(false);

        $captured = null;
        $connection->expects(self::once())
            ->method('insertOnDuplicate')
            ->with('watchtower_submission_backoff', self::callback(function (array $row) use (&$captured) {
                $captured = $row;

                return true;
            }), ['attempt_count', 'next_attempt_at']);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        (new ReportBufferRepository($resourceConnection))->recordFailure(null, $this->now());

        self::assertSame(1, $captured['id']);
        self::assertSame(1, $captured['attempt_count']);
        self::assertSame('2026-08-13 15:05:00', $captured['next_attempt_at']);
    }

    public function testRecordFailureDoublesTheBackoffFromTheExistingAttemptCount(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        // Third consecutive failure -> this call makes it the fourth -> 5 * 2^3 = 40 minutes.
        $connection->method('fetchRow')->willReturn([
            'id' => '1',
            'attempt_count' => '3',
            'next_attempt_at' => '2026-08-13 14:30:00',
        ]);

        $captured = null;
        $connection->method('insertOnDuplicate')->with(
            self::anything(),
            self::callback(function (array $row) use (&$captured) {
                $captured = $row;

                return true;
            }),
            self::anything()
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        (new ReportBufferRepository($resourceConnection))->recordFailure(null, $this->now());

        self::assertSame(4, $captured['attempt_count']);
        self::assertSame('2026-08-13 15:40:00', $captured['next_attempt_at']);
    }

    public function testRecordFailureCapsBackoffAtFourHours(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        // A high attempt count would blow well past 4h uncapped (5 * 2^9 = 2560 min).
        $connection->method('fetchRow')->willReturn([
            'id' => '1',
            'attempt_count' => '9',
            'next_attempt_at' => '2026-08-13 14:30:00',
        ]);

        $captured = null;
        $connection->method('insertOnDuplicate')->with(
            self::anything(),
            self::callback(function (array $row) use (&$captured) {
                $captured = $row;

                return true;
            }),
            self::anything()
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        (new ReportBufferRepository($resourceConnection))->recordFailure(null, $this->now());

        self::assertSame('2026-08-13 19:00:00', $captured['next_attempt_at']);
    }

    public function testRecordFailureHonorsAnExplicitRetryAfterInsteadOfTheDefaultBackoff(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn(false);

        $captured = null;
        $connection->method('insertOnDuplicate')->with(
            self::anything(),
            self::callback(function (array $row) use (&$captured) {
                $captured = $row;

                return true;
            }),
            self::anything()
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        (new ReportBufferRepository($resourceConnection))->recordFailure(120, $this->now());

        self::assertSame('2026-08-13 15:02:00', $captured['next_attempt_at']);
    }

    /**
     * A malformed or malicious Retry-After shouldn't be able to park the
     * connector for an absurd amount of time; clamp the upper bound.
     */
    public function testRecordFailureClampsAnExcessiveRetryAfterToTheMaximum(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn(false);

        $captured = null;
        $connection->method('insertOnDuplicate')->with(
            self::anything(),
            self::callback(function (array $row) use (&$captured) {
                $captured = $row;

                return true;
            }),
            self::anything()
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        // 30 days of seconds, far beyond the 24h clamp.
        (new ReportBufferRepository($resourceConnection))->recordFailure(30 * 86400, $this->now());

        self::assertSame('2026-08-14 15:00:00', $captured['next_attempt_at']);
    }

    /**
     * The row is upserted (attempt_count reset, next_attempt_at moved to
     * $now, last_success_at stamped), not deleted -- last_success_at must
     * survive across successes for the diagnostics (watchtower:status /
     * the admin diagnostics page) to read it back.
     */
    public function testClearBackoffUpsertsResettingAttemptsAndStampingLastSuccessAt(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('insertOnDuplicate')->with(
            'watchtower_submission_backoff',
            [
                'id' => 1,
                'attempt_count' => 0,
                'next_attempt_at' => '2026-08-13 15:00:00',
                'last_success_at' => '2026-08-13 15:00:00',
            ],
            ['attempt_count', 'next_attempt_at', 'last_success_at']
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        (new ReportBufferRepository($resourceConnection))->clearBackoff($this->now());
    }

    public function testLastSuccessfulSubmissionAtReturnsNullWhenNoRowExistsYet(): void
    {
        $repository = $this->repositoryWithBackoffRow(false);

        self::assertNull($repository->lastSuccessfulSubmissionAt());
    }

    public function testLastSuccessfulSubmissionAtReturnsNullWhenTheRowHasNeverRecordedASuccess(): void
    {
        $repository = $this->repositoryWithBackoffRow([
            'id' => '1',
            'attempt_count' => '2',
            'next_attempt_at' => '2026-08-13 15:10:00',
            'last_success_at' => null,
        ]);

        self::assertNull($repository->lastSuccessfulSubmissionAt());
    }

    public function testLastSuccessfulSubmissionAtReflectsTheStoredValue(): void
    {
        $repository = $this->repositoryWithBackoffRow([
            'id' => '1',
            'attempt_count' => '0',
            'next_attempt_at' => '2026-08-13 15:00:00',
            'last_success_at' => '2026-08-13 14:55:00',
        ]);

        self::assertEquals(
            new \DateTimeImmutable('2026-08-13T14:55:00+00:00'),
            $repository->lastSuccessfulSubmissionAt()
        );
    }

    public function testAllBufferedPushesTheLimitIntoTheQueryAndOrdersOldestFirst(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('from')->with('watchtower_report_buffer')->willReturnSelf();
        $select->expects(self::once())->method('order')->with('buffer_id ASC')->willReturnSelf();
        $select->expects(self::once())->method('limit')->with(500)->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([
            [
                'buffer_id' => '7',
                'store_view_code' => null,
                'event_type' => 'cron_health',
                'status' => 'SEVERE_DROP',
                'sequence_number' => '18',
                'evaluated_at' => '2026-08-13 14:00:00',
                'reason' => 'transition',
                'ruleset_version' => '1.0.1',
            ],
        ]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $buffered = (new ReportBufferRepository($resourceConnection))->allBuffered(500);

        self::assertCount(1, $buffered);
        self::assertSame(7, $buffered[0]->bufferId);
        self::assertSame(18, $buffered[0]->report->sequenceNumber);
        self::assertSame(SignalStatus::SevereDrop, $buffered[0]->report->status);
        self::assertSame(ReportReason::Transition, $buffered[0]->report->reason);
    }

    public function testBufferReportInsertsWithoutAnyPerRowRetryBookkeeping(): void
    {
        $captured = null;
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with('watchtower_report_buffer', self::callback(function (array $row) use (&$captured) {
                $captured = $row;

                return true;
            }));
        $connection->method('select')->willReturn($this->fluentSelectStub());
        // Comfortably under MAX_BUFFERED_REPORTS -- eviction must stay a no-op.
        $connection->method('fetchOne')->willReturn('1');
        $connection->expects(self::never())->method('delete');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        (new ReportBufferRepository($resourceConnection))->bufferReport($this->report(42));

        self::assertSame(42, $captured['sequence_number']);
        self::assertArrayNotHasKey('attempt_count', $captured);
        self::assertArrayNotHasKey('next_attempt_at', $captured);
    }

    /**
     * Age-based eviction alone doesn't bound row count. When buffering a
     * new report pushes
     * the total over MAX_BUFFERED_REPORTS (5000), the oldest rows (lowest
     * buffer_id) must be evicted to bring the total back down to the cap,
     * not left to grow further.
     */
    public function testBufferReportEvictsTheOldestReportsWhenOverCapacity(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('insert');

        $countSelect = $this->fluentSelectStub();
        $oldestIdsSelect = $this->fluentSelectStub();

        $connection->method('select')->willReturnOnConsecutiveCalls($countSelect, $oldestIdsSelect);
        // 5002 total after this insert -- 2 over the 5000 cap.
        $connection->method('fetchOne')->willReturn('5002');
        $connection->method('fetchCol')->willReturn([11, 12]);

        $connection->expects(self::once())
            ->method('delete')
            ->with('watchtower_report_buffer', ['buffer_id IN (?)' => [11, 12]]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $evicted = (new ReportBufferRepository($resourceConnection))->bufferReport($this->report(99));

        // ReportingService::bufferAll() sums this return value to surface the
        // "evicted for capacity" diagnostic; the count itself, not just the
        // delete() call, is load-bearing.
        self::assertSame(2, $evicted);
    }

    public function testBufferReportDoesNotEvictWhenExactlyAtCapacity(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('insert');
        $connection->method('select')->willReturn($this->fluentSelectStub());
        $connection->method('fetchOne')->willReturn('5000');
        $connection->expects(self::never())->method('delete');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $evicted = (new ReportBufferRepository($resourceConnection))->bufferReport($this->report(100));

        self::assertSame(0, $evicted);
    }

    public function testBufferedCountReturnsThePlainRowCount(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->fluentSelectStub());
        $connection->method('fetchOne')->willReturn('42');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        self::assertSame(42, (new ReportBufferRepository($resourceConnection))->bufferedCount());
    }

    public function testDeleteDeliveredIssuesNoQueryForAnEmptyList(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('delete');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);

        (new ReportBufferRepository($resourceConnection))->deleteDelivered([]);
    }

    public function testDeleteDeliveredDeletesTheGivenBufferIds(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('delete')
            ->with('watchtower_report_buffer', ['buffer_id IN (?)' => [3, 4]]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        (new ReportBufferRepository($resourceConnection))->deleteDelivered([3, 4]);
    }

    public function testDiscardExpiredDeletesRowsOlderThanTheHorizonAndReturnsTheCount(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('delete')
            ->with('watchtower_report_buffer', ['evaluated_at < ?' => '2026-07-15 15:00:00'])
            ->willReturn(3);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $discarded = (new ReportBufferRepository($resourceConnection))->discardExpired($this->now());

        self::assertSame(3, $discarded);
    }

    private function repositoryWithBackoffRow(array|false $row): ReportBufferRepository
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn($row);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        return new ReportBufferRepository($resourceConnection);
    }

    private function report(int $sequenceNumber): MetricReport
    {
        return new MetricReport(
            storeViewCode: null,
            eventType: 'cron_health',
            status: SignalStatus::Normal,
            sequenceNumber: $sequenceNumber,
            evaluatedAt: $this->now(),
            reason: ReportReason::Heartbeat,
            rulesetVersion: '1.0.1',
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW_STRING);
    }

    private function fluentSelectStub(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        return $select;
    }
}
