<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

use Magento\Cron\Model\ConfigInterface as CronConfigInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Enumerates the integration_health sources a merchant can pick from in the
 * admin picker: the cron job codes and message-queue topics this Magento
 * install offers.
 *
 * Only two of the three source types are enumerable: convention_event's
 * identifier is a free-typed integration label with no server-side list to
 * read, so the picker offers a text input for it instead.
 */
class AvailableSourcesProvider
{
    /**
     * Jobs found in cron_schedule but declared in no module's crontab.xml.
     */
    private const UNDECLARED_GROUP = 'other';

    /**
     * @param ResourceConnection $resourceConnection
     * @param CronConfigInterface $cronConfig
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly CronConfigInterface $cronConfig
    ) {
    }

    /**
     * Lists the selectable cron job codes, keyed by cron group.
     *
     * The union of two deliberately different sources, because neither alone
     * is a complete list of what a merchant could monitor:
     *
     * - crontab.xml config, via ConfigInterface::getJobs() -- every job any
     *   installed module declares. Complete the moment a module is installed,
     *   and the only source that knows a job's cron group.
     * - cron_schedule -- additionally catches jobs inserted straight into the
     *   queue programmatically, which appear in no crontab.xml at all.
     *
     * Reading only cron_schedule (as this did before) is the trap worth
     * spelling out: it is a work queue, not a catalogue. Magento generates
     * rows only `schedule_ahead_for` minutes ahead (default 20) and prunes
     * succeeded history after `history_success_lifetime` minutes (default
     * 60), so a daily job's code is present for roughly 80 minutes out of
     * 1440 and absent the rest of the day. On a stock 2.4.8 install that hid
     * 33 of 70 declared jobs, third-party integration jobs among them --
     * exactly what this picker exists to offer. It also skews the list
     * toward broken jobs, since `history_failure_lifetime` defaults to 4320
     * minutes, 72x the successful one.
     *
     * The connector's own watchtower_* jobs are excluded from both sources
     * because monitoring them as an "integration" is circular: the job that
     * reports the signal would be the source of the signal.
     *
     * @return array<string, string[]> Cron group name => sorted job codes
     */
    public function cronJobCodesByGroup(): array
    {
        $grouped = [];
        $declared = [];

        foreach ($this->cronConfig->getJobs() as $group => $jobs) {
            foreach (array_keys($jobs) as $jobCode) {
                $jobCode = (string) $jobCode;
                // Recorded before the exclusion so a declared watchtower_*
                // job is not re-added below as an "undeclared" one.
                $declared[$jobCode] = true;

                if ($this->isOwnJob($jobCode)) {
                    continue;
                }

                $grouped[(string) $group][] = $jobCode;
            }
        }

        foreach ($this->scheduledCronJobCodes() as $jobCode) {
            if (!isset($declared[$jobCode])) {
                $grouped[self::UNDECLARED_GROUP][] = $jobCode;
            }
        }

        foreach ($grouped as &$jobCodes) {
            $jobCodes = array_values(array_unique($jobCodes));
            sort($jobCodes, SORT_NATURAL);
        }
        unset($jobCodes);

        ksort($grouped, SORT_NATURAL);

        return $grouped;
    }

    /**
     * Lists the distinct cron job codes this install currently has queued or in recent history.
     *
     * @return string[]
     */
    private function scheduledCronJobCodes(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('cron_schedule');

        $codes = $connection->fetchCol(
            $connection->select()
                ->distinct()
                ->from($table, ['job_code'])
                ->where('job_code NOT LIKE ?', 'watchtower\_%')
                ->order('job_code ASC')
        );

        return array_map('strval', $codes);
    }

    /**
     * Whether this job code belongs to the connector's own crontab.xml.
     *
     * @param string $jobCode
     * @return bool
     */
    private function isOwnJob(string $jobCode): bool
    {
        return str_starts_with($jobCode, 'watchtower_');
    }

    /**
     * Lists the distinct message-queue topics this install has run operations for.
     *
     * @return string[]
     */
    public function queueTopics(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('magento_operation');

        $topics = $connection->fetchCol(
            $connection->select()
                ->distinct()
                ->from($table, ['topic_name'])
                ->order('topic_name ASC')
        );

        return array_map('strval', $topics);
    }
}
