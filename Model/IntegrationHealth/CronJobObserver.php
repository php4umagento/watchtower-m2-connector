<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

use Magento\Cron\Model\Schedule;
use Magento\Framework\App\ResourceConnection;

/**
 * Reads Magento's cron_schedule table for the freshest success/failure
 * evidence of a store-configured job code. Parameterized by whichever
 * job_code the store's source-picker configuration names, unlike
 * CronHealth\CronScheduleObserver, which deliberately applies no job_code
 * filter at all: cron_health is install-scoped and answers "is Magento cron
 * running at all", so any job's success is evidence for it.
 *
 * Query shape (lookback window, COALESCE fallback for a 'missed' row's
 * missing finished_at) mirrors CronScheduleObserver's -- see that class's
 * docblock for why each of those choices exists.
 */
class CronJobObserver
{
    private const LOOKBACK_MINUTES = 120;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Fetches the freshest success/failure evidence for one job code within the lookback window.
     *
     * @param string $jobCode
     * @param \DateTimeImmutable $now
     * @return Observation
     */
    public function observe(string $jobCode, \DateTimeImmutable $now): Observation
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('cron_schedule');
        $lookbackValue = $now->modify('-' . self::LOOKBACK_MINUTES . ' minutes')->format('Y-m-d H:i:s');

        $latestSuccessAt = $connection->fetchOne(
            $connection->select()
                ->from($table, ['finished_at'])
                ->where('job_code = ?', $jobCode)
                ->where('status = ?', Schedule::STATUS_SUCCESS)
                ->where('finished_at >= ?', $lookbackValue)
                ->order('finished_at DESC')
                ->limit(1)
        );

        $latestFailureAt = $connection->fetchOne(
            $connection->select()
                ->from($table, ['failure_at' => new \Zend_Db_Expr('COALESCE(finished_at, scheduled_at, created_at)')])
                ->where('job_code = ?', $jobCode)
                ->where('status IN (?)', [Schedule::STATUS_ERROR, Schedule::STATUS_MISSED])
                ->where('COALESCE(finished_at, scheduled_at, created_at) >= ?', $lookbackValue)
                ->order('failure_at DESC')
                ->limit(1)
        );

        return new Observation(
            latestSuccessAt: $latestSuccessAt !== false ? $this->toUtc($latestSuccessAt) : null,
            latestFailureAt: $latestFailureAt !== false ? $this->toUtc($latestFailureAt) : null,
        );
    }

    /**
     * Parses a stored datetime string as explicit UTC. The bare single-arg
     * form would instead use PHP's default timezone, which is not
     * necessarily UTC, and silently yield the wrong instant.
     *
     * @param string $value
     * @return \DateTimeImmutable
     */
    private function toUtc(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
