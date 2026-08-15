<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\IntegrationHealth;

use Magento\Cron\Model\Schedule;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\IntegrationHealth\CronJobObserver;
use Watchtower\Connector\Model\IntegrationHealth\Observation;

/**
 * Uses RollupRepositoryTest.php's own established pattern for asserting
 * Select where() calls precisely (CronHealth\CronScheduleObserver, the
 * class this one is modeled on, has no dedicated unit test of its own to
 * follow instead).
 *
 * The success/failure timestamp assertions below are also a regression
 * test for CronJobObserver::toUtc()'s own reason for existing: this
 * module's dev/tests/unit/phpunit.xml.dist deliberately runs under
 * America/Los_Angeles, so an assertion against an explicit +00:00 offset
 * would fail here if the parsing ever regressed to a bare, timezone-
 * implicit `new \DateTimeImmutable($value)`.
 */
class CronJobObserverTest extends TestCase
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

    public function testFiltersByTheGivenJobCodeAndTheCorrectStatusValues(): void
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

        (new CronJobObserver($resourceConnection))->observe('my_erp_sync', $this->now());

        self::assertSame(['job_code = ?', 'my_erp_sync'], $seenWhere[0]);
        self::assertSame(['status = ?', Schedule::STATUS_SUCCESS], $seenWhere[1]);
        self::assertSame(['job_code = ?', 'my_erp_sync'], $seenWhere[3]);
        self::assertSame(
            [Schedule::STATUS_ERROR, Schedule::STATUS_MISSED],
            $seenWhere[4][1],
            'The failure query must match both error and missed statuses.'
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

        return (new CronJobObserver($resourceConnection))->observe('my_erp_sync', $this->now());
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW_STRING);
    }
}
