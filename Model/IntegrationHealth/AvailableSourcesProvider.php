<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

use Magento\Framework\App\ResourceConnection;

/**
 * Enumerates the integration_health sources a merchant can pick from in the
 * admin picker: the cron job codes and message-queue topics this Magento
 * install has actually seen.
 *
 * Only two of the three source types are enumerable: convention_event's
 * identifier is a free-typed integration label with no server-side list to
 * read, so the picker offers a text input for it instead.
 */
class AvailableSourcesProvider
{
    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Lists the distinct cron job codes this install has scheduled, excluding this module's own jobs.
     *
     * The connector's own watchtower_* jobs are filtered out because
     * monitoring them as an "integration" is circular: the job that reports
     * the signal would be the source of the signal.
     *
     * @return string[]
     */
    public function cronJobCodes(): array
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
