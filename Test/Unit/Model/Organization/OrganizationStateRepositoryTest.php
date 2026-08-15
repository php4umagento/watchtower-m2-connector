<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Organization;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Organization\OrganizationStateRepository;

class OrganizationStateRepositoryTest extends TestCase
{
    private const NOW_STRING = '2026-08-13T15:00:00+00:00';

    public function testIsPausedReturnsFalseWhenNoRowExistsYet(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->fluentSelectStub());
        $connection->method('fetchRow')->willReturn(false);

        $repository = new OrganizationStateRepository($this->resourceConnectionFor($connection));

        self::assertFalse($repository->isPaused($this->now()));
    }

    public function testIsPausedReflectsTheStoredValueWhenFresh(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->fluentSelectStub());
        $connection->method('fetchRow')->willReturn([
            'id' => 1,
            'organization_paused' => 1,
            'updated_at' => '2026-08-13 14:59:00',
        ]);

        $repository = new OrganizationStateRepository($this->resourceConnectionFor($connection));

        self::assertTrue($repository->isPaused($this->now()));
    }

    public function testIsPausedReturnsFalseWhenNotPausedRegardlessOfAge(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->fluentSelectStub());
        $connection->method('fetchRow')->willReturn([
            'id' => 1,
            'organization_paused' => 0,
            'updated_at' => '2020-01-01 00:00:00',
        ]);

        $repository = new OrganizationStateRepository($this->resourceConnectionFor($connection));

        self::assertFalse($repository->isPaused($this->now()));
    }

    /**
     * The connector has no scheduled ping job, so a cached "paused"
     * reading would otherwise never self-correct: neither ReportingService
     * nor StoreViewSyncService attempts a real request while they believe
     * they're paused. A reading older than STALE_AFTER_HOURS (6) is no
     * longer trusted, so the next cycle attempts a real request again and
     * self-corrects via MetricsSubmissionService's own 403/200 detection.
     */
    public function testAStalePausedReadingIsNoLongerTrusted(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->fluentSelectStub());
        $connection->method('fetchRow')->willReturn([
            'id' => 1,
            'organization_paused' => 1,
            // 6 hours and 1 minute before "now" -- just past the staleness horizon.
            'updated_at' => '2026-08-13 08:59:00',
        ]);

        $repository = new OrganizationStateRepository($this->resourceConnectionFor($connection));

        self::assertFalse($repository->isPaused($this->now()));
    }

    /**
     * A reading exactly at the staleness boundary is still trusted -- the
     * cutoff is exclusive on the stale side, not inclusive.
     */
    public function testAPausedReadingExactlyAtTheStalenessBoundaryIsStillTrusted(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->fluentSelectStub());
        $connection->method('fetchRow')->willReturn([
            'id' => 1,
            'organization_paused' => 1,
            // Exactly 6 hours before "now".
            'updated_at' => '2026-08-13 09:00:00',
        ]);

        $repository = new OrganizationStateRepository($this->resourceConnectionFor($connection));

        self::assertTrue($repository->isPaused($this->now()));
    }

    /**
     * updated_at is stamped explicitly by save(), not left to the column's
     * own ON UPDATE CURRENT_TIMESTAMP -- verified live against the real
     * database that MySQL does NOT bump an ON UPDATE CURRENT_TIMESTAMP
     * column when the newly assigned value equals the row's current
     * value, which is exactly the repeated "still paused" case the
     * staleness check exists to eventually escape from.
     */
    public function testSaveUpsertsTheSingletonRowWithAnExplicitUpdatedAt(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('insertOnDuplicate')->with(
            'watchtower_organization_state',
            ['id' => 1, 'organization_paused' => true, 'updated_at' => '2026-08-13 15:00:00'],
            ['organization_paused', 'updated_at']
        );

        $repository = new OrganizationStateRepository($this->resourceConnectionFor($connection));
        $repository->save(true, $this->now());
    }

    private function fluentSelectStub(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }

    private function resourceConnectionFor(AdapterInterface $connection): ResourceConnection
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        return $resourceConnection;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW_STRING);
    }
}
