<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Signal;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Signal\CustomerAccountRegistrationReader;

/**
 * customer_account's registrations
 * sub-counter counts `customer_entity` rows by created_at, filtered by
 * store_id -- the only table-sourced piece of customer_account, since
 * logins/logouts have no store-attributable table source (see the reader's
 * own docblock).
 */
class CustomerAccountRegistrationReaderTest extends TestCase
{
    public function testCountForWindowReturnsZeroWhenNoRowsMatch(): void
    {
        $reader = new CustomerAccountRegistrationReader($this->resourceConnectionReturningCount('0'));

        self::assertSame(
            0,
            $reader->countForWindow(
                4,
                new \DateTimeImmutable('2026-08-13T14:00:00+00:00'),
                new \DateTimeImmutable('2026-08-13T15:00:00+00:00')
            )
        );
    }

    public function testCountForWindowCastsTheAggregateResultToInt(): void
    {
        $reader = new CustomerAccountRegistrationReader($this->resourceConnectionReturningCount('5'));

        self::assertSame(
            5,
            $reader->countForWindow(
                4,
                new \DateTimeImmutable('2026-08-13T14:00:00+00:00'),
                new \DateTimeImmutable('2026-08-13T15:00:00+00:00')
            )
        );
    }

    public function testCountForWindowFiltersByStoreAndTheStartInclusiveEndExclusiveWindow(): void
    {
        $seenWhere = [];
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('from')
            ->with('customer_entity', self::callback(function (array $columns): bool {
                self::assertSame(['count'], array_keys($columns));
                self::assertSame('COUNT(*)', (string) $columns['count']);

                return true;
            }))
            ->willReturnSelf();
        $select->expects(self::exactly(3))->method('where')->willReturnCallback(
            function (string $condition, mixed $value = null) use ($select, &$seenWhere) {
                $seenWhere[] = [$condition, $value];

                return $select;
            }
        );

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn('2');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $count = (new CustomerAccountRegistrationReader($resourceConnection))->countForWindow(
            9,
            new \DateTimeImmutable('2026-08-13T14:00:00+00:00'),
            new \DateTimeImmutable('2026-08-13T15:00:00+00:00')
        );

        self::assertSame(2, $count);
        self::assertSame(['store_id = ?', 9], $seenWhere[0]);
        // Start-inclusive.
        self::assertSame(['created_at >= ?', '2026-08-13 14:00:00'], $seenWhere[1]);
        // End-exclusive.
        self::assertSame(['created_at < ?', '2026-08-13 15:00:00'], $seenWhere[2]);
    }

    private function resourceConnectionReturningCount(string $rawCount): ResourceConnection
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn($rawCount);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        return $resourceConnection;
    }
}
