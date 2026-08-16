<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Environment;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Environment\ConnectorVersionStateRepository;

/**
 * Mirrors OrganizationStateRepositoryTest's coverage shape for the
 * singleton-row pattern these repositories share.
 */
class ConnectorVersionStateRepositoryTest extends TestCase
{
    /**
     * An install that has never completed a check must default to NOT
     * self-disabled: the absence of a verdict is not a verdict, and a
     * default of true would brick every fresh install before its first
     * reporting cycle.
     */
    public function testGetDefaultsToNotBelowMinimumWhenNoRowExistsYet(): void
    {
        $state = $this->repositoryReturning(fetchRowResult: false)->get();

        self::assertNull($state->installedVersion);
        self::assertNull($state->minimumVersion);
        self::assertNull($state->latestVersion);
        self::assertFalse($state->belowMinimum);
        self::assertFalse($state->updateAvailable);
        self::assertNull($state->checkedAt);
    }

    public function testGetMapsAFullyPopulatedRowToItsTypedFields(): void
    {
        $state = $this->repositoryReturning(fetchRowResult: [
            'id' => 1,
            'installed_version' => '1.0.1',
            'minimum_version' => '1.2.0',
            'latest_version' => '1.3.0',
            'below_minimum' => '1',
            'update_available' => '1',
            'checked_at' => '2026-08-14 10:00:00',
        ])->get();

        self::assertSame('1.0.1', $state->installedVersion);
        self::assertSame('1.2.0', $state->minimumVersion);
        self::assertSame('1.3.0', $state->latestVersion);
        self::assertTrue($state->belowMinimum);
        self::assertTrue($state->updateAvailable);
        self::assertSame('2026-08-14T10:00:00+00:00', $state->checkedAt?->format(\DateTimeInterface::ATOM));
    }

    /**
     * checked_at is stored as a naive string, so it is only unambiguous if
     * it is read back in the same zone save() wrote it in -- reading it as
     * server-local would shift every timestamp the status command prints.
     */
    public function testAStoredCheckedAtIsReadBackAsUtc(): void
    {
        $state = $this->repositoryReturning(fetchRowResult: [
            'id' => 1,
            'installed_version' => '1.0.1',
            'minimum_version' => null,
            'latest_version' => null,
            'below_minimum' => '0',
            'update_available' => '0',
            'checked_at' => '2026-08-14 23:30:00',
        ])->get();

        self::assertSame('UTC', $state->checkedAt?->getTimezone()->getName());
        self::assertSame('2026-08-14T23:30:00+00:00', $state->checkedAt?->format(\DateTimeInterface::ATOM));
    }

    public function testSaveUpsertsEveryFieldOfTheSingletonRow(): void
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

        $repository = new ConnectorVersionStateRepository($this->resourceConnectionFor($connection));
        $repository->save('1.0.1', '1.2.0', '1.3.0', true, true, new \DateTimeImmutable('2026-08-14T10:00:00+00:00'));

        self::assertSame('watchtower_connector_version_state', $savedTable);
        self::assertSame(1, $savedRow['id'], 'Always the same row -- this table holds one state, not a history.');
        self::assertSame('1.0.1', $savedRow['installed_version']);
        self::assertSame('1.2.0', $savedRow['minimum_version']);
        self::assertSame('1.3.0', $savedRow['latest_version']);
        self::assertSame(1, $savedRow['below_minimum']);
        self::assertSame(1, $savedRow['update_available']);
        self::assertSame('2026-08-14 10:00:00', $savedRow['checked_at']);
        self::assertSame(array_keys($savedRow), $savedColumns, 'A second save must overwrite every column.');
    }

    /**
     * Recovering from self-disabled is just a later save writing false, so
     * the update column list has to include below_minimum -- an
     * insert-only or partial-update would leave an upgraded install
     * permanently disabled.
     */
    public function testASecondSaveOverwritesAPreviouslyBelowMinimumVerdict(): void
    {
        $savedRows = [];

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $data, array $columns) use (&$savedRows) {
                $savedRows[] = [$data, $columns];

                return 1;
            }
        );

        $repository = new ConnectorVersionStateRepository($this->resourceConnectionFor($connection));
        $repository->save('1.0.1', '1.2.0', '1.3.0', true, true, new \DateTimeImmutable('2026-08-14T10:00:00+00:00'));
        $repository->save('1.3.0', '1.2.0', '1.3.0', false, false, new \DateTimeImmutable('2026-08-15T10:00:00+00:00'));

        self::assertCount(2, $savedRows);
        self::assertSame($savedRows[0][0]['id'], $savedRows[1][0]['id']);
        self::assertSame(0, $savedRows[1][0]['below_minimum']);
        self::assertSame(0, $savedRows[1][0]['update_available']);
        self::assertContains('below_minimum', $savedRows[1][1]);
        self::assertSame('2026-08-15 10:00:00', $savedRows[1][0]['checked_at']);
    }

    /**
     * checked_at is normalised to UTC on the way in, so a Magento install
     * whose PHP timezone is anything else doesn't write a timestamp that
     * get() would then read back as a different instant.
     */
    public function testSaveNormalisesCheckedAtToUtc(): void
    {
        $savedRow = null;

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $data) use (&$savedRow) {
                $savedRow = $data;

                return 1;
            }
        );

        $repository = new ConnectorVersionStateRepository($this->resourceConnectionFor($connection));
        $repository->save(
            '1.3.0',
            '1.2.0',
            '1.3.0',
            false,
            false,
            new \DateTimeImmutable('2026-08-14T12:00:00', new \DateTimeZone('Europe/Warsaw'))
        );

        self::assertSame('2026-08-14 10:00:00', $savedRow['checked_at']);
    }

    public function testSavePersistsUnknownVersionsAsNullColumns(): void
    {
        $savedRow = null;

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $data) use (&$savedRow) {
                $savedRow = $data;

                return 1;
            }
        );

        $repository = new ConnectorVersionStateRepository($this->resourceConnectionFor($connection));
        $repository->save(null, null, null, false, false, new \DateTimeImmutable('2026-08-14T10:00:00+00:00'));

        self::assertNull($savedRow['installed_version']);
        self::assertNull($savedRow['minimum_version']);
        self::assertNull($savedRow['latest_version']);
        self::assertSame(0, $savedRow['below_minimum']);
        self::assertSame(0, $savedRow['update_available']);
    }

    private function repositoryReturning(array|false $fetchRowResult): ConnectorVersionStateRepository
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn($fetchRowResult);

        return new ConnectorVersionStateRepository($this->resourceConnectionFor($connection));
    }

    private function resourceConnectionFor(AdapterInterface $connection): ResourceConnection
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        return $resourceConnection;
    }
}
