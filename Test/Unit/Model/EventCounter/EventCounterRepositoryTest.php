<?php

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\EventCounter;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\EventCounter\EventCounterRepository;

/**
 * Unlike RollupRepository's recordHourlyCount() (an overwrite-with-latest
 * upsert), increment()/incrementDropped() must accumulate one discrete
 * event at a time -- the exact distinction the class docblock calls out.
 * These tests assert the upsert uses an accumulating SQL expression
 * (`count = count + VALUES(count)`), not the plain overwrite
 * insertOnDuplicate() produces when the field is passed as a bare value;
 * real MySQL accumulation across repeated calls is confirmed separately by
 * live verification (see progress.txt), since a mocked AdapterInterface
 * cannot itself execute SQL.
 */
class EventCounterRepositoryTest extends TestCase
{
    public function testIncrementUpsertsWithAnAccumulatingCountExpressionForANewRow(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('insertOnDuplicate')
            ->with(
                'watchtower_event_counter',
                [
                    'store_view_id' => 1,
                    'event_name' => 'customer_login',
                    'hour_bucket' => '2026-08-13 15:00:00',
                    'count' => 1,
                ],
                self::callback(fn (array $fields) => $this->isAccumulatingCountExpression($fields))
            );

        $resourceConnection = $this->resourceConnectionReturning($connection, 'watchtower_event_counter');

        (new EventCounterRepository($resourceConnection))->increment(
            1,
            'customer_login',
            new \DateTimeImmutable('2026-08-13T15:24:10+00:00')
        );
    }

    /**
     * The precise scenario the story asks for: two logins in the same hour
     * for the same store must accumulate to count=2, not overwrite to a
     * second count=1. Both calls carry the identical accumulating
     * expression -- there is no branch in increment() that would ever
     * downgrade a repeat call to a plain overwrite.
     */
    public function testIncrementCalledTwiceForTheSameHourBothUseTheSameAccumulatingExpression(): void
    {
        $calls = [];

        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::exactly(2))
            ->method('insertOnDuplicate')
            ->willReturnCallback(function (string $table, array $data, array $fields) use (&$calls) {
                $calls[] = [$table, $data, $fields];

                return 1;
            });

        $resourceConnection = $this->resourceConnectionReturning($connection, 'watchtower_event_counter');
        $repository = new EventCounterRepository($resourceConnection);

        $now = new \DateTimeImmutable('2026-08-13T15:05:00+00:00');
        $repository->increment(1, 'customer_login', $now);
        $repository->increment(1, 'customer_login', new \DateTimeImmutable('2026-08-13T15:40:00+00:00'));

        self::assertCount(2, $calls);
        foreach ($calls as [$table, $data, $fields]) {
            self::assertSame('watchtower_event_counter', $table);
            self::assertSame('2026-08-13 15:00:00', $data['hour_bucket']);
            self::assertSame(1, $data['count']);
            self::assertTrue($this->isAccumulatingCountExpression($fields));
        }
    }

    public function testIncrementDroppedUsesTheSameAccumulatingSemanticsOnTheDropTable(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('insertOnDuplicate')
            ->with(
                'watchtower_event_drop_counter',
                [
                    'event_name' => 'customer_login',
                    'hour_bucket' => '2026-08-13 15:00:00',
                    'count' => 1,
                ],
                self::callback(fn (array $fields) => $this->isAccumulatingCountExpression($fields))
            );

        $resourceConnection = $this->resourceConnectionReturning($connection, 'watchtower_event_drop_counter');

        (new EventCounterRepository($resourceConnection))->incrementDropped(
            'customer_login',
            new \DateTimeImmutable('2026-08-13T15:59:59+00:00')
        );
    }

    public function testCountForReturnsZeroWhenNoRowExistsYet(): void
    {
        $repository = $this->repositoryReturning(fetchOneResult: false);
        $hourBucket = new \DateTimeImmutable('2026-08-13T15:00:00+00:00');

        self::assertSame(0, $repository->countFor(1, 'customer_login', $hourBucket));
    }

    public function testCountForReturnsThePersistedCount(): void
    {
        $repository = $this->repositoryReturning(fetchOneResult: '2');
        $hourBucket = new \DateTimeImmutable('2026-08-13T15:00:00+00:00');

        self::assertSame(2, $repository->countFor(1, 'customer_login', $hourBucket));
    }

    public function testDroppedCountForReturnsZeroWhenNoRowExistsYet(): void
    {
        $repository = $this->repositoryReturning(fetchOneResult: false);
        $hourBucket = new \DateTimeImmutable('2026-08-13T15:00:00+00:00');

        self::assertSame(0, $repository->droppedCountFor('customer_login', $hourBucket));
    }

    public function testDroppedCountForReturnsThePersistedCount(): void
    {
        $repository = $this->repositoryReturning(fetchOneResult: '3');
        $hourBucket = new \DateTimeImmutable('2026-08-13T15:00:00+00:00');

        self::assertSame(3, $repository->droppedCountFor('customer_login', $hourBucket));
    }

    public function testTotalDroppedInLast24HoursReturnsZeroWhenNoRowsMatch(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        // A SUM() over zero matching rows is SQL NULL, not "no row" (false)
        // -- an aggregate query always returns exactly one row.
        $connection->method('fetchOne')->willReturn(null);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $repository = new EventCounterRepository($resourceConnection);

        $now = new \DateTimeImmutable('2026-08-13T15:00:00+00:00');
        self::assertSame(0, $repository->totalDroppedInLast24Hours($now));
    }

    public function testTotalDroppedInLast24HoursSumsAcrossEventNamesAndUsesA24HourWindow(): void
    {
        $seenWhere = [];
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            function (string $condition, mixed $value) use ($select, &$seenWhere) {
                $seenWhere[] = [$condition, $value];

                return $select;
            }
        );

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn('7');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $repository = new EventCounterRepository($resourceConnection);
        $total = $repository->totalDroppedInLast24Hours(new \DateTimeImmutable('2026-08-13T15:30:00+00:00'));

        self::assertSame(7, $total);
        self::assertSame([['hour_bucket >= ?', '2026-08-12 15:00:00']], $seenWhere);
    }

    private function isAccumulatingCountExpression(array $fields): bool
    {
        if (!isset($fields['count']) || !$fields['count'] instanceof \Zend_Db_Expr) {
            return false;
        }

        return (string) $fields['count'] === 'count + VALUES(count)';
    }

    private function resourceConnectionReturning(AdapterInterface $connection, string $tableName): ResourceConnection
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturn($tableName);

        return $resourceConnection;
    }

    private function repositoryReturning(string|false $fetchOneResult): EventCounterRepository
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn($fetchOneResult);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        return new EventCounterRepository($resourceConnection);
    }
}
