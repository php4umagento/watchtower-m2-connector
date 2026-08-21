<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\StoreView;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\StoreView\IgnoredDomainStateRepository;

/**
 * Mirrors ConnectorVersionStateRepositoryTest's coverage shape for the
 * singleton-row pattern these repositories share.
 */
class IgnoredDomainStateRepositoryTest extends TestCase
{
    /**
     * An install that has never synced must default to zero ignored, not
     * a stale-looking notice with no data behind it.
     */
    public function testGetDefaultsToZeroIgnoredWhenNoRowExistsYet(): void
    {
        $state = $this->repositoryReturning(fetchRowResult: false)->get();

        self::assertSame(0, $state->ignoredCount);
        self::assertNull($state->exampleCode);
        self::assertNull($state->occurredAt);
    }

    public function testGetMapsAFullyPopulatedRowToItsTypedFields(): void
    {
        $state = $this->repositoryReturning(fetchRowResult: [
            'id' => 1,
            'ignored_count' => '2',
            'example_code' => 'default',
            'occurred_at' => '2026-08-14 10:00:00',
        ])->get();

        self::assertSame(2, $state->ignoredCount);
        self::assertSame('default', $state->exampleCode);
        self::assertSame('2026-08-14T10:00:00+00:00', $state->occurredAt?->format(\DateTimeInterface::ATOM));
    }

    /**
     * occurred_at is stored as a naive string, so it is only unambiguous if
     * it is read back in the same zone save() wrote it in.
     */
    public function testAStoredOccurredAtIsReadBackAsUtc(): void
    {
        $state = $this->repositoryReturning(fetchRowResult: [
            'id' => 1,
            'ignored_count' => '1',
            'example_code' => 'default',
            'occurred_at' => '2026-08-14 23:30:00',
        ])->get();

        self::assertSame('UTC', $state->occurredAt?->getTimezone()->getName());
        self::assertSame('2026-08-14T23:30:00+00:00', $state->occurredAt?->format(\DateTimeInterface::ATOM));
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

        $repository = new IgnoredDomainStateRepository($this->resourceConnectionFor($connection));
        $repository->save(3, 'default', new \DateTimeImmutable('2026-08-14T10:00:00+00:00'));

        self::assertSame('watchtower_ignored_domain_state', $savedTable);
        self::assertSame(1, $savedRow['id'], 'Always the same row -- this table holds one state, not a history.');
        self::assertSame(3, $savedRow['ignored_count']);
        self::assertSame('default', $savedRow['example_code']);
        self::assertSame('2026-08-14 10:00:00', $savedRow['occurred_at']);
        self::assertSame(array_keys($savedRow), $savedColumns, 'A second save must overwrite every column.');
    }

    /**
     * A resolved/removed local domain must clear the previous notice on the
     * very next successful sync -- an insert-only or partial update would
     * leave a stale notice displayed forever.
     */
    public function testASecondSaveWithZeroOverwritesAPreviouslyNonZeroCount(): void
    {
        $savedRows = [];

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $data, array $columns) use (&$savedRows) {
                $savedRows[] = [$data, $columns];

                return 1;
            }
        );

        $repository = new IgnoredDomainStateRepository($this->resourceConnectionFor($connection));
        $repository->save(2, 'default', new \DateTimeImmutable('2026-08-14T10:00:00+00:00'));
        $repository->save(0, null, new \DateTimeImmutable('2026-08-15T10:00:00+00:00'));

        self::assertCount(2, $savedRows);
        self::assertSame($savedRows[0][0]['id'], $savedRows[1][0]['id']);
        self::assertSame(0, $savedRows[1][0]['ignored_count']);
        self::assertNull($savedRows[1][0]['example_code']);
        self::assertContains('ignored_count', $savedRows[1][1]);
        self::assertSame('2026-08-15 10:00:00', $savedRows[1][0]['occurred_at']);
    }

    /**
     * occurred_at is normalised to UTC on the way in, so a Magento install
     * whose PHP timezone is anything else doesn't write a timestamp that
     * get() would then read back as a different instant.
     */
    public function testSaveNormalisesOccurredAtToUtc(): void
    {
        $savedRow = null;

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $data) use (&$savedRow) {
                $savedRow = $data;

                return 1;
            }
        );

        $repository = new IgnoredDomainStateRepository($this->resourceConnectionFor($connection));
        $repository->save(
            1,
            'default',
            new \DateTimeImmutable('2026-08-14T12:00:00', new \DateTimeZone('Europe/Warsaw'))
        );

        self::assertSame('2026-08-14 10:00:00', $savedRow['occurred_at']);
    }

    private function repositoryReturning(array|false $fetchRowResult): IgnoredDomainStateRepository
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn($fetchRowResult);

        return new IgnoredDomainStateRepository($this->resourceConnectionFor($connection));
    }

    private function resourceConnectionFor(AdapterInterface $connection): ResourceConnection
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        return $resourceConnection;
    }
}
