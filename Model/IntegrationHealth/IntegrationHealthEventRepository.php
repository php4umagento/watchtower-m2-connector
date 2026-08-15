<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

use Magento\Framework\App\ResourceConnection;

/**
 * CRUD for watchtower_integration_health_event -- the connector's local
 * snapshot of watchtower_integration_health event dispatches. Dispatches are
 * persisted rather than held in memory because a single tick's observation
 * window can legitimately be empty between dispatches, and the connector
 * must still be able to answer "when did this integration last report
 * ok/failed".
 */
class IntegrationHealthEventRepository
{
    private const TABLE = 'watchtower_integration_health_event';

    /**
     * How far back to look for evidence at all -- matches CronJobObserver/
     * QueueConsumerObserver's lookback window. This bounds the query rather
     * than filtering meaningfully; Evaluator's expected-max-interval
     * comparison is what actually decides the status.
     */
    private const LOOKBACK_MINUTES = 120;

    /**
     * This table has no dedicated prune cron. Convention-event dispatch
     * volume is opt-in and store-view/label-scoped, so it is pruned
     * opportunistically on every record() write instead, scoped to just that
     * (store view, integration label) pair via the same composite index the
     * read path uses.
     */
    private const RETENTION_DAYS = 90;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Records one observed dispatch of the watchtower_integration_health event.
     *
     * @param int $storeViewId
     * @param string $integrationLabel
     * @param string $status 'ok' or 'failed', as dispatched
     * @param \DateTimeImmutable $observedAt
     * @return void
     */
    public function record(
        int $storeViewId,
        string $integrationLabel,
        string $status,
        \DateTimeImmutable $observedAt
    ): void {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $observedAtUtc = $observedAt->setTimezone(new \DateTimeZone('UTC'));

        $connection->insert($table, [
            'store_view_id' => $storeViewId,
            'integration_label' => $integrationLabel,
            'status' => $status,
            'observed_at' => $observedAtUtc->format('Y-m-d H:i:s'),
        ]);

        $cutoff = $observedAtUtc->modify('-' . self::RETENTION_DAYS . ' days')->format('Y-m-d H:i:s');

        $connection->delete($table, [
            'store_view_id = ?' => $storeViewId,
            'integration_label = ?' => $integrationLabel,
            'observed_at < ?' => $cutoff,
        ]);
    }

    /**
     * Fetches the freshest success/failure evidence for one (store view, integration label) pair.
     *
     * @param int $storeViewId
     * @param string $integrationLabel
     * @param \DateTimeImmutable $now
     * @return Observation
     */
    public function latestObservation(int $storeViewId, string $integrationLabel, \DateTimeImmutable $now): Observation
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $lookbackValue = $now->modify('-' . self::LOOKBACK_MINUTES . ' minutes')->format('Y-m-d H:i:s');

        $latestSuccessAt = $connection->fetchOne(
            $connection->select()
                ->from($table, ['observed_at'])
                ->where('store_view_id = ?', $storeViewId)
                ->where('integration_label = ?', $integrationLabel)
                ->where('status = ?', 'ok')
                ->where('observed_at >= ?', $lookbackValue)
                ->order('observed_at DESC')
                ->limit(1)
        );

        $latestFailureAt = $connection->fetchOne(
            $connection->select()
                ->from($table, ['observed_at'])
                ->where('store_view_id = ?', $storeViewId)
                ->where('integration_label = ?', $integrationLabel)
                ->where('status = ?', 'failed')
                ->where('observed_at >= ?', $lookbackValue)
                ->order('observed_at DESC')
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
