<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Buffer;

use Magento\Framework\App\ResourceConnection;
use Watchtower\Connector\Model\Api\MetricReport;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;

/**
 * CRUD for watchtower_report_buffer and its single retry clock in
 * watchtower_submission_backoff.
 *
 * The backoff is whole-buffer, not per report: submitting a newer report
 * while withholding an older buffered one would advance the platform's
 * sequence-number high-water mark and permanently invalidate the older one,
 * so every submission attempt carries the entire buffer.
 */
class ReportBufferRepository
{
    private const TABLE = 'watchtower_report_buffer';
    private const BACKOFF_TABLE = 'watchtower_submission_backoff';
    private const BACKOFF_ROW_ID = 1;

    /**
     * 5 minutes doubling per attempt, capped at 4 hours. The hourly
     * evaluation tick bounds retry frequency in practice; the cap mainly
     * stops a manually re-run `watchtower:report` from hammering a
     * still-down platform.
     */
    private const BASE_BACKOFF_MINUTES = 5;
    private const MAX_BACKOFF_MINUTES = 240;

    /**
     * A malformed or malicious Retry-After shouldn't be able to park the
     * connector indefinitely; clamp to a sane upper bound regardless of
     * what the header says.
     */
    private const MAX_RETRY_AFTER_SECONDS = 86400;

    /**
     * One day under the platform's 30-day report horizon: a report older
     * than that horizon 422s its whole batch, not just itself, so it must
     * be evicted before it gets there.
     */
    private const MAX_REPORT_AGE_DAYS = 29;

    /**
     * Row-count cap so a long outage can't grow the buffer unboundedly
     * before age-based eviction catches up. Overflow evicts oldest first.
     */
    private const MAX_BUFFERED_REPORTS = 5000;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Whether the buffer as a whole is due for a retry attempt.
     *
     * No backoff row at all means no known failure yet, i.e. always due.
     *
     * @param \DateTimeImmutable $now
     * @return bool
     */
    public function isDue(\DateTimeImmutable $now): bool
    {
        $row = $this->backoffRow();
        if ($row === false) {
            return true;
        }

        return $now >= new \DateTimeImmutable($row['next_attempt_at'], new \DateTimeZone('UTC'));
    }

    /**
     * A submission attempt failed (whether or not it included buffered
     * reports); bump the shared attempt counter and push the buffer's
     * retry clock out.
     *
     * @param int|null $retryAfterSeconds
     * @param \DateTimeImmutable $now
     * @return void
     */
    public function recordFailure(?int $retryAfterSeconds, \DateTimeImmutable $now): void
    {
        $current = $this->backoffRow();
        $attemptCount = ($current !== false ? (int) $current['attempt_count'] : 0) + 1;

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::BACKOFF_TABLE);

