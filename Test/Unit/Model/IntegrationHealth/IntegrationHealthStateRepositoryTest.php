<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\IntegrationHealth;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\IntegrationHealth\IntegrationHealthStateRepository;

/**
 * Covers saveObservedEvidence(), the narrow write the 5-minute evidence
 * snapshot uses. Everything else about this table is written by
 * IntegrationHealth\Evaluator and covered by its own test.
 */
class IntegrationHealthStateRepositoryTest extends TestCase
{
    public function testSaveObservedEvidenceWritesOnlyTheTwoEvidenceColumnsForThatStoreView(): void
    {
        $updatedTable = null;
        $updatedData = null;
        $updatedWhere = null;

        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('update')->willReturnCallback(
            function (string $table, array $data, $where) use (&$updatedTable, &$updatedData, &$updatedWhere) {
                $updatedTable = $table;
                $updatedData = $data;
                $updatedWhere = $where;

                return 1;
            }
        );

        $repository = new IntegrationHealthStateRepository($this->resourceConnectionFor($connection));
        $repository->saveObservedEvidence(
            7,
            new \DateTimeImmutable('2026-08-13T14:30:00+00:00'),
            new \DateTimeImmutable('2026-08-13T12:00:00+00:00')
        );

        self::assertSame('watchtower_integration_health_state', $updatedTable);
        self::assertSame(
            ['last_success_at', 'last_failure_at'],
            array_keys($updatedData),
            'The snapshot must never touch a status, sequence number, or source fingerprint.'
        );
        self::assertSame('2026-08-13 14:30:00', $updatedData['last_success_at']);
        self::assertSame('2026-08-13 12:00:00', $updatedData['last_failure_at']);
        self::assertSame(['store_view_id = ?' => 7], $updatedWhere);
    }

    public function testSaveObservedEvidenceWritesNullsThrough(): void
    {
        $updatedData = null;

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('update')->willReturnCallback(
            function (string $table, array $data) use (&$updatedData) {
                $updatedData = $data;

                return 1;
            }
        );

        $repository = new IntegrationHealthStateRepository($this->resourceConnectionFor($connection));
        $repository->saveObservedEvidence(7, null, null);

        self::assertNull($updatedData['last_success_at']);
        self::assertNull($updatedData['last_failure_at']);
    }

    /**
     * @param AdapterInterface $connection
     * @return ResourceConnection
     */
    private function resourceConnectionFor(AdapterInterface $connection): ResourceConnection
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        return $resourceConnection;
    }
}
