<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\QueueHealth;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\MessageQueue\ConnectionTypeResolver;
use Magento\Framework\MessageQueue\Consumer\Config\ConsumerConfigItemInterface;
use Magento\Framework\MessageQueue\Consumer\ConfigInterface as ConsumerConfigInterface;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\QueueHealth\AmqpQueueProbe;
use Watchtower\Connector\Model\QueueHealth\QueueStateObserver;

/**
 * The per-consumer backend resolution and watchlist filtering that
 * connector-metrics-spec.md 2.12 makes binding, since both are easy to get
 * wrong in ways that either miss real failures or invent them.
 */
class QueueStateObserverTest extends TestCase
{
    private const NOW_STRING = '2026-08-13T15:00:00+00:00';

    /**
     * The trap the spec calls out explicitly. A store whose default connection
     * is amqp still runs any consumer whose own module declares
     * connection="db" -- stock Magento ships at least one -- so resolving the
     * backend once for the whole install and applying it to every consumer
     * reads the wrong tables for that one.
     */
    public function testEachConsumerResolvesItsOwnBackend(): void
    {
        $probed = [];
        $probe = $this->createStub(AmqpQueueProbe::class);
        $probe->method('isUndrained')->willReturnCallback(
            function (string $connection, string $queue) use (&$probed) {
                $probed[] = $queue;

                return false;
            }
        );

        $this->observe(
            consumers: [
                ['async.operations.all', 'amqp', 'async.operations.all'],
                ['saveConfigProcessor', 'db', 'saveConfig'],
            ],
            connectionTypes: ['amqp' => 'amqp', 'db' => 'db'],
            probe: $probe,
            oldestPending: false
        );

        self::assertSame(
            ['async.operations.all'],
            $probed,
            'Only the amqp-backed consumer may be probed over AMQP; the db-backed one is a table read.'
        );
    }

    /**
     * Magento's own ConsumersRunner::canBeRun() skips a consumer whose
     * connection does not resolve, logging it as "not configured". Magento is
     * not running it, so nothing is expected to drain its queue and calling
     * that a fault would alert on a perfectly healthy store.
     */
    public function testAConsumerWhoseConnectionDoesNotResolveIsSkipped(): void
    {
        $probe = $this->createMock(AmqpQueueProbe::class);
        $probe->expects(self::never())->method('isUndrained');

        $observation = $this->observe(
            consumers: [['async.operations.all', 'ghost', 'async.operations.all']],
            connectionTypes: [],
            probe: $probe,
            oldestPending: false
        );

        self::assertNull($observation->undrainedSince);
        self::assertFalse($observation->undrainedWithoutOnset);
        self::assertSame([], $observation->affectedQueues);
    }

    /**
     * The watchlist is platform-fixed and covers merchant-visible consumers
     * only. A housekeeping consumer backing up must not contribute, because
     * the wire payload cannot name the queue and an alert the merchant cannot
     * act on is worse than none.
     */
    public function testAConsumerOutsideTheWatchlistIsIgnored(): void
    {
        $probe = $this->createMock(AmqpQueueProbe::class);
        $probe->expects(self::never())->method('isUndrained');

        $observation = $this->observe(
            consumers: [['inventory.reservations.cleanup', 'amqp', 'inventory.reservations.cleanup']],
            connectionTypes: ['amqp' => 'amqp'],
            probe: $probe,
            oldestPending: false
        );

        self::assertFalse($observation->undrainedWithoutOnset);
    }

    /**
     * Pending means NEW *and* RETRY_REQUIRED. Magento's own getMessagesCount()
     * treats both as consumable, so reading only status 2 would report a queue
     * in a retry storm as empty and silently miss the stall.
     */
    public function testPendingMessagesAreMatchedOnBothNewAndRetryRequired(): void
    {
        $seenWhere = [];
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            function (string $condition, mixed $value = null) use ($select, &$seenWhere) {
                $seenWhere[] = [$condition, $value];

                return $select;
            }
        );