        $connection->insertOnDuplicate(
            $table,
            [
                'id' => self::BACKOFF_ROW_ID,
                'attempt_count' => $attemptCount,
                'next_attempt_at' => $this->formatUtc($this->nextAttemptAt($attemptCount, $retryAfterSeconds, $now)),
            ],
            ['attempt_count', 'next_attempt_at']
        );
    }

    /**
     * A submission succeeded: resets the failure streak and marks the buffer immediately due again.
     *
     * @param \DateTimeImmutable $now
     * @return void
     */
    public function clearBackoff(\DateTimeImmutable $now): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::BACKOFF_TABLE);

        $connection->insertOnDuplicate(
            $table,
            [
                'id' => self::BACKOFF_ROW_ID,
                'attempt_count' => 0,
                'next_attempt_at' => $this->formatUtc($now),
                'last_success_at' => $this->formatUtc($now),
            ],
            ['attempt_count', 'next_attempt_at', 'last_success_at']
        );
    }

    /**
     * How many reports are currently buffered.
     *
     * @return int
     */
    public function bufferedCount(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        return (int) $connection->fetchOne($connection->select()->from($table, ['COUNT(*)']));
    }

    /**
     * When a submission last succeeded, or null if none ever has.
     *
     * @return \DateTimeImmutable|null
     */
    public function lastSuccessfulSubmissionAt(): ?\DateTimeImmutable
    {
        $row = $this->backoffRow();

        if ($row === false || $row['last_success_at'] === null) {
            return null;
        }

        return new \DateTimeImmutable($row['last_success_at'], new \DateTimeZone('UTC'));
    }

    /**
     * Every currently-buffered report, oldest first, capped at $limit rows.
     *
     * @param int $limit
     * @return BufferedReport[]
     */
    public function allBuffered(int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table)
                ->order('buffer_id ASC')
                ->limit($limit)
        );

        return array_map($this->toBufferedReport(...), $rows);
    }

    /**
     * Adds a report to the buffer for later retry, then evicts oldest reports if over MAX_BUFFERED_REPORTS.
     *
     * @param MetricReport $report
     * @return int number of other reports evicted for capacity (0 most of the time)
     */
    public function bufferReport(MetricReport $report): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->insert($table, [
            'store_view_code' => $report->storeViewCode,
            'event_type' => $report->eventType,
            'status' => $report->status->value,
            'sequence_number' => $report->sequenceNumber,
            'evaluated_at' => $this->formatUtc($report->evaluatedAt),
            'reason' => $report->reason->value,
            'ruleset_version' => $report->rulesetVersion,
        ]);

        return $this->evictOldestBeyondCapacity();
    }

    /**
     * A buffered report reached the platform (accepted or rejected, either way delivery succeeded).
     *
     * @param int[] $bufferIds
     * @return void
     */
    public function deleteDelivered(array $bufferIds): void
    {
        if ($bufferIds === []) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->delete($table, ['buffer_id IN (?)' => $bufferIds]);
    }

    /**
     * Evicts the oldest buffered reports if the total is over MAX_BUFFERED_REPORTS. Runs after every insert.
     *
     * @return int number of rows evicted (0 when under capacity)
     */
    private function evictOldestBeyondCapacity(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $totalCount = (int) $connection->fetchOne($connection->select()->from($table, ['COUNT(*)']));
        $excess = $totalCount - self::MAX_BUFFERED_REPORTS;

        if ($excess <= 0) {
            return 0;
        }

        $oldestIds = $connection->fetchCol(
            $connection->select()->from($table, ['buffer_id'])->order('buffer_id ASC')->limit($excess)
        );

        $connection->delete($table, ['buffer_id IN (?)' => $oldestIds]);

        return count($oldestIds);
    }

    /**
     * Evicts reports older than MAX_REPORT_AGE_DAYS. Must run before a batch is built, not after.
     *
     * @param \DateTimeImmutable $now
     * @return int number of reports discarded
     */
    public function discardExpired(\DateTimeImmutable $now): int
    {
        $cutoff = $now->modify(sprintf('-%d days', self::MAX_REPORT_AGE_DAYS));

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        return (int) $connection->delete($table, ['evaluated_at < ?' => $this->formatUtc($cutoff)]);
    }

    /**
     * Every datetime column in this repository is written and compared in UTC.
     *
     * @param \DateTimeImmutable $dateTime
     * @return string
     */
    private function formatUtc(\DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Fetches the single backoff row, or false when none exists yet.
     *
     * @return array|false
     */
    private function backoffRow(): array|false
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::BACKOFF_TABLE);

        return $connection->fetchRow(
            $connection->select()->from($table)->where('id = ?', self::BACKOFF_ROW_ID)
        );
    }

    /**
     * Computes when the next retry attempt is due.
     *
     * @param int $attemptCountAfterThisFailure
     * @param int|null $retryAfterSeconds
     * @param \DateTimeImmutable $now
     * @return \DateTimeImmutable
     */
    private function nextAttemptAt(
        int $attemptCountAfterThisFailure,
        ?int $retryAfterSeconds,
        \DateTimeImmutable $now
    ): \DateTimeImmutable {
        if ($retryAfterSeconds !== null) {
            $clamped = max(0, min(self::MAX_RETRY_AFTER_SECONDS, $retryAfterSeconds));

            return $now->modify(sprintf('+%d seconds', $clamped));
        }

        $backoffMinutes = min(
            self::MAX_BACKOFF_MINUTES,
            self::BASE_BACKOFF_MINUTES * (2 ** ($attemptCountAfterThisFailure - 1))
        );

        return $now->modify(sprintf('+%d minutes', $backoffMinutes));
    }

    /**
     * Maps a raw buffer row to its typed domain object.
     *
     * @param array $row
     * @return BufferedReport
     */
    private function toBufferedReport(array $row): BufferedReport
    {
        return new BufferedReport(
            bufferId: (int) $row['buffer_id'],
            report: new MetricReport(
                storeViewCode: $row['store_view_code'],
                eventType: $row['event_type'],
                status: SignalStatus::from($row['status']),
                sequenceNumber: (int) $row['sequence_number'],
                evaluatedAt: new \DateTimeImmutable($row['evaluated_at'], new \DateTimeZone('UTC')),
                reason: ReportReason::from($row['reason']),
                rulesetVersion: $row['ruleset_version'],
            ),
        );
    }
}
