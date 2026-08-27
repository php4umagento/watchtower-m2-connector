<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Cron;

use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Api\MetricsSubmissionResult;
use Watchtower\Connector\Model\CronJobObservation\CronJobRunRecorder;
use Watchtower\Connector\Model\Reporting\ReportCycleStateRepository;
use Watchtower\Connector\Model\ReportingService;

/**
 * Scheduled counterpart to bin/magento watchtower:report, sharing ReportingService with it.
 *
 * etc/crontab.xml polls this every 5 minutes, but the real evaluate-and-submit
 * cycle -- expensive: it evaluates every live store view's rate signals, not
 * just a network call -- only actually runs roughly once an hour, gated by
 * isDue() below rather than every tick. The cron run recorder is the one
 * exception: it runs on every tick, because cron_schedule does not retain its
 * evidence for a full hour.
 *
 * Previously gated on a per-install "jitter minute" derived from the API key
 * hash, comparing against a tolerance window (see git history). That still
 * assumed the host's own system cron invokes bin/magento cron:run at least
 * as often as this job's own 5-minute schedule -- on a real production
 * install whose host only ran cron:run every 10 minutes, this install's
 * jitter window landed on a slot that was *never* actually reached, so the
 * real cycle silently never ran, permanently, with cron itself reporting
 * "success" the whole time (nothing to fail -- it just correctly determined
 * "not my minute" every single tick). isDue() instead tracks elapsed time
 * since the last real run, so it self-corrects regardless of how often (or
 * how irregularly) the outer cron actually ticks, as long as it ticks at
 * all. Naturally staggers installs across the hour too, since each one's
 * cycle is anchored to whenever it first ran rather than a shared clock.
 */
class ReportJob
{
    /** Roughly once per hour, matching the metrics spec's hourly evaluation cadence. */
    private const MIN_INTERVAL_SECONDS = 3600;

    /**
     * @param ReportingService $reportingService
     * @param ReportCycleStateRepository $reportCycleStateRepository
     * @param CronJobRunRecorder $cronJobRunRecorder
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ReportingService $reportingService,
        private readonly ReportCycleStateRepository $reportCycleStateRepository,
        private readonly CronJobRunRecorder $cronJobRunRecorder,
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
     * assert the elapsed-time guard deterministically. Not the method
     * Magento's cron dispatcher calls -- see execute().
     *
     * @param \DateTimeImmutable $now
     * @return void
     */
    public function executeAt(\DateTimeImmutable $now): void
    {
        // Every tick, not just a due one: Magento purges succeeded cron_schedule
        // rows about an hour after they finish, so evidence sampled once per
        // hourly cycle is evidence already partly deleted. Capture-only, never
        // a report.
        $this->cronJobRunRecorder->record($now);

        if (!$this->isDue($now)) {
            return;
        }

        $outcome = $this->reportingService->run();

        if (!$outcome['ran']) {
            // Not configured or disabled: ordinary states, not worth a log line every tick.
            // Deliberately does NOT record a run -- the moment this install becomes
            // configured/enabled, the next tick must treat it as never-run, not
            // wait out the rest of an interval that was never really running.
            return;
        }

        $this->reportCycleStateRepository->save($now);

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
     * Whether at least MIN_INTERVAL_SECONDS has passed since the real cycle
     * last ran -- or it has never run at all, so a freshly-configured
     * install doesn't wait out an interval that never actually started.
     *
     * @param \DateTimeImmutable $now
     * @return bool
     */
    private function isDue(\DateTimeImmutable $now): bool
    {
        $lastRunAt = $this->reportCycleStateRepository->get()->lastRunAt;

        if ($lastRunAt === null) {
            return true;
        }

        return ($now->getTimestamp() - $lastRunAt->getTimestamp()) >= self::MIN_INTERVAL_SECONDS;
    }
}
