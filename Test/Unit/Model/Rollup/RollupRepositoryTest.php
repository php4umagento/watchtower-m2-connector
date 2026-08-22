<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Rollup;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Rollup\RollupRepository;

/**
 * The local historical retention store: watchtower_rollup_hourly (~90
 * days) rolled up into watchtower_rollup_daily (400+ days) as hourly rows
 * age out. These tests lock the three properties the seeders and the
 * evaluator depend on: recordHourlyCount() is a true upsert,
 * rollupAndPrune() only ever moves a day once every one of its
 * hours is past the cutoff (never a partial-day double-count) and isolates
 * each day group in its own transaction, and hourlyCountsForBucket()
 * returns exactly the same-hour-of-day/day-of-week series a median/MAD
 * baseline needs.
 */
class RollupRepositoryTest extends TestCase
{
    private const NOW_STRING = '2026-08-13T15:00:00+00:00';

    public function testRecordHourlyCountUpsertsAtTheUtcTopOfHour(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('insertOnDuplicate')
            ->with(
                'watchtower_rollup_hourly',
                [
                    'store_view_id' => 4,
                    'category' => 'checkout',
                    'hour_bucket' => '2026-08-13 15:00:00',
                    'count' => 7,
                ],
                ['count']
            );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        (new RollupRepository($resourceConnection))->recordHourlyCount(
            4,
            'checkout',
            new \DateTimeImmutable('2026-08-13T15:47:12+00:00'),
            7
        );
    }

    public function testRollupAndPruneMovesOneDayGroupIntoDailyAndDeletesExactlyThatDaysHourlyRows(): void
    {
        $insertedRows = [];
        $deletedCalls = [];
        $connection = $this->connectionForGroups(
            [
                ['store_view_id' => '4', 'category' => 'checkout', 'day_bucket' => '2026-05-01', 'total' => '42'],
            ],
            $insertedRows,
            $deletedCalls
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $result = (new RollupRepository($resourceConnection))->rollupAndPrune($this->now());

        self::assertSame(1, $result->rolledDayGroups);
        self::assertSame(24, $result->hourlyRowsPruned);

        self::assertSame([
            'store_view_id' => 4,
            'category' => 'checkout',
            'day_bucket' => '2026-05-01',
            'count' => 42,
        ], $insertedRows[0]);

        $hourlyDelete = $deletedCalls[0];
        self::assertSame('watchtower_rollup_hourly', $hourlyDelete[0]);
        self::assertSame([
            'store_view_id = ?' => 4,
            'category = ?' => 'checkout',
            'hour_bucket >= ?' => '2026-05-01 00:00:00',
            'hour_bucket < ?' => '2026-05-02 00:00:00',
        ], $hourlyDelete[1]);
    }

    public function testRollupAndPruneTreatsEachDayGroupAsItsOwnTransaction(): void
    {
        $insertedRows = [];
        $deletedCalls = [];
        $connection = $this->connectionForGroups(
            [
                ['store_view_id' => '4', 'category' => 'checkout', 'day_bucket' => '2026-05-01', 'total' => '42'],
                ['store_view_id' => '4', 'category' => 'basket_quote', 'day_bucket' => '2026-05-02', 'total' => '10'],
            ],
            $insertedRows,
            $deletedCalls
        );

        $connection->expects(self::exactly(2))->method('beginTransaction');
        $connection->expects(self::exactly(2))->method('commit');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $result = (new RollupRepository($resourceConnection))->rollupAndPrune($this->now());

        self::assertSame(2, $result->rolledDayGroups);
        self::assertCount(2, $insertedRows);
        self::assertSame('checkout', $insertedRows[0]['category']);
        self::assertSame('basket_quote', $insertedRows[1]['category']);
    }

    public function testRollupAndPruneLeavesRowsWithinTheRetentionWindowUntouchedWhenNoGroupsQualify(): void
    {
        $insertedRows = [];
        $deletedCalls = [];
        $connection = $this->connectionForGroups([], $insertedRows, $deletedCalls);

        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::never())->method('insertOnDuplicate');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $result = (new RollupRepository($resourceConnection))->rollupAndPrune($this->now());

        self::assertSame(0, $result->rolledDayGroups);
        self::assertSame(0, $result->hourlyRowsPruned);
    }