        $this->observe(
            consumers: [['saveConfigProcessor', 'db', 'saveConfig']],
            connectionTypes: ['db' => 'db'],
            probe: $this->createStub(AmqpQueueProbe::class),
            oldestPending: false,
            select: $select
        );

        self::assertSame(['q.name = ?', 'saveConfig'], $seenWhere[0]);
        self::assertSame(
            [2, 5],
            $seenWhere[1][1],
            'Both NEW and RETRY_REQUIRED count as still waiting, never NEW alone.'
        );
    }

    /**
     * A queue that is draining keeps its oldest pending message young however
     * deep it gets, so a bulk import in progress reports no onset at all. This
     * is the discriminator a depth threshold would lack, and the reason this
     * signal is not the gauge-shaped "queue depth" the taxonomy once pencilled
     * in.
     */
    public function testADrainingQueueYieldsNoOnsetHoweverDeep(): void
    {
        $observation = $this->observe(
            consumers: [['saveConfigProcessor', 'db', 'saveConfig']],
            connectionTypes: ['db' => 'db'],
            probe: $this->createStub(AmqpQueueProbe::class),
            oldestPending: false
        );

        self::assertNull($observation->undrainedSince);
    }

    /**
     * ...and a queue holding a message nobody has taken reports when that
     * message arrived, which is the duration the evaluator judges.
     */
    public function testAStalledMysqlQueueReportsItsOldestPendingMessagesArrival(): void
    {
        $observation = $this->observe(
            consumers: [['saveConfigProcessor', 'db', 'saveConfig']],
            connectionTypes: ['db' => 'db'],
            probe: $this->createStub(AmqpQueueProbe::class),
            oldestPending: '2026-08-13 09:00:00'
        );

        self::assertEquals(
            new \DateTimeImmutable('2026-08-13T09:00:00+00:00'),
            $observation->undrainedSince
        );
        self::assertSame(['saveConfig'], $observation->affectedQueues);
    }

    /**
     * Runs one poll against stubbed Magento collaborators.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $consumers name, connection, queue
     * @param array<string, string> $connectionTypes connection name => resolved type; absent throws
     * @param AmqpQueueProbe $probe
     * @param string|false $oldestPending what MIN(updated_at) returns
     * @param Select|null $select
     * @return \Watchtower\Connector\Model\QueueHealth\Observation
     */
    private function observe(
        array $consumers,
        array $connectionTypes,
        AmqpQueueProbe $probe,
        string|false $oldestPending,
        ?Select $select = null
    ) {
        $configItems = [];

        foreach ($consumers as [$name, $connection, $queue]) {
            $item = $this->createStub(ConsumerConfigItemInterface::class);
            $item->method('getName')->willReturn($name);
            $item->method('getConnection')->willReturn($connection);
            $item->method('getQueue')->willReturn($queue);
            $configItems[] = $item;
        }

        $consumerConfig = $this->createStub(ConsumerConfigInterface::class);
        $consumerConfig->method('getConsumers')->willReturn($configItems);

        $resolver = $this->createStub(ConnectionTypeResolver::class);
        $resolver->method('getConnectionType')->willReturnCallback(
            function (string $name) use ($connectionTypes): string {
                if (!array_key_exists($name, $connectionTypes)) {
                    throw new \LogicException('Unknown connection name '.$name);
                }

                return $connectionTypes[$name];
            }
        );

        if ($select === null) {
            $select = $this->createStub(Select::class);
            $select->method('from')->willReturnSelf();
            $select->method('join')->willReturnSelf();
            $select->method('where')->willReturnSelf();
        }

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('fetchOne')->willReturn($oldestPending);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        return (new QueueStateObserver($consumerConfig, $resolver, $resourceConnection, $probe))
            ->observe(new \DateTimeImmutable(self::NOW_STRING));
    }
}
