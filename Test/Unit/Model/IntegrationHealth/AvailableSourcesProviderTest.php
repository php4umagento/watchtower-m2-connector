<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\IntegrationHealth;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\IntegrationHealth\AvailableSourcesProvider;

/**
 * The two enumerable source kinds behind the admin picker. The
 * property worth locking here is the watchtower_* exclusion: the connector
 * must never offer its own scheduled jobs as a monitorable "integration",
 * since that would make the reporting job the source of its own signal.
 * The exclusion lives in SQL, so the honest unit-level assertion is on the
 * WHERE clause handed to the adapter rather than on a faked MySQL doing
 * the filtering itself.
 */
class AvailableSourcesProviderTest extends TestCase
{
    public function testCronJobCodesSelectsDistinctOrderedCodesExcludingThisModulesOwnJobs(): void
    {
        $seenWhere = [];

        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('distinct')->willReturnSelf();
        $select->expects(self::once())->method('from')->with('cron_schedule', ['job_code'])->willReturnSelf();
        $select->expects(self::once())->method('order')->with('job_code ASC')->willReturnSelf();
        $select->expects(self::once())->method('where')->willReturnCallback(
            function (string $condition, mixed $value = null) use ($select, &$seenWhere) {
                $seenWhere[] = [$condition, $value];

                return $select;
            }
        );

        $provider = new AvailableSourcesProvider(
            $this->resourceConnectionFor($select, ['indexer_reindex_all_invalid', 'sales_grid_order_async_insert'])
        );

        $codes = $provider->cronJobCodes();

        // Escaped underscore: a bare _ is a single-character LIKE wildcard.
        self::assertSame([['job_code NOT LIKE ?', 'watchtower\_%']], $seenWhere);
        self::assertSame(['indexer_reindex_all_invalid', 'sales_grid_order_async_insert'], $codes);
    }

    public function testQueueTopicsSelectsDistinctOrderedTopicNamesUnfiltered(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('distinct')->willReturnSelf();
        $select->expects(self::once())->method('from')->with('magento_operation', ['topic_name'])->willReturnSelf();
        $select->expects(self::once())->method('order')->with('topic_name ASC')->willReturnSelf();
        $select->expects(self::never())->method('where');

        $provider = new AvailableSourcesProvider(
            $this->resourceConnectionFor($select, ['async.operations.all', 'product_action_attribute.update'])
        );

        self::assertSame(['async.operations.all', 'product_action_attribute.update'], $provider->queueTopics());
    }

    public function testBothListsAreEmptyWhenTheInstallHasNoRowsYet(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('distinct')->willReturnSelf();
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        $provider = new AvailableSourcesProvider($this->resourceConnectionFor($select, []));

        self::assertSame([], $provider->cronJobCodes());
        self::assertSame([], $provider->queueTopics());
    }

    /**
     * @param Select $select
     * @param string[] $fetchColResult
     * @return ResourceConnection
     */
    private function resourceConnectionFor(Select $select, array $fetchColResult): ResourceConnection
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturn($fetchColResult);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        return $resourceConnection;
    }
}
