<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\AdminAuthFailure;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\AdminAuthFailure\InstallEventCounterRepository;

/**
 * Mirrors EventCounter\EventCounterRepositoryTest's shape for the one table
 * this class owns, minus the store_view_id dimension, which does not exist
 * here.
 */
class InstallEventCounterRepositoryTest extends TestCase
{
    public function testIncrementUpsertsWithAnAccumulatingCountExpression(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('insertOnDuplicate')
            ->with(
                'watchtower_install_event_counter',
                [
                    'event_name' => 'backend_auth_user_login_failed',
                    'hour_bucket' => '2026-08-13 15:00:00',
                    'count' => 1,
                ],
                self::callback(fn (array $fields) => $this->isAccumulatingCountExpression($fields))
            );

        $resourceConnection = $this->resourceConnectionReturning($connection);

        (new InstallEventCounterRepository($resourceConnection))->increment(
            'backend_auth_user_login_failed',
            new \DateTimeImmutable('2026-08-13T15:24:10+00:00')
        );
    }

    /**
     * The scenario that matters: two failures in the same hour must
     * accumulate to count=2, not overwrite to a second count=1.
     */
    public function testIncrementCalledTwiceForTheSameHourBothAccumulate(): void
    {
        $calls = [];

        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::exactly(2))
            ->method('insertOnDuplicate')
            ->willReturnCallback(function (string $table, array $data, array $fields) use (&$calls) {
                $calls[] = [$table, $data, $fields];

                return 1;
            });

        $resourceConnection = $this->resourceConnectionReturning($connection);
        $repository = new InstallEventCounterRepository($resourceConnection);

        $repository->increment('backend_auth_user_login_failed', new \DateTimeImmutable('2026-08-13T15:05:00+00:00'));
        $repository->increment('backend_auth_user_login_failed', new \DateTimeImmutable('2026-08-13T15:40:00+00:00'));

        self::assertCount(2, $calls);
        foreach ($calls as [$table, $data, $fields]) {
            self::assertSame('watchtower_install_event_counter', $table);
            self::assertSame('2026-08-13 15:00:00', $data['hour_bucket']);
            self::assertSame(1, $data['count']);
            self::assertTrue($this->isAccumulatingCountExpression($fields));
        }
    }

    public function testCountForReturnsZeroWhenNoRowExistsYet(): void
    {
        $repository = $this->repositoryReturning(fetchOneResult: false);
        $hourBucket = new \DateTimeImmutable('2026-08-13T15:00:00+00:00');

        self::assertSame(0, $repository->countFor('backend_auth_user_login_failed', $hourBucket));
    }

    public function testCountForReturnsThePersistedCount(): void
    {
        $repository = $this->repositoryReturning(fetchOneResult: '11');
        $hourBucket = new \DateTimeImmutable('2026-08-13T15:00:00+00:00');

        self::assertSame(11, $repository->countFor('backend_auth_user_login_failed', $hourBucket));
    }

    public function testPruneDeletesRowsPastRetentionAndReturnsTheCount(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('delete')
            ->with('watchtower_install_event_counter', self::isArray())
            ->willReturn(6);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $result = (new InstallEventCounterRepository($resourceConnection))->prune(
            new \DateTimeImmutable('2026-08-13T15:00:00+00:00')
        );

        self::assertSame(6, $result);
    }

    public function testPruneReturnsZeroWhenNothingIsPastRetention(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('delete')->willReturn(0);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $result = (new InstallEventCounterRepository($resourceConnection))->prune(
            new \DateTimeImmutable('2026-08-13T15:00:00+00:00')
        );

        self::assertSame(0, $result);
    }

    /**
     * Fails loudly rather than let the database silently shorten a name --
     * the exact regression InstallEventCounterRepository::MAX_EVENT_NAME_LENGTH's
     * docblock records: a 32-character column silently truncated a
     * 40-character event name, and the writer and reader ended up using two
     * different keys.
     */
    public function testIncrementRejectsAnEventNameLongerThanTheColumn(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('insertOnDuplicate');

        $resourceConnection = $this->resourceConnectionReturning($connection);
        $repository = new InstallEventCounterRepository($resourceConnection);

        $this->expectException(\LengthException::class);

        $tooLong = str_repeat('x', InstallEventCounterRepository::MAX_EVENT_NAME_LENGTH + 1);
        $repository->increment($tooLong, new \DateTimeImmutable());
    }

    private function isAccumulatingCountExpression(array $fields): bool
    {
        if (!isset($fields['count']) || !$fields['count'] instanceof \Zend_Db_Expr) {
            return false;
        }

        return (string) $fields['count'] === 'count + VALUES(count)';
    }

    private function resourceConnectionReturning(AdapterInterface $connection): ResourceConnection
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturn('watchtower_install_event_counter');

        return $resourceConnection;
    }

    private function repositoryReturning(string|false $fetchOneResult): InstallEventCounterRepository
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

        return new InstallEventCounterRepository($resourceConnection);
    }
}
