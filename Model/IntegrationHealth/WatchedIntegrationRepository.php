<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

use Magento\Framework\App\ResourceConnection;

/**
 * The install-level set of integrations a merchant chose to watch.
 *
 * Install-level because a cron job code has no store dimension; the retired
 * per-store-view model made twelve store views mean twelve identical rows.
 * A `module` entry watches everything that module ships, a `job` entry watches
 * one code for merchants who used the advanced disclosure.
 */
class WatchedIntegrationRepository
{
    public const WATCH_TYPE_MODULE = 'module';
    public const WATCH_TYPE_JOB = 'job';

    private const TABLE = 'watchtower_watched_integration';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Module names whose every job is watched.
     *
     * @return string[]
     */
    public function watchedModules(): array
    {
        return $this->identifiersOfType(self::WATCH_TYPE_MODULE);
    }

    /**
     * Individually watched cron job codes.
     *
     * @return string[]
     */
    public function watchedJobCodes(): array
    {
        return $this->identifiersOfType(self::WATCH_TYPE_JOB);
    }

    /**
     * Whether anything at all is watched.
     *
     * The signal is optional: choosing nothing means not evaluated, never
     * reported healthy.
     *
     * @return bool
     */
    public function hasAnyWatched(): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        return (int) $connection->fetchOne(
            $connection->select()->from($table, ['COUNT(*)'])
        ) > 0;
    }

    /**
     * Replaces the entire watched set in one transaction.
     *
     * Replace rather than merge: the page posts the whole set, and a partial
     * write would leave an unticked integration silently still watched.
     *
     * @param string[] $moduleNames
     * @param string[] $jobCodes
     * @return void
     */
    public function save(array $moduleNames, array $jobCodes): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $rows = [];

        foreach (array_unique($moduleNames) as $moduleName) {
            $rows[] = ['watch_type' => self::WATCH_TYPE_MODULE, 'identifier' => (string) $moduleName];
        }

        foreach (array_unique($jobCodes) as $jobCode) {
            $rows[] = ['watch_type' => self::WATCH_TYPE_JOB, 'identifier' => (string) $jobCode];
        }

        $connection->beginTransaction();

        try {
            $connection->delete($table);

            if ($rows !== []) {
                $connection->insertMultiple($table, $rows);
            }

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    /**
     * The identifiers stored under one watch type.
     *
     * @param string $watchType
     * @return string[]
     */
    private function identifiersOfType(string $watchType): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $identifiers = $connection->fetchCol(
            $connection->select()
                ->from($table, ['identifier'])
                ->where('watch_type = ?', $watchType)
                ->order('identifier ASC')
        );

        return array_map('strval', $identifiers);
    }
}
