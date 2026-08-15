<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\IntegrationHealth;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Bulk\OperationInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\IntegrationHealth\Observation;
use Watchtower\Connector\Model\IntegrationHealth\QueueConsumerObserver;

/**
 * Verified against the real magento_operation schema in the running
 * install (status is a smallint keyed to Magento\Framework\Bulk\
 * OperationInterface's own STATUS_TYPE_* constants: COMPLETE=1,
 * RETRIABLY_FAILED=2, NOT_RETRIABLY_FAILED=3, OPEN=4, REJECTED=5 -- OPEN is
 * "still pending", deliberately excluded from both success and failure).
 */
class QueueConsumerObserverTest extends TestCase
{
    private const NOW_STRING = '2026-08-13T15:00:00+00:00';

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

    public function testFiltersByTheGivenTopicNameAndTheCorrectStatusValues(): void
    {
        $seenWhere = [];
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $select->expects(self::exactly(6))->method('where')->willReturnCallback(
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

        (new QueueConsumerObserver($resourceConnection))->observe('my_erp_bulk_topic', $this->now());

        self::assertSame(['topic_name = ?', 'my_erp_bulk_topic'], $seenWhere[0]);
        self::assertSame(['status = ?', OperationInterface::STATUS_TYPE_COMPLETE], $seenWhere[1]);
        self::assertSame(['topic_name = ?', 'my_erp_bulk_topic'], $seenWhere[3]);
        self::assertSame(
            [
                OperationInterface::STATUS_TYPE_RETRIABLY_FAILED,
                OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED,
                OperationInterface::STATUS_TYPE_REJECTED,
            ],
            $seenWhere[4][1],
            'The failure query must match retriably-failed, not-retriably-failed, and rejected -- never OPEN.'
        );
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

        return (new QueueConsumerObserver($resourceConnection))->observe('my_erp_bulk_topic', $this->now());
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW_STRING);
    }
}
