<?php

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Signal;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Signal\CheckoutReader;

/**
 * checkout counts `sales_order` rows by
 * created_at with NO status filter -- a cancellation, hold, or refund is a
 * separate polarity handled elsewhere, never a modifier on this count.
 * These tests lock that unconditional-on-status contract, plus the exact
 * where-clause window (see BasketQuoteReaderTest for why a mock-based
 * where-clause assertion is this module's way of proving window inclusion/
 * exclusion without a real database).
 */
class CheckoutReaderTest extends TestCase
{
    public function testCountForWindowReturnsZeroWhenNoRowsMatch(): void
    {
        $reader = new CheckoutReader($this->resourceConnectionReturningCount('0'));

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
        $reader = new CheckoutReader($this->resourceConnectionReturningCount('8'));

        self::assertSame(
            8,
            $reader->countForWindow(
                4,
                new \DateTimeImmutable('2026-08-13T14:00:00+00:00'),
                new \DateTimeImmutable('2026-08-13T15:00:00+00:00')
            )
        );
    }

    /**
     * The one criterion this reader's design exists to satisfy: every order
     * status -- pending, complete, canceled, on hold, whatever else exists
     * -- is counted the same way, because the where-clause this reader
     * builds never mentions `status` at all. Asserted here by capturing
     * every where() call and proving none of them reference the status
     * column, rather than by stubbing four different "order in state X"
     * fixtures that a real (untested) status filter could still pass.
     */
    public function testCountForWindowIsUnconditionalOnOrderStatus(): void
    {
        $seenWhere = [];
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('from')
            ->with('sales_order', self::callback(function (array $columns): bool {
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
        $connection->method('fetchOne')->willReturn('11');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $count = (new CheckoutReader($resourceConnection))->countForWindow(
            9,
            new \DateTimeImmutable('2026-08-13T14:00:00+00:00'),
            new \DateTimeImmutable('2026-08-13T15:00:00+00:00')
        );

        self::assertSame(11, $count);
        self::assertSame(['store_id = ?', 9], $seenWhere[0]);
        // Start-inclusive.
        self::assertSame(['created_at >= ?', '2026-08-13 14:00:00'], $seenWhere[1]);
        // End-exclusive.
        self::assertSame(['created_at < ?', '2026-08-13 15:00:00'], $seenWhere[2]);

        foreach ($seenWhere as [$condition]) {
            self::assertStringNotContainsString(
                'status',
                $condition,
                'CheckoutReader must never filter by order status -- status mutates post-creation and '
                . 'the reversal/cancellation exclusion belongs to a separate story, not this reader.'
            );
        }
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
