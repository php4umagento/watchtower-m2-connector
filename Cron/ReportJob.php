<?php

declare(strict_types=1);

namespace Watchtower\Connector\Cron;

use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Api\MetricsSubmissionResult;
use Watchtower\Connector\Model\Config;
use Watchtower\Connector\Model\ReportingService;

/**
 * Scheduled counterpart to bin/magento watchtower:report, sharing ReportingService with it.
 *
 * etc/crontab.xml polls this every JITTER_BUCKET_MINUTES; most ticks return
 * immediately via isMyJitterMinute(). Every install ships the identical
 * crontab.xml, so a fixed "0 * * * *" would have every connector submit at the
 * same wall-clock moment -- the alignment this jitter breaks.
 */
class ReportJob
{
    /** Cron polling granularity (see etc/crontab.xml); 5 minutes gives 12 offsets across the hour. */
    private const JITTER_BUCKET_MINUTES = 5;

    /**
     * @param ReportingService $reportingService
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ReportingService $reportingService,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Magento's cron dispatcher always calls the job method positionally with
     * the running \Magento\Cron\Model\Schedule, so execute() must declare no
     * parameter -- a typed one here throws a TypeError on every real invocation.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->executeAt(new \DateTimeImmutable());
    }

    /**
     * The evaluate-and-submit logic, with an injectable "now" so tests can
     * assert the jitter guard deterministically. Not the method Magento's
     * cron dispatcher calls -- see execute().
     *
     * @param \DateTimeImmutable $now
     * @return void
     */
    public function executeAt(\DateTimeImmutable $now): void
    {
        if (!$this->isMyJitterMinute($now)) {
            return;
        }

        $outcome = $this->reportingService->run();

        if (!$outcome['ran']) {
            // Not configured or disabled: ordinary states, not worth a log line every tick.
            return;
        }

        if ($outcome['expiredBufferedCount'] > 0) {
            // Discarded past the platform's report-age horizon, not delivered; distinct
            // from a normal backfill line.
            $this->logger->warning(
                'Watchtower discarded buffered reports that exceeded the platform report-age horizon.',
                ['count' => $outcome['expiredBufferedCount']]
            );
        }

        if ($outcome['evictedForCapacityCount'] > 0) {
            // Logged every cycle it happens: each occurrence is new, permanent data
            // loss rather than a persistent state.
            $this->logger->warning(
                'Watchtower evicted buffered reports to stay under the buffer capacity cap.',
                ['count' => $outcome['evictedForCapacityCount']]
            );
        }

        $result = $outcome['result'];

        if ($result === null) {
            // Not attempted: still backing off, or the organization is known paused.
            // Both persist across ticks and are already visible elsewhere (the original
            // failure logged a warning; paused is queryable via watchtower:status).
            return;
        }

        if (!$result->succeeded) {
            // Visibility only, not the record: ReportingService has already buffered
            // this report and pushed back the shared retry clock. errorMessage never
            // carries the API key or a response body -- see Client.php.
            $this->logger->warning('Watchtower report submission failed; buffered for retry.', [
                'error' => $result->errorMessage,
            ]);

            return;
        }

        // A backfill batch; only fires after a real recovery, not every cycle.
        if ($outcome['includedBufferedCount'] > 0) {
            $this->logger->info('Watchtower delivered buffered reports on reconnect.', [
                'count' => $outcome['includedBufferedCount'],
            ]);
        }

        // A 200 with the report inside rejected[] must not be silent: the local
        // sequence already advanced past it, so nothing retries it, and a local
        // sequence drifted below the platform's high-water mark (dropped/restored
        // table) rejects every future report forever. Dedup rejections prove prior
        // delivery, so they log at info rather than joining the warning.
        $isDedup = static fn (array $r): bool
            => ($r['reason'] ?? null) === MetricsSubmissionResult::DEDUP_REJECTION_REASON;
        $dedupRejections = array_values(array_filter($result->rejected, $isDedup));
        $otherRejections = array_values(array_filter($result->rejected, static fn (array $r) => !$isDedup($r)));

        if ($dedupRejections !== []) {
            $this->logger->info('Watchtower report(s) already delivered (dedup).', ['rejected' => $dedupRejections]);
        }

        if ($otherRejections !== []) {
            $this->logger->warning('Watchtower report was rejected by the platform.', [
                'rejected' => $otherRejections,
            ]);
        }
    }

    /**
     * Whether $now falls in this install's own jitter bucket. Compares buckets,
     * not the exact scheduled minute: Magento runs a due job whenever cron:run
     * next reaches it, often a minute or more late, which an equality check
     * would silently skip the whole hour for.
     *
     * @param \DateTimeImmutable $now
     * @return bool
     */
    private function isMyJitterMinute(\DateTimeImmutable $now): bool
    {
        $currentBucket = intdiv((int) $now->format('i'), self::JITTER_BUCKET_MINUTES) * self::JITTER_BUCKET_MINUTES;

        return $currentBucket === $this->jitterOffsetMinutes();
    }

    /**
     * A deterministic per-install offset derived from the already-unique API
     * key, so installs spread across the hour with no new persisted state.
     * Hashing a stable value matters: a fresh random pick per tick would rarely
     * hit the same bucket twice and could skip an entire hour. Falls back to 0
     * when unconfigured, harmless since ReportingService::run() no-ops there.
     *
     * @return int
     */
    private function jitterOffsetMinutes(): int
    {
        $apiKey = $this->config->apiKey();

        if ($apiKey === null || $apiKey === '') {
            return 0;
        }

        $bucketCount = intdiv(60, self::JITTER_BUCKET_MINUTES);
        $bucket = hexdec(substr(hash('sha256', $apiKey), 0, 8)) % $bucketCount;

        return $bucket * self::JITTER_BUCKET_MINUTES;
    }
}
