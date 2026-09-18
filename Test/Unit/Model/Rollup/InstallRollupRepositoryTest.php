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
use Watchtower\Connector\Model\Rollup\InstallRollupRepository;

/**
 * The install-scoped counterpart to RollupRepositoryTest: same
 * upsert/bucket-query/retention properties, keyed by event_name instead of
 * (store_view_id, category), and hourly-only (see InstallRollupRepository's
 * own docblock for why there is no daily table here).
 */
class InstallRollupRepositoryTest extends TestCase
{
    private const NOW_STRING = '2026-08-13T15:00:00+00:00';

    public function testRecordHourlyCountUpsertsAtTheUtcTopOfHour(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('insertOnDuplicate')
            ->with(
                'watchtower_install_rollup_hourly',
                [
                    'event_name' => 'application_error_logged',
                    'hour_bucket' => '2026-08-13 15:00:00',
                    'count' => 7,
                ],
                ['count']
            );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        (new InstallRollupRepository($resourceConnection))->recordHourlyCount(
            'application_error_logged',
            new \DateTimeImmutable('2026-08-13T15:47:12+00:00'),
            7
        );
    }

    public function testHourlyCountsForBucketFiltersByEventNameHourWeekdayAndLookbackWindowAndMapsResults(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('from')
            ->with('watchtower_install_rollup_hourly', ['hour_bucket', 'count'])->willReturnSelf();

        $seenWhere = [];
        $select->expects(self::exactly(5))->method('where')->willReturnCallback(
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

        $samples = (new InstallRollupRepository($resourceConnection))->hourlyCountsForBucket(
            'application_error_logged',
            2,
            14,
            4,
            $this->now()
        );

        self::assertSame(['event_name = ?', 'application_error_logged'], $seenWhere[0]);
        self::assertSame(['HOUR(hour_bucket) = ?', 14], $seenWhere[1]);
        // ISO Tuesday (2) maps to MySQL WEEKDAY()'s Tuesday (1).
        self::assertSame(['WEEKDAY(hour_bucket) = ?', 1], $seenWhere[2]);
        self::assertSame('hour_bucket >= ?', $seenWhere[3][0]);
        self::assertSame($this->now()->modify('-28 days')->format('Y-m-d H:i:s'), $seenWhere[3][1]);
        self::assertSame('hour_bucket <= ?', $seenWhere[4][0]);
        self::assertSame('2026-08-13 15:00:00', $seenWhere[4][1]);

        self::assertCount(2, $samples);
        self::assertEquals(new \DateTimeImmutable('2026-07-28T14:00:00+00:00'), $samples[0]->bucket);
        self::assertSame(12, $samples[0]->count);
        self::assertSame(15, $samples[1]->count);
    }

    public function testHourlyCountsForBucketRejectsAnIsoDayOfWeekOutsideOneThroughSeven(): void
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);

        $this->expectException(\InvalidArgumentException::class);

        (new InstallRollupRepository($resourceConnection))->hourlyCountsForBucket(
            'application_error_logged',
            8,
            10,
            4,
            $this->now()
        );
    }

    public function testHourlyCountsForBucketRejectsAnHourOfDayOutsideZeroThroughTwentyThree(): void
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);

        $this->expectException(\InvalidArgumentException::class);

        (new InstallRollupRepository($resourceConnection))->hourlyCountsForBucket(
            'application_error_logged',
            2,
            24,
            4,
            $this->now()
        );
    }

    public function testPruneDeletesRowsOlderThanTheHourlyRetentionWindow(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('delete')
            ->with(
                'watchtower_install_rollup_hourly',
                ['hour_bucket < ?' => $this->now()->modify('-90 days')->format('Y-m-d H:i:s')]
            )
            ->willReturn(12);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $result = (new InstallRollupRepository($resourceConnection))->prune($this->now());

        self::assertSame(12, $result);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW_STRING);
    }
}
