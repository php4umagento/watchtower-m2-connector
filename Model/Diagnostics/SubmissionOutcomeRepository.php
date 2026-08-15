<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\Diagnostics;

use Magento\Framework\App\ResourceConnection;

/**
 * CRUD for watchtower_submission_outcome_log -- the durable record of past
 * submission attempts shown by the diagnostics page and watchtower:status.
 * Self-prunes to the newest MAX_ROWS on every write rather than relying on
 * a separate scheduled cron.
 */
class SubmissionOutcomeRepository
{
    private const TABLE = 'watchtower_submission_outcome_log';

    /**
     * A single reporting cycle can record up to MAX_SUBMISSIONS_PER_CYCLE (5)
     * rows when ReportingService::run() loops multiple requests, so this cap
     * is sized for ~20 cycles of history in the worst case.
     */
    private const MAX_ROWS = 100;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Records one submission attempt's outcome, then prunes back to MAX_ROWS.
     *
     * @param bool $succeeded
     * @param int $acceptedCount
     * @param array<int,array{event_type:string,sequence_number:int,reason:string,store_view_code?:string}> $rejected
     *        the platform's own rejected[] array (MetricsSubmissionResult::$rejected); both the count and the
     *        reason breakdown are derived from it rather than passed in separately and allowed to drift.
     * @param string|null $errorMessage
     * @param \DateTimeImmutable $occurredAt
     * @return void
     */
    public function record(
        bool $succeeded,
        int $acceptedCount,
        array $rejected,
        ?string $errorMessage,
        \DateTimeImmutable $occurredAt
    ): void {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $reasonCounts = [];
        foreach ($rejected as $rejection) {
            // $rejected comes straight from the platform's response body, so
            // the key is not guaranteed to be present.
            $reason = $rejection['reason'] ?? '(no reason given)';
            $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
        }

        $connection->insert($table, [
            'succeeded' => $succeeded,
            'accepted_count' => $acceptedCount,
            'rejected_count' => count($rejected),
            'rejection_reasons' => $reasonCounts !== [] ? json_encode($reasonCounts) : null,
            // varchar(255): a raw error message could in principle be
            // longer (e.g. an unusual platform response body echoed back);
            // truncated rather than left to fatal on insert.
            'error_message' => $errorMessage !== null ? substr($errorMessage, 0, 255) : null,
            'occurred_at' => $occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);

        $this->pruneToMaxRows();
    }

    /**
     * The most recent outcomes, newest first, capped at $limit.
     *
     * @param int $limit
     * @return SubmissionOutcome[]
     */
    public function recent(int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $rows = $connection->fetchAll(
            $connection->select()->from($table)->order('id DESC')->limit($limit)
        );

        return array_map($this->toSubmissionOutcome(...), $rows);
    }

    /**
     * Evicts the oldest rows (lowest id) beyond MAX_ROWS.
     *
     * @return void
     */
    private function pruneToMaxRows(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $totalCount = (int) $connection->fetchOne($connection->select()->from($table, ['COUNT(*)']));
        $excess = $totalCount - self::MAX_ROWS;

        if ($excess <= 0) {
            return;
        }

        $oldestIds = $connection->fetchCol(
            $connection->select()->from($table, ['id'])->order('id ASC')->limit($excess)
        );

        $connection->delete($table, ['id IN (?)' => $oldestIds]);
    }

    /**
     * Maps one raw DB row to a SubmissionOutcome value object.
     *
     * @param array $row
     * @return SubmissionOutcome
     */
    private function toSubmissionOutcome(array $row): SubmissionOutcome
    {
        return new SubmissionOutcome(
            succeeded: (bool) $row['succeeded'],
            acceptedCount: (int) $row['accepted_count'],
            rejectedCount: (int) $row['rejected_count'],
            rejectionReasons: $row['rejection_reasons'] !== null
                ? (array) json_decode((string) $row['rejection_reasons'], true)
                : [],
            errorMessage: $row['error_message'],
            occurredAt: new \DateTimeImmutable($row['occurred_at'], new \DateTimeZone('UTC')),
        );
    }
}
