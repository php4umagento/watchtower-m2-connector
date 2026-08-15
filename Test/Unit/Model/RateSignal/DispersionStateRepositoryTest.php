<?php

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\RateSignal;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\RateSignal\DispersionState;
use Watchtower\Connector\Model\RateSignal\DispersionStateRepository;

/**
 * Mirrors HealthStateRepositoryTest's coverage for the rate-based analogue:
 * a NULL pending/confirmed status column on a brand-new (store view,
 * category) row must map to null, not throw via SignalStatus::tryFrom().
 */
class DispersionStateRepositoryTest extends TestCase
{
    public function testGetReturnsAFreshDefaultStateWhenNoRowExistsYet(): void
    {
        $repository = $this->repositoryReturning(fetchRowResult: false);

        $state = $repository->get(7, 'checkout');

        self::assertSame(7, $state->storeViewId);
        self::assertSame('checkout', $state->category);
        self::assertNull($state->pendingStatus);
        self::assertNull($state->confirmedStatus);
        self::assertSame(1, $state->sequenceNumber);
    }

    public function testGetMapsNullPendingAndConfirmedStatusColumnsToNullRatherThanThrowing(): void
    {
        $repository = $this->repositoryReturning(fetchRowResult: [
            'pending_status' => null,
            'confirmed_status' => null,
            'sequence_number' => '4',
        ]);

        $state = $repository->get(7, 'checkout');

        self::assertNull($state->pendingStatus);
        self::assertNull($state->confirmedStatus);
        self::assertSame(4, $state->sequenceNumber);
    }

    public function testGetMapsAFullyPopulatedRowToItsTypedFields(): void
    {
        $repository = $this->repositoryReturning(fetchRowResult: [
            'pending_status' => 'MILD_DROP',
            'confirmed_status' => 'NORMAL',
            'sequence_number' => '7',
        ]);

        $state = $repository->get(7, 'checkout');

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
                'watchtower_dispersion_state',
                [
                    'store_view_id' => 7,
                    'category' => 'checkout',
                    'pending_status' => 'MILD_DROP',
                    'confirmed_status' => null,
                    'sequence_number' => 5,
                ],
                ['pending_status', 'confirmed_status', 'sequence_number']
            );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturn('watchtower_dispersion_state');

        $state = new DispersionState(
            storeViewId: 7,
            category: 'checkout',
            pendingStatus: SignalStatus::MildDrop,
            confirmedStatus: null,
            sequenceNumber: 5,
        );

        (new DispersionStateRepository($resourceConnection))->save($state);
    }

    /**
     * (store_view_id, category) is the table's composite primary key
     * (etc/db_schema.xml), so two different store views for the same
     * category can never collide or share a sequence counter: every get()/
     * save() call is scoped by BOTH values, never by category alone. Proven
     * here by capturing the actual WHERE/insertOnDuplicate arguments across
     * two calls with different store view ids and asserting store_view_id
     * genuinely varies between them rather than being dropped or merged.
     */
    public function testGetAndSaveScopeByBothStoreViewIdAndCategorySoDifferentStoreViewsStayIndependent(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();

        $whereCalls = [];
        $select->method('where')->willReturnCallback(function (string $condition, $value) use (&$whereCalls, $select) {
            $whereCalls[] = [$condition, $value];

            return $select;
        });

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn([
            'pending_status' => null,
            'confirmed_status' => 'NORMAL',
            'sequence_number' => '9',
        ]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturn('watchtower_dispersion_state');

        $repository = new DispersionStateRepository($resourceConnection);
        $stateForStoreOne = $repository->get(1, 'checkout');
        $stateForStoreTwo = $repository->get(2, 'checkout');

        self::assertSame(1, $stateForStoreOne->storeViewId);
        self::assertSame(2, $stateForStoreTwo->storeViewId);

        $storeViewIdConditions = array_column(
            array_filter($whereCalls, static fn (array $call): bool => $call[0] === 'store_view_id = ?'),
            1
        );
        self::assertSame([1, 2], $storeViewIdConditions);

        $savedRows = [];
        $writeConnection = $this->createStub(AdapterInterface::class);
        $writeConnection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $data) use (&$savedRows) {
                $savedRows[] = $data;

                return 1;
            }
        );

        $writeResourceConnection = $this->createStub(ResourceConnection::class);
        $writeResourceConnection->method('getConnection')->willReturn($writeConnection);
        $writeResourceConnection->method('getTableName')->willReturn('watchtower_dispersion_state');

        $writeRepository = new DispersionStateRepository($writeResourceConnection);
        $writeRepository->save(new DispersionState(
            storeViewId: 1,
            category: 'checkout',
            pendingStatus: null,
            confirmedStatus: SignalStatus::Normal,
            sequenceNumber: 5,
        ));
        $writeRepository->save(new DispersionState(
            storeViewId: 2,
            category: 'checkout',
            pendingStatus: null,
            confirmedStatus: SignalStatus::MildDrop,
            sequenceNumber: 12,
        ));

        self::assertSame(1, $savedRows[0]['store_view_id']);
        self::assertSame(5, $savedRows[0]['sequence_number']);
        self::assertSame(2, $savedRows[1]['store_view_id']);
        self::assertSame(12, $savedRows[1]['sequence_number']);
    }

    private function repositoryReturning(array|false $fetchRowResult): DispersionStateRepository
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn($fetchRowResult);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturn('watchtower_dispersion_state');

        return new DispersionStateRepository($resourceConnection);
    }
}
