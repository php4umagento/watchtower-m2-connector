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
use Watchtower\Connector\Model\Signal\BasketQuoteReader;

/**
 * basket_quote counts non-empty `quote`
 * rows by created_at, with a redundant updated_at lower bound added purely
 * to keep the query index-friendly (see the reader's own docblock). These
 * tests lock the exact where-clause this reader builds -- the only way to
 * prove "rows outside the window are excluded" and "the boundary is
 * start-inclusive/end-exclusive" without a real database, matching this
 * module's existing mock-based repository test style (see
 * RollupRepositoryTest).
 */
class BasketQuoteReaderTest extends TestCase
{
    public function testCountForWindowReturnsZeroWhenNoRowsMatch(): void
    {
        $reader = new BasketQuoteReader($this->resourceConnectionReturningCount('0'));

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
        $reader = new BasketQuoteReader($this->resourceConnectionReturningCount('12'));

        self::assertSame(
            12,
            $reader->countForWindow(
                4,
                new \DateTimeImmutable('2026-08-13T14:00:00+00:00'),
                new \DateTimeImmutable('2026-08-13T15:00:00+00:00')
            )
        );
    }

    public function testCountForWindowFiltersByStoreNonEmptyCartsAndTheStartInclusiveEndExclusiveWindow(): void
    {
        $seenWhere = [];
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('from')
            ->with('quote', self::callback(function (array $columns): bool {
                self::assertSame(['count'], array_keys($columns));
                self::assertSame('COUNT(*)', (string) $columns['count']);

                return true;
            }))
            ->willReturnSelf();
        $select->expects(self::exactly(5))->method('where')->willReturnCallback(
            function (string $condition, mixed $value = null) use ($select, &$seenWhere) {
                $seenWhere[] = [$condition, $value];

                return $select;
            }
        );

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn('3');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $count = (new BasketQuoteReader($resourceConnection))->countForWindow(
            9,
            new \DateTimeImmutable('2026-08-13T14:00:00+00:00'),
            new \DateTimeImmutable('2026-08-13T15:00:00+00:00')
        );

        self::assertSame(3, $count);
        self::assertSame(['store_id = ?', 9], $seenWhere[0]);
        self::assertSame(['items_count > ?', 0], $seenWhere[1]);
        self::assertSame(['updated_at >= ?', '2026-08-13 14:00:00'], $seenWhere[2]);
        // Start-inclusive: the window's first instant is included via >=.
        self::assertSame(['created_at >= ?', '2026-08-13 14:00:00'], $seenWhere[3]);
        // End-exclusive: the window's last instant (15:00:00 itself, the
        // next hour's first instant) is excluded via strict <.
        self::assertSame(['created_at < ?', '2026-08-13 15:00:00'], $seenWhere[4]);
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
