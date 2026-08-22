<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Seed;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Seed\HistorySeeder;
use Watchtower\Connector\Model\Seed\SeedCoverageRepository;
use Watchtower\Connector\Model\Seed\SeedCoverageResult;
use Watchtower\Connector\Model\Seed\SeedCoverageStatus;
use Watchtower\Connector\Model\Seed\SeedLimitReason;

/**
 * DiagnosticsSnapshotProvider must never re-invoke HistorySeeder::seed()
 * (a side-effecting write) just to render a page, so this repository is
 * the only way seed coverage reaches diagnostics -- these tests prove the
 * round trip preserves every field, including the nullable limit reason
 * and retention-days fields Seeded results never carry.
 */
class SeedCoverageRepositoryTest extends TestCase
{
    public function testGetReturnsNullWhenNoRowExistsYet(): void
    {
        $repository = $this->repositoryReturning(fetchRowResult: false);

        self::assertNull($repository->get(7, HistorySeeder::CATEGORY_BASKET_QUOTE));
    }

    public function testGetMapsASeededRowWithoutALimitReason(): void
    {
        $repository = $this->repositoryReturning(fetchRowResult: [
            'status' => 'seeded',
            'requested_days' => '84',
            'days_seeded' => '26',
            'limit_reason' => null,
            'source_retention_days' => null,
        ]);

        $result = $repository->get(7, HistorySeeder::CATEGORY_BASKET_QUOTE);

        self::assertNotNull($result);
        self::assertSame(SeedCoverageStatus::Seeded, $result->status);
        self::assertSame(84, $result->requestedDays);
        self::assertSame(26, $result->daysSeeded);
        self::assertNull($result->limitReason);
        self::assertNull($result->sourceRetentionDays);
    }

    public function testGetMapsALimitedRowWithARetentionCliffReason(): void
    {
        $repository = $this->repositoryReturning(fetchRowResult: [
            'status' => 'limited',
            'requested_days' => '84',
            'days_seeded' => '5',
            'limit_reason' => 'retention_cliff',
            'source_retention_days' => '7',
        ]);

        $result = $repository->get(7, HistorySeeder::CATEGORY_BASKET_QUOTE);

        self::assertNotNull($result);
        self::assertSame(SeedCoverageStatus::Limited, $result->status);
        self::assertSame(SeedLimitReason::RetentionCliff, $result->limitReason);
        self::assertSame(7, $result->sourceRetentionDays);
    }

    public function testSavePersistsEveryFieldIncludingNullsThroughInsertOnDuplicate(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('insertOnDuplicate')
            ->with(
                'watchtower_seed_coverage',
                [
                    'store_view_id' => 7,
                    'category' => HistorySeeder::CATEGORY_BASKET_QUOTE,
                    'status' => 'seeded',
                    'requested_days' => 84,
                    'days_seeded' => 26,
                    'limit_reason' => null,
                    'source_retention_days' => null,
                ],
                ['status', 'requested_days', 'days_seeded', 'limit_reason', 'source_retention_days']
            );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturn('watchtower_seed_coverage');

        $result = new SeedCoverageResult(
            category: HistorySeeder::CATEGORY_BASKET_QUOTE,
            requestedDays: 84,
            daysSeeded: 26,
            status: SeedCoverageStatus::Seeded,
        );

        (new SeedCoverageRepository($resourceConnection))->save(7, $result);
    }

    private function repositoryReturning(array|false $fetchRowResult): SeedCoverageRepository
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn($fetchRowResult);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturn('watchtower_seed_coverage');

        return new SeedCoverageRepository($resourceConnection);
    }
}
