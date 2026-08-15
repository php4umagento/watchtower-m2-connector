<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\IntegrationHealth;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthEventRepository;
use Watchtower\Connector\Model\IntegrationHealth\Observation;

class IntegrationHealthEventRepositoryTest extends TestCase
{
    private const NOW_STRING = '2026-08-13T15:00:00+00:00';

    public function testRecordInsertsAPlainRowWithAUtcNormalizedTimestamp(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('insert')->with(
            'watchtower_integration_health_event',
            [
                'store_view_id' => 3,
                'integration_label' => 'erp_sync',
                'status' => 'ok',
                'observed_at' => '2026-08-13 15:00:00',
            ]
        );
        $connection->method('delete')->willReturn(0);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        (new IntegrationHealthEventRepository($resourceConnection))->record(3, 'erp_sync', 'ok', $this->now());
    }

    /**
     * record() opportunistically prunes rows for the SAME (store view,
     * integration label) pair older than the retention horizon, using the
     * same composite index the read path relies on -- this table has no
     * dedicated prune cron of its own, unlike RollupRepository.
     */
    public function testRecordPrunesRowsOlderThanTheRetentionHorizonForTheSamePair(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('insert');
        $connection->expects(self::once())->method('delete')->with(
            'watchtower_integration_health_event',
            [
                'store_view_id = ?' => 3,
                'integration_label = ?' => 'erp_sync',
                'observed_at < ?' => '2026-05-15 15:00:00',
            ]
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        (new IntegrationHealthEventRepository($resourceConnection))->record(3, 'erp_sync', 'ok', $this->now());
    }

    public function testASuccessRowWithinTheLookbackProducesLatestSuccessAt(): void
    {
        $observation = $this->observeWith(successRow: '2026-08-13 14:50:00', failureRow: false);

        self::assertEquals(new \DateTimeImmutable('2026-08-13T14:50:00+00:00'), $observation->latestSuccessAt);
        self::assertNull($observation->latestFailureAt);
    }

    public function testAFailureRowWithinTheLookbackProducesLatestFailureAt(): void
    {
        $observation = $this->observeWith(successRow: false, failureRow: '2026-08-13 14:55:00');

        self::assertNull($observation->latestSuccessAt);
        self::assertEquals(new \DateTimeImmutable('2026-08-13T14:55:00+00:00'), $observation->latestFailureAt);
    }

    public function testNoMatchingRowsProducesBothNull(): void
    {
        $observation = $this->observeWith(successRow: false, failureRow: false);

        self::assertNull($observation->latestSuccessAt);
        self::assertNull($observation->latestFailureAt);
    }

    public function testFiltersByBothTheStoreViewAndTheIntegrationLabel(): void
    {
        $seenWhere = [];
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $select->expects(self::exactly(8))->method('where')->willReturnCallback(
            function (string $condition, mixed $value = null) use ($select, &$seenWhere) {
                $seenWhere[] = [$condition, $value];

                return $select;
            }
        );

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn(false);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        (new IntegrationHealthEventRepository($resourceConnection))->latestObservation(3, 'erp_sync', $this->now());

        self::assertSame(['store_view_id = ?', 3], $seenWhere[0]);
        self::assertSame(['integration_label = ?', 'erp_sync'], $seenWhere[1]);
        self::assertSame(['status = ?', 'ok'], $seenWhere[2]);
        self::assertSame(['store_view_id = ?', 3], $seenWhere[4]);
        self::assertSame(['integration_label = ?', 'erp_sync'], $seenWhere[5]);
        self::assertSame(['status = ?', 'failed'], $seenWhere[6]);
    }

    private function observeWith(string|false $successRow, string|false $failureRow): Observation
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls($successRow, $failureRow);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $repository = new IntegrationHealthEventRepository($resourceConnection);

        return $repository->latestObservation(3, 'erp_sync', $this->now());
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW_STRING);
    }
}
