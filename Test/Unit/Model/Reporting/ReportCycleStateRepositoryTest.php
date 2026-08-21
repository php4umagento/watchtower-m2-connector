<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Reporting;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Reporting\ReportCycleStateRepository;

/**
 * Mirrors ConnectorVersionStateRepositoryTest's coverage shape for the
 * singleton-row pattern these repositories share.
 */
class ReportCycleStateRepositoryTest extends TestCase
{
    public function testGetDefaultsToNullWhenNoRowExistsYet(): void
    {
        $state = $this->repositoryReturning(fetchRowResult: false)->get();

        self::assertNull($state->lastRunAt);
    }

    public function testGetMapsAPopulatedRowToItsTypedField(): void
    {
        $state = $this->repositoryReturning(fetchRowResult: [
            'id' => 1,
            'last_run_at' => '2026-08-14 10:00:00',
        ])->get();

        self::assertSame('2026-08-14T10:00:00+00:00', $state->lastRunAt?->format(\DateTimeInterface::ATOM));
    }

    /**
     * last_run_at is stored as a naive string, so it is only unambiguous if
     * it is read back in the same zone save() wrote it in.
     */
    public function testAStoredLastRunAtIsReadBackAsUtc(): void
    {
        $state = $this->repositoryReturning(fetchRowResult: [
            'id' => 1,
            'last_run_at' => '2026-08-14 23:30:00',
        ])->get();

        self::assertSame('UTC', $state->lastRunAt?->getTimezone()->getName());
        self::assertSame('2026-08-14T23:30:00+00:00', $state->lastRunAt?->format(\DateTimeInterface::ATOM));
    }

    public function testSaveUpsertsTheSingletonRow(): void
    {
        $savedTable = null;
        $savedRow = null;
        $savedColumns = null;

        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $data, array $columns) use (&$savedTable, &$savedRow, &$savedColumns) {
                $savedTable = $table;
                $savedRow = $data;
                $savedColumns = $columns;

                return 1;
            }
        );

        $repository = new ReportCycleStateRepository($this->resourceConnectionFor($connection));
        $repository->save(new \DateTimeImmutable('2026-08-14T10:00:00+00:00'));

        self::assertSame('watchtower_report_cycle_state', $savedTable);
        self::assertSame(1, $savedRow['id'], 'Always the same row -- this table holds one state, not a history.');
        self::assertSame('2026-08-14 10:00:00', $savedRow['last_run_at']);
        self::assertSame(array_keys($savedRow), $savedColumns, 'A second save must overwrite every column.');
    }

    public function testASecondSaveOverwritesThePreviousRunTime(): void
    {
        $savedRows = [];

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $data) use (&$savedRows) {
                $savedRows[] = $data;

                return 1;
            }
        );

        $repository = new ReportCycleStateRepository($this->resourceConnectionFor($connection));
        $repository->save(new \DateTimeImmutable('2026-08-14T10:00:00+00:00'));
        $repository->save(new \DateTimeImmutable('2026-08-14T11:00:00+00:00'));

        self::assertCount(2, $savedRows);
        self::assertSame($savedRows[0]['id'], $savedRows[1]['id']);
        self::assertSame('2026-08-14 11:00:00', $savedRows[1]['last_run_at']);
    }

    /**
     * last_run_at is normalised to UTC on the way in, so a Magento install
     * whose PHP timezone is anything else doesn't write a timestamp that
     * get() would then read back as a different instant.
     */
    public function testSaveNormalisesLastRunAtToUtc(): void
    {
        $savedRow = null;

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $data) use (&$savedRow) {
                $savedRow = $data;

                return 1;
            }
        );

        $repository = new ReportCycleStateRepository($this->resourceConnectionFor($connection));
        $repository->save(new \DateTimeImmutable('2026-08-14T12:00:00', new \DateTimeZone('Europe/Warsaw')));

        self::assertSame('2026-08-14 10:00:00', $savedRow['last_run_at']);
    }

    private function repositoryReturning(array|false $fetchRowResult): ReportCycleStateRepository
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn($fetchRowResult);

        return new ReportCycleStateRepository($this->resourceConnectionFor($connection));
    }

    private function resourceConnectionFor(AdapterInterface $connection): ResourceConnection
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        return $resourceConnection;
    }
}
