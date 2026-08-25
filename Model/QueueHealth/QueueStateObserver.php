<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\QueueHealth;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\ConnectionTypeResolver;
use Magento\Framework\MessageQueue\Consumer\ConfigInterface as ConsumerConfigInterface;

/**
 * Polls Magento's queue backends for queue_health: the raw material Evaluator
 * turns into a status.
 *
 * Reports whether work is going undrained and since when, never which queue or
 * how much. A message count is a proxy for order and catalogue volume, and the
 * queue's identity says which part of the business is busy. See the leak review
 * in the platform's connector-metrics-spec.md.
 *
 * Two Magento facts shape this class. The backend is resolved PER CONSUMER: a
 * store defaulting to amqp still runs any consumer whose module declares
 * connection="db". And it must never be inferred from table contents, since
 * MysqlMq's Recurring setup fills `queue` for every topology queue whatever the
 * backend.
 */
class QueueStateObserver
{
    /**
     * Consumers whose stalling a merchant would actually notice.
     *
     * Platform-fixed, and deliberately not every declared consumer: a third of
     * stock Magento's are housekeeping nobody needs waking for. That matters
     * more here than elsewhere because the queue name cannot cross the wire, so
     * an alert the merchant cannot act on is worse than none.
     *
     * These are CONSUMER names, which are not always queue names.
     */
    private const WATCHED_CONSUMERS = [
        'async.operations.all',
        'inventory.indexer.stock',
        'inventory.indexer.sourceItem',
        'sales.rule.quote.trigger.recollect',
        'catalog_product_generate_urls',
        'product_action_attribute.update',
        'media.storage.catalog.image.resize',
        'saveConfigProcessor',
    ];

    /**
     * Statuses meaning a row is still waiting: NEW and RETRY_REQUIRED.
     *
     * Both, not just NEW. Magento's own getMessagesCount() counts both, so
     * reading NEW alone reports a queue in a retry storm as empty. Literals
     * rather than QueueManagement's constants, so an AMQP-only store need not
     * enable Magento_MysqlMq. The enum starts at 2.
     */
    private const PENDING_MESSAGE_STATUSES = [2, 5];

    /**
     * @param ConsumerConfigInterface $consumerConfig
     * @param ConnectionTypeResolver $connectionTypeResolver
     * @param ResourceConnection $resourceConnection
     * @param AmqpQueueProbe $amqpQueueProbe
     */
    public function __construct(
        private readonly ConsumerConfigInterface $consumerConfig,
        private readonly ConnectionTypeResolver $connectionTypeResolver,
        private readonly ResourceConnection $resourceConnection,
        private readonly AmqpQueueProbe $amqpQueueProbe,
    ) {
    }

    /**
     * Polls every watched consumer and reports the oldest undrained onset.
     *
     * @param \DateTimeImmutable $now
     * @return Observation
     */
    public function observe(\DateTimeImmutable $now): Observation
    {
        $onsets = [];
        $undrainedWithoutOnset = false;
        $affectedQueues = [];

        foreach ($this->consumerConfig->getConsumers() as $consumer) {
            if (!in_array($consumer->getName(), self::WATCHED_CONSUMERS, true)) {
                continue;
            }

            $connectionName = (string) $consumer->getConnection();

            try {
                $connectionType = $this->connectionTypeResolver->getConnectionType($connectionName);
            } catch (\LogicException $e) {
                // Magento's ConsumersRunner::canBeRun() skips these too, so
                // nothing is expected to drain the queue and calling it a fault
                // would alert on a healthy store.
                continue;
            }

            $queueName = (string) $consumer->getQueue();

            if ($connectionType === 'db') {
                $onset = $this->mysqlUndrainedSince($queueName);

                if ($onset !== null) {
                    $onsets[] = $onset;
                    $affectedQueues[] = $queueName;
                }

                continue;
            }

            if ($connectionType === 'amqp' && $this->amqpQueueProbe->isUndrained($connectionName, $queueName)) {
                $undrainedWithoutOnset = true;
                $affectedQueues[] = $queueName;
            }

            // Any other type (stomp, 2.4.8+) is left alone rather than guessed
            // at: silence is the honest answer to a backend with no reader.
        }

        return new Observation(
            undrainedSince: $onsets === [] ? null : min($onsets),
            undrainedWithoutOnset: $undrainedWithoutOnset,
            affectedQueues: $affectedQueues,
        );
    }

    /**
     * When the oldest pending message on a MySQL-backed queue arrived.
     *
     * Null when nothing is waiting on it.
     *
     * MIN() only, never a row, keeping this inside the same aggregate-only
     * constraint as every other source; what it returns is a duration input,
     * not a quantity. A draining queue keeps this young however deep it gets,
     * which is the discriminator a depth threshold would lack.
     *
     * Uses updated_at because the table has no created_at. For a NEW row that
     * is its insert time. A retried row carries its last transition and so
     * looks younger, erring toward not alerting.
     *
     * @param string $queueName
     * @return \DateTimeImmutable|null
     */
    private function mysqlUndrainedSince(string $queueName): ?\DateTimeImmutable
    {
        $connection = $this->resourceConnection->getConnection();
        $statusTable = $this->resourceConnection->getTableName('queue_message_status');
        $queueTable = $this->resourceConnection->getTableName('queue');

        if (!$connection->isTableExists($statusTable) || !$connection->isTableExists($queueTable)) {
            // Magento_MysqlMq disabled: no db-backed queue to fall behind on.
            return null;
        }

        $oldest = $connection->fetchOne(
            $connection->select()
                ->from(['qms' => $statusTable], ['MIN(qms.updated_at)'])
                ->join(['q' => $queueTable], 'q.id = qms.queue_id', [])
                ->where('q.name = ?', $queueName)
                ->where('qms.status IN (?)', self::PENDING_MESSAGE_STATUSES)
        );

        if (!is_string($oldest) || $oldest === '') {
            // MIN() over no matching rows yields null rather than a row.
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $oldest, new \DateTimeZone('UTC'));

        return $date === false ? null : $date;
    }
}