    public function testRollupAndPruneRollsBackTheTransactionAndPropagatesOnFailure(): void
    {
        $insertedRows = [];
        $deletedCalls = [];
        $connection = $this->connectionForGroups(
            [
                ['store_view_id' => '4', 'category' => 'checkout', 'day_bucket' => '2026-05-01', 'total' => '42'],
            ],
            $insertedRows,
            $deletedCalls,
            deleteThrows: true
        );

        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $this->expectException(\RuntimeException::class);

        (new RollupRepository($resourceConnection))->rollupAndPrune($this->now());
    }

    public function testRollupAndPrunePrunesDailyRowsOlderThanTheDailyRetentionWindow(): void
    {
        $insertedRows = [];
        $deletedCalls = [];
        $connection = $this->connectionForGroups([], $insertedRows, $deletedCalls);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $result = (new RollupRepository($resourceConnection))->rollupAndPrune($this->now());

        $expectedCutoff = $this->now()->modify('-400 days')->format('Y-m-d H:i:s');

        self::assertSame('watchtower_rollup_daily', $deletedCalls[0][0]);
        self::assertSame(['day_bucket < ?' => $expectedCutoff], $deletedCalls[0][1]);
        self::assertSame(3, $result->dailyRowsPruned);
    }

    /**
     * Regression coverage for the query HistorySeeder::seed()'s automatic
     * on-enable trigger (ReportingService::seedIfNeverSeeded()) gates on:
     * a row for ANY of the given categories means "already tracked, don't
     * re-seed" -- proven here by asserting the exact WHERE/LIMIT shape, not
     * just the boolean outcome, since a query that silently dropped the
     * store_view_id filter (or the category IN list) would make this method
     * return true for the wrong store view or category and permanently skip
     * seeding a genuinely fresh one.
     */
    public function testHasAnyHourlyDataForCategoriesReturnsTrueWhenAMatchingRowExists(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('from')->willReturnCallback(
            function (string $table, array $columns) use ($select): Select {
                self::assertSame('watchtower_rollup_hourly', $table);
                self::assertCount(1, $columns);
                self::assertInstanceOf(\Zend_Db_Expr::class, $columns[0]);

                return $select;
            }
        );

        $seenWhere = [];
        $select->expects(self::exactly(2))->method('where')->willReturnCallback(
            function (string $condition, mixed $value = null) use ($select, &$seenWhere) {
                $seenWhere[] = [$condition, $value];

                return $select;
            }
        );
        $select->expects(self::once())->method('limit')->with(1)->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn('1');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $result = (new RollupRepository($resourceConnection))->hasAnyHourlyDataForCategories(
            9,
            ['basket_quote', 'checkout', 'customer_account']
        );

        self::assertTrue($result);
        self::assertSame(['store_view_id = ?', 9], $seenWhere[0]);
        self::assertSame(
            ['category IN (?)', ['basket_quote', 'checkout', 'customer_account']],
            $seenWhere[1]
        );
    }

    /**
     * The false side: a genuinely fresh store view with no rollup rows for
     * any of the given categories yet must be reported as never-seeded, not
     * default to "already covered" -- fetchOne() returning MySQL's real
     * "no row" sentinel (false, not null) is the exact boundary case
     * ->fetchOne() !== false above guards.
     */
    public function testHasAnyHourlyDataForCategoriesReturnsFalseWhenNoRowExists(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn(false);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $result = (new RollupRepository($resourceConnection))->hasAnyHourlyDataForCategories(
            9,
            ['basket_quote', 'checkout', 'customer_account']
        );

        self::assertFalse($result);
    }

