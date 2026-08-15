<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Diagnostics;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Diagnostics\SubmissionOutcomeRepository;

class SubmissionOutcomeRepositoryTest extends TestCase
{
    public function testRecordInsertsTheOutcomeRowInUtc(): void
    {
        $captured = null;
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with('watchtower_submission_outcome_log', self::callback(function (array $row) use (&$captured) {
                $captured = $row;

                return true;
            }));
        $connection->method('select')->willReturn($this->fluentSelectStub());
        // Comfortably under MAX_ROWS -- pruning must stay a no-op.
        $connection->method('fetchOne')->willReturn('1');
        $connection->expects(self::never())->method('delete');

        $rejected = [
            [
                'event_type' => 'checkout',
                'sequence_number' => 5,
                'reason' => 'store view not recognised for this install',
            ],
        ];

        $repository = new SubmissionOutcomeRepository($this->resourceConnectionFor($connection));
        $repository->record(true, 3, $rejected, null, new \DateTimeImmutable('2026-08-14T09:00:00+02:00'));

        self::assertTrue($captured['succeeded']);
        self::assertSame(3, $captured['accepted_count']);
        self::assertSame(1, $captured['rejected_count']);
        self::assertNull($captured['error_message']);
        // Stored in UTC regardless of the DateTimeImmutable's own offset.
        self::assertSame('2026-08-14 07:00:00', $captured['occurred_at']);
    }

    /**
     * rejectedCount alone gives no way to tell a batch of benign
     * already-delivered dedups apart from a batch of genuinely
     * unrecognized store views, so rejected[] is derived into a
     * reason => count breakdown and persisted as JSON.
     */
    public function testRecordDerivesAndPersistsTheRejectionReasonBreakdown(): void
    {
        $captured = null;
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with('watchtower_submission_outcome_log', self::callback(function (array $row) use (&$captured) {
                $captured = $row;

                return true;
            }));
        $connection->method('select')->willReturn($this->fluentSelectStub());
        $connection->method('fetchOne')->willReturn('1');

        $dedupReason = 'sequence_number is out of order or already recorded';
        $rejected = [
            ['event_type' => 'cron_health', 'sequence_number' => 1, 'reason' => $dedupReason],
            ['event_type' => 'checkout', 'sequence_number' => 2, 'reason' => $dedupReason],
            [
                'event_type' => 'basket_quote',
                'sequence_number' => 3,
                'reason' => 'store view not recognised for this install',
            ],
        ];

        $repository = new SubmissionOutcomeRepository($this->resourceConnectionFor($connection));
        $repository->record(true, 2, $rejected, null, new \DateTimeImmutable('2026-08-14T09:00:00+00:00'));

        self::assertSame(3, $captured['rejected_count']);
        self::assertSame(
            [
                'sequence_number is out of order or already recorded' => 2,
                'store view not recognised for this install' => 1,
            ],
            json_decode((string) $captured['rejection_reasons'], true)
        );
    }

    public function testRecordStoresNullRejectionReasonsWhenNothingWasRejected(): void
    {
        $captured = null;
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with('watchtower_submission_outcome_log', self::callback(function (array $row) use (&$captured) {
                $captured = $row;

                return true;
            }));
        $connection->method('select')->willReturn($this->fluentSelectStub());
        $connection->method('fetchOne')->willReturn('1');

        $repository = new SubmissionOutcomeRepository($this->resourceConnectionFor($connection));
        $repository->record(true, 5, [], null, new \DateTimeImmutable('2026-08-14T09:00:00+00:00'));

        self::assertSame(0, $captured['rejected_count']);
        self::assertNull($captured['rejection_reasons']);
    }

    public function testRecordTruncatesAnOverlongErrorMessageTo255Chars(): void
    {
        $captured = null;
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with('watchtower_submission_outcome_log', self::callback(function (array $row) use (&$captured) {
                $captured = $row;

                return true;
            }));
        $connection->method('select')->willReturn($this->fluentSelectStub());
        $connection->method('fetchOne')->willReturn('1');

        $repository = new SubmissionOutcomeRepository($this->resourceConnectionFor($connection));
        $repository->record(false, 0, [], str_repeat('x', 300), new \DateTimeImmutable('2026-08-14T09:00:00+00:00'));

        self::assertSame(255, strlen($captured['error_message']));
    }

    /**
     * Same self-pruning shape as ReportBufferRepository::evictOldestBeyondCapacity() --
     * the oldest rows (lowest id) beyond MAX_ROWS (100) are evicted after
     * every write.
     */
    public function testRecordPrunesTheOldestRowsWhenOverCapacity(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('insert');

        $countSelect = $this->fluentSelectStub();
        $oldestIdsSelect = $this->fluentSelectStub();

        $connection->method('select')->willReturnOnConsecutiveCalls($countSelect, $oldestIdsSelect);
        // 102 total after this insert -- 2 over the 100 cap.
        $connection->method('fetchOne')->willReturn('102');
        $connection->method('fetchCol')->willReturn([101, 102]);

        $connection->expects(self::once())
            ->method('delete')
            ->with('watchtower_submission_outcome_log', ['id IN (?)' => [101, 102]]);

        $repository = new SubmissionOutcomeRepository($this->resourceConnectionFor($connection));
        $repository->record(true, 1, [], null, new \DateTimeImmutable('2026-08-14T09:00:00+00:00'));
    }

    public function testRecordDoesNotPruneWhenExactlyAtCapacity(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('insert');
        $connection->method('select')->willReturn($this->fluentSelectStub());
        $connection->method('fetchOne')->willReturn('100');
        $connection->expects(self::never())->method('delete');

        $repository = new SubmissionOutcomeRepository($this->resourceConnectionFor($connection));
        $repository->record(true, 1, [], null, new \DateTimeImmutable('2026-08-14T09:00:00+00:00'));
    }

    public function testRecentReturnsRowsNewestFirstMappedToValueObjects(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->fluentSelectStub());
        $connection->method('fetchAll')->willReturn([
            [
                'id' => 2,
                'succeeded' => 0,
                'accepted_count' => 0,
                'rejected_count' => 1,
                'rejection_reasons' => json_encode(['store view not recognised for this install' => 1]),
                'error_message' => 'Connection refused',
                'occurred_at' => '2026-08-14 08:00:00',
            ],
            [
                'id' => 1,
                'succeeded' => 1,
                'accepted_count' => 5,
                'rejected_count' => 0,
                'rejection_reasons' => null,
                'error_message' => null,
                'occurred_at' => '2026-08-14 07:00:00',
            ],
        ]);

        $repository = new SubmissionOutcomeRepository($this->resourceConnectionFor($connection));
        $outcomes = $repository->recent(20);

        self::assertCount(2, $outcomes);
        self::assertFalse($outcomes[0]->succeeded);
        self::assertSame('Connection refused', $outcomes[0]->errorMessage);
        self::assertSame(['store view not recognised for this install' => 1], $outcomes[0]->rejectionReasons);
        self::assertTrue($outcomes[1]->succeeded);
        self::assertSame(5, $outcomes[1]->acceptedCount);
        self::assertSame(0, $outcomes[1]->rejectedCount);
        self::assertSame([], $outcomes[1]->rejectionReasons);
        self::assertNull($outcomes[1]->errorMessage);
    }

    private function fluentSelectStub(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        return $select;
    }

    private function resourceConnectionFor(AdapterInterface $connection): ResourceConnection
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        return $resourceConnection;
    }
}
