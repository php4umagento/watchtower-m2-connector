<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\CronHealth;

use Magento\Cron\Model\Schedule;
use Magento\Framework\App\ResourceConnection;

/**
 * Reads Magento's cron_schedule table for the freshest success/failure
 * evidence: the raw material Evaluator turns into cron_health's
 * OK/FAILED/DOWN triple.
 *
 * Deliberately does NOT infer "is the scheduler process alive" from row
 * creation activity. Magento's schedule generator
 * (Magento\Cron\Observer\ProcessCronQueueObserver::generateSchedules()) only
 * re-runs once schedule_generate_every has elapsed since its last run (15
 * minutes for the "default" cron group), so new cron_schedule rows arrive in
 * ~15-minute bursts rather than continuously, and "no row created in the
 * last N minutes" is not a reliable proxy for "the scheduler has stopped".
 * Evaluator instead compares timestamps against its own expected-interval
 * window; the lookback here is a generous query-cost bound only.
 */
class CronScheduleObserver
{
    /**
     * How far back to look for evidence at all. Generous relative to
     * Magento's own history_success_lifetime (~60 min default), so this
     * essentially always sees whatever Magento still has, without scanning
     * the whole table.
     */
    private const LOOKBACK_MINUTES = 120;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Fetches the freshest success/failure evidence from cron_schedule within the lookback window.
     *
     * @param \DateTimeImmutable $now
     * @return Observation
     */
    public function observe(\DateTimeImmutable $now): Observation
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('cron_schedule');
        $lookbackValue = $now->modify('-'.self::LOOKBACK_MINUTES.' minutes')->format('Y-m-d H:i:s');

        $latestSuccessAt = $connection->fetchOne(
            $connection->select()
                ->from($table, ['finished_at'])
                ->where('status = ?', Schedule::STATUS_SUCCESS)
                ->where('finished_at >= ?', $lookbackValue)
                ->order('finished_at DESC')
                ->limit(1)
        );

        // COALESCE matters: a 'missed' row (the window to run it passed
        // without it ever starting) never gets a finished_at, while an
        // 'error' row (it ran and threw) does. scheduled_at is the fallback
        // for 'missed' -- "when it should have run" is the meaningful
        // failure instant for a job that never did -- and created_at is only
        // a last resort, since it is schedule-generation time, up to
        // schedule_ahead_for minutes before the job was even due.
        $latestFailureAt = $connection->fetchOne(
            $connection->select()
                ->from($table, ['failure_at' => new \Zend_Db_Expr('COALESCE(finished_at, scheduled_at, created_at)')])
                ->where('status IN (?)', [Schedule::STATUS_ERROR, Schedule::STATUS_MISSED])
                ->where('COALESCE(finished_at, scheduled_at, created_at) >= ?', $lookbackValue)
                ->order('failure_at DESC')
                ->limit(1)
        );

        return new Observation(
            latestSuccessAt: $latestSuccessAt !== false ? new \DateTimeImmutable($latestSuccessAt) : null,
            latestFailureAt: $latestFailureAt !== false ? new \DateTimeImmutable($latestFailureAt) : null,
        );
    }
}
