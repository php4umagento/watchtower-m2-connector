<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Bulk\OperationInterface;

/**
 * Reads Magento's magento_operation table for the freshest success/failure
 * evidence of a store-configured topic name. topic_name is the selectable
 * identifier: it distinguishes one consumer/bulk operation's rows from
 * another's, and is exposed directly on magento_operation with no join to
 * magento_bulk required (started_at already lives on the operation row).
 *
 * STATUS_TYPE_OPEN is excluded from both success and failure below, since
 * it means "still pending", neither.
 */
class QueueConsumerObserver
{
    private const LOOKBACK_MINUTES = 120;

    private const FAILURE_STATUSES = [
        OperationInterface::STATUS_TYPE_RETRIABLY_FAILED,
        OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED,
        OperationInterface::STATUS_TYPE_REJECTED,
    ];

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Fetches the freshest success/failure evidence for one topic name within the lookback window.
     *
     * @param string $topicName
     * @param \DateTimeImmutable $now
     * @return Observation
     */
    public function observe(string $topicName, \DateTimeImmutable $now): Observation
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('magento_operation');
        $lookbackValue = $now->modify('-' . self::LOOKBACK_MINUTES . ' minutes')->format('Y-m-d H:i:s');

        $latestSuccessAt = $connection->fetchOne(
            $connection->select()
                ->from($table, ['started_at'])
                ->where('topic_name = ?', $topicName)
                ->where('status = ?', OperationInterface::STATUS_TYPE_COMPLETE)
                ->where('started_at >= ?', $lookbackValue)
                ->order('started_at DESC')
                ->limit(1)
        );

        $latestFailureAt = $connection->fetchOne(
            $connection->select()
                ->from($table, ['started_at'])
                ->where('topic_name = ?', $topicName)
                ->where('status IN (?)', self::FAILURE_STATUSES)
                ->where('started_at >= ?', $lookbackValue)
                ->order('started_at DESC')
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
     * necessarily UTC.
     *
     * @param string $value
     * @return \DateTimeImmutable
     */
    private function toUtc(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
