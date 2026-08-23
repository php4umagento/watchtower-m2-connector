<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\HealthState;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\HealthState\HealthState;
use Watchtower\Connector\Model\HealthState\HealthStateRepository;

/**
 * cron_schedule's own aggressive purging is the whole reason this table
 * exists (see the class docblock), so a bug here silently loses the
 * (success, failure, debounce, sequence) state Evaluator depends on;
 * these were entirely untested before this pass, including the one case
 * that would previously have crashed outright: SignalStatus::tryFrom()
 * against the NULL columns a brand-new event_type row always starts with.
 */
class HealthStateRepositoryTest extends TestCase
{
    public function testGetReturnsAFreshDefaultStateWhenNoRowExistsYet(): void
    {
        $repository = $this->repositoryReturning(fetchRowResult: false);

        $state = $repository->get('cron_health');

        self::assertSame('cron_health', $state->eventType);
        self::assertNull($state->lastSuccessAt);
        self::assertNull($state->lastFailureAt);
        self::assertNull($state->pendingStatus);
        self::assertNull($state->confirmedStatus);
        self::assertSame(1, $state->sequenceNumber);
    }

    /**
     * The exact scenario the review flagged: pending_status/confirmed_status
     * are nullable columns, and a row can have both NULL right after the
     * very first evaluation (the shared debounce's null-confirmed seed saves
     * confirmedStatus as INSUFFICIENT_DATA, not null, but pending_status
     * stays null on every branch that doesn't set it). tryFrom() against a
     * NULL column must map to null, not throw or silently coerce to a
     * bogus enum case.
     */
    public function testGetMapsNullPendingAndConfirmedStatusColumnsToNullRatherThanThrowing(): void
    {
        $repository = $this->repositoryReturning(fetchRowResult: [
            'last_success_at' => null,
            'last_failure_at' => null,
            'pending_status' => null,
            'confirmed_status' => null,
            'sequence_number' => '4',
        ]);

        $state = $repository->get('cron_health');

        self::assertNull($state->pendingStatus);
        self::assertNull($state->confirmedStatus);
        self::assertNull($state->lastSuccessAt);
        self::assertNull($state->lastFailureAt);
        self::assertSame(4, $state->sequenceNumber);
    }

    public function testGetMapsAFullyPopulatedRowToItsTypedFields(): void
    {
        $repository = $this->repositoryReturning(fetchRowResult: [
            'last_success_at' => '2026-08-13 14:30:00',
            'last_failure_at' => null,
            'pending_status' => 'MILD_DROP',
            'confirmed_status' => 'NORMAL',
            'sequence_number' => '7',
        ]);

        $state = $repository->get('cron_health');

        self::assertEquals(new \DateTimeImmutable('2026-08-13 14:30:00'), $state->lastSuccessAt);
        self::assertNull($state->lastFailureAt);
        self::assertSame(SignalStatus::MildDrop, $state->pendingStatus);
        self::assertSame(SignalStatus::Normal, $state->confirmedStatus);
        self::assertSame(7, $state->sequenceNumber);
    }

    public function testSavePersistsEveryFieldIncludingNullsThroughInsertOnDuplicate(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('insertOnDuplicate')
            ->with(
                'watchtower_health_state',
                [
                    'event_type' => 'cron_health',
                    'last_success_at' => '2026-08-13 14:30:00',
                    'last_failure_at' => null,
                    'pending_status' => 'MILD_DROP',
                    'confirmed_status' => null,
                    'last_reported_reason' => 'transition',
                    'sequence_number' => 5,
                ],
                [
                    'last_success_at',
                    'last_failure_at',
                    'pending_status',
                    'confirmed_status',
                    'last_reported_reason',
                    'sequence_number',
                ]
            );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturn('watchtower_health_state');

        $state = new HealthState(
            eventType: 'cron_health',
            lastSuccessAt: new \DateTimeImmutable('2026-08-13 14:30:00'),
            lastFailureAt: null,
            pendingStatus: SignalStatus::MildDrop,
            confirmedStatus: null,
            sequenceNumber: 5,
            lastReportedReason: ReportReason::Transition,
        );

        (new HealthStateRepository($resourceConnection))->save($state);
    }

    private function repositoryReturning(array|false $fetchRowResult): HealthStateRepository
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn($fetchRowResult);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturn('watchtower_health_state');

        return new HealthStateRepository($resourceConnection);
    }
}
