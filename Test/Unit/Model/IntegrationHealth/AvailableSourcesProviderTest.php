<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\IntegrationHealth;

use Magento\Cron\Model\ConfigInterface as CronConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Watchtower\Connector\Model\IntegrationHealth\AvailableSourcesProvider;

/**
 * The two enumerable source kinds behind the admin picker.
 *
 * Two properties are worth locking here. First, the watchtower_* exclusion:
 * the connector must never offer its own scheduled jobs as a monitorable
 * "integration", since that would make the reporting job the source of its
 * own signal -- and it now has to hold on both halves of the union, not just
 * the SQL half. Second, that crontab.xml config is genuinely unioned with
 * cron_schedule rather than either one silently winning, which is the actual
 * regression risk: reading only cron_schedule hides every job outside its
 * ~80-minute-per-day visibility window.
 */
class AvailableSourcesProviderTest extends TestCase
{
    public function testCronJobCodesAreGroupedByCronGroupAndNaturallySorted(): void
    {
        $provider = new AvailableSourcesProvider(
            $this->resourceConnectionFor($this->permissiveSelect(), []),
            $this->cronConfigFor([
                'default' => ['sitemap_generate' => [], 'catalogrule_apply_all' => []],
                'index' => ['indexer_reindex_all_invalid' => []],
            ])
        );

        self::assertSame(
            [
                'default' => ['catalogrule_apply_all', 'sitemap_generate'],
                'index' => ['indexer_reindex_all_invalid'],
            ],
            $provider->cronJobCodesByGroup()
        );
    }

    public function testDeclaredJobsAreOfferedEvenWhenAbsentFromCronSchedule(): void
    {
        // The whole point of the union: a daily job sits outside
        // cron_schedule for ~94% of the day, but is always in crontab.xml.
        $provider = new AvailableSourcesProvider(
            $this->resourceConnectionFor($this->permissiveSelect(), []),
            $this->cronConfigFor(['default' => ['braintree_rtau' => []]])
        );

        self::assertSame(['default' => ['braintree_rtau']], $provider->cronJobCodesByGroup());
    }

    public function testScheduledJobsDeclaredInNoCrontabAreOfferedUnderTheOtherGroup(): void
    {
        $provider = new AvailableSourcesProvider(
            $this->resourceConnectionFor($this->permissiveSelect(), ['acme_erp_backfill', 'sitemap_generate']),
            $this->cronConfigFor(['default' => ['sitemap_generate' => []]])
        );

        // sitemap_generate is declared, so it stays in its real group rather
        // than being duplicated into "other".
        self::assertSame(
            [
                'default' => ['sitemap_generate'],
                'other' => ['acme_erp_backfill'],
            ],
            $provider->cronJobCodesByGroup()
        );
    }

    public function testThisModulesOwnJobsAreExcludedFromBothHalvesOfTheUnion(): void
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
            $this->resourceConnectionFor($select, []),
            $this->cronConfigFor([
                'default' => ['sitemap_generate' => []],
                'watchtower' => ['watchtower_report' => [], 'watchtower_sync' => []],
            ])
        );

        $grouped = $provider->cronJobCodesByGroup();

        // Escaped underscore: a bare _ is a single-character LIKE wildcard.
        self::assertSame([['job_code NOT LIKE ?', 'watchtower\_%']], $seenWhere);
        // The watchtower group is dropped entirely rather than left empty.
        self::assertSame(['default' => ['sitemap_generate']], $grouped);
    }

    public function testQueueTopicsSelectsDistinctOrderedTopicNamesUnfiltered(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('distinct')->willReturnSelf();
        $select->expects(self::once())->method('from')->with('magento_operation', ['topic_name'])->willReturnSelf();
        $select->expects(self::once())->method('order')->with('topic_name ASC')->willReturnSelf();
        $select->expects(self::never())->method('where');

        $provider = new AvailableSourcesProvider(
            $this->resourceConnectionFor($select, ['async.operations.all', 'product_action_attribute.update']),
            $this->cronConfigFor([])
        );

        self::assertSame(['async.operations.all', 'product_action_attribute.update'], $provider->queueTopics());
    }

    public function testBothListsAreEmptyWhenTheInstallHasNoRowsYet(): void
    {
        $provider = new AvailableSourcesProvider(
            $this->resourceConnectionFor($this->permissiveSelect(), []),
            $this->cronConfigFor([])
        );

        self::assertSame([], $provider->cronJobCodesByGroup());
        self::assertSame([], $provider->queueTopics());
    }

    /**
     * @return Select
     */
    private function permissiveSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('distinct')->willReturnSelf();
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        return $select;
    }

    /**
     * @param array<string, array<string, array>> $jobs
     * @return CronConfigInterface
     */
    private function cronConfigFor(array $jobs): CronConfigInterface
    {
        $cronConfig = $this->createStub(CronConfigInterface::class);
        $cronConfig->method('getJobs')->willReturn($jobs);

        return $cronConfig;
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