    public function testHourlyCountsForBucketFiltersByHourWeekdayAndLookbackWindowAndMapsResults(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('from')
            ->with('watchtower_rollup_hourly', ['hour_bucket', 'count'])->willReturnSelf();

        $seenWhere = [];
        $select->expects(self::exactly(6))->method('where')->willReturnCallback(
            function (string $condition, mixed $value = null) use ($select, &$seenWhere) {
                $seenWhere[] = [$condition, $value];

                return $select;
            }
        );
        $select->expects(self::once())->method('order')->with('hour_bucket ASC')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([
            ['hour_bucket' => '2026-07-28 14:00:00', 'count' => '12'],
            ['hour_bucket' => '2026-08-04 14:00:00', 'count' => '15'],
        ]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $samples = (new RollupRepository($resourceConnection))->hourlyCountsForBucket(
            9,
            'checkout',
            2,
            14,
            4,
            $this->now()
        );

        self::assertSame(['store_view_id = ?', 9], $seenWhere[0]);
        self::assertSame(['category = ?', 'checkout'], $seenWhere[1]);
        self::assertSame(['HOUR(hour_bucket) = ?', 14], $seenWhere[2]);
        // ISO Tuesday (2) maps to MySQL WEEKDAY()'s Tuesday (1).
        self::assertSame(['WEEKDAY(hour_bucket) = ?', 1], $seenWhere[3]);
        self::assertSame('hour_bucket >= ?', $seenWhere[4][0]);
        self::assertSame($this->now()->modify('-28 days')->format('Y-m-d H:i:s'), $seenWhere[4][1]);
        self::assertSame('hour_bucket <= ?', $seenWhere[5][0]);
        self::assertSame('2026-08-13 15:00:00', $seenWhere[5][1]);

        self::assertCount(2, $samples);
        self::assertEquals(new \DateTimeImmutable('2026-07-28T14:00:00+00:00'), $samples[0]->bucket);
        self::assertSame(12, $samples[0]->count);
        self::assertSame(15, $samples[1]->count);
    }

    public function testHourlyCountsForBucketRejectsAnIsoDayOfWeekOutsideOneThroughSeven(): void
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);

        $this->expectException(\InvalidArgumentException::class);

        (new RollupRepository($resourceConnection))->hourlyCountsForBucket(1, 'checkout', 8, 10, 4, $this->now());
    }

    public function testHourlyCountsForBucketRejectsAnHourOfDayOutsideZeroThroughTwentyThree(): void
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);

        $this->expectException(\InvalidArgumentException::class);

        (new RollupRepository($resourceConnection))->hourlyCountsForBucket(1, 'checkout', 2, 24, 4, $this->now());
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW_STRING);
    }

    /**
     * Builds a mock AdapterInterface wired to return the given grouped
     * rows from the roll-up query, capturing every insertOnDuplicate call
     * into $insertedRows and every delete call into $deletedCalls (both
     * out-parameters, populated once the repository method under test
     * actually runs).
     *
     * @param array $groups
     * @param array $insertedRows
     * @param array $deletedCalls
     * @param bool $deleteThrows
     * @return AdapterInterface
     */
    private function connectionForGroups(
        array $groups,
        array &$insertedRows,
        array &$deletedCalls,
        bool $deleteThrows = false
    ): AdapterInterface {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($groups);

        $insertedRows = [];
        $connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $row) use (&$insertedRows) {
                if ($table === 'watchtower_rollup_daily') {
                    $insertedRows[] = $row;
                }

                return 1;
            }
        );

        $hourlyDeleteCounts = [24, 5];
        $hourlyDeleteIndex = 0;
        $deletedCalls = [];
        $connection->method('delete')->willReturnCallback(
            function (
                string $table,
                array $where
            ) use (
                &$deletedCalls,
                &$hourlyDeleteIndex,
                $hourlyDeleteCounts,
                $deleteThrows
            ) {
                $deletedCalls[] = [$table, $where];

                if (array_key_exists('hour_bucket >= ?', $where)) {
                    if ($deleteThrows) {
                        throw new \RuntimeException('simulated delete failure');
                    }

                    return $hourlyDeleteCounts[$hourlyDeleteIndex++];
                }

                return 3;
            }
        );

        return $connection;
    }
}
