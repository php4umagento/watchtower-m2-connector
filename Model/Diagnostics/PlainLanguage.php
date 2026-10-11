<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Diagnostics;

use Watchtower\Connector\Model\Api\SignalStatus;

/**
 * Turns the diagnostics snapshot into wording a shop owner can act on. It
 * deliberately names no method, check or threshold: the page says whether
 * things are fine and what to do, and `watchtower:status` keeps the engineering view.
 */
class PlainLanguage
{
    /** Reports go out hourly, so this much silence means something is stopping them. */
    private const STALE_REPORT_HOURS = 3;

    private const NAMES = [
        'basket_quote' => 'Cart activity',
        'checkout' => 'Checkout',
        'customer_account' => 'Customer accounts',
        'cron_health' => 'Scheduled jobs (cron)',
        'integration_health' => 'Integrations',
    ];

    private const DESCRIPTIONS = [
        'basket_quote' => 'Shoppers adding products to their carts.',
        'checkout' => 'Orders being placed.',
        'customer_account' => 'Customers signing in and registering.',
        'cron_health' => 'Magento\'s background jobs are running on time.',
        'integration_health' => 'Syncs such as ERP or order export are completing.',
    ];

    /** Rate signals read as "fewer/more X than usual"; the rest only have a "not running as expected" state. */
    private const RATE_NOUNS = [
        'basket_quote' => 'shopping carts',
        'checkout' => 'orders',
        'customer_account' => 'customer account activity',
    ];

    /**
     * @param InsufficientDataLabel $insufficientDataLabel
     */
    public function __construct(
        private readonly InsufficientDataLabel $insufficientDataLabel
    ) {
    }

    /**
     * @param string $category
     * @return string
     */
    public function name(string $category): string
    {
        return self::NAMES[$category] ?? ucfirst(str_replace('_', ' ', $category));
    }

    /**
     * @param string $category
     * @return string
     */
    public function description(string $category): string
    {
        return self::DESCRIPTIONS[$category] ?? '';
    }

    /**
     * @param SignalSnapshot $signal
     * @return PlainStatus
     */
    public function signal(SignalSnapshot $signal): PlainStatus
    {
        if ($signal->status === null) {
            return new PlainStatus('Waiting for first check', PlainStatus::INFO);
        }

        return match ($signal->status) {
            SignalStatus::Normal => new PlainStatus('Normal', PlainStatus::GOOD),
            SignalStatus::InsufficientData => $this->notEnoughData($signal),
            default => $this->unusual($signal),
        };
    }

    /**
     * Whether the signal needs the shop owner to look at something.
     *
     * @param SignalSnapshot $signal
     * @return bool
     */
    public function needsAttention(SignalSnapshot $signal): bool
    {
        return $this->signal($signal)->tone === PlainStatus::BAD;
    }

    /**
     * Whether the signal is genuinely still learning, as opposed to normal, unusual or too quiet.
     *
     * @param SignalSnapshot $signal
     * @return bool
     */
    public function isLearning(SignalSnapshot $signal): bool
    {
        return $signal->status === null || $this->insufficientDataLabel->isWarmingUp($signal);
    }

    /**
     * The one-line answer at the top of the page, most urgent problem first.
     *
     * @param DiagnosticsSnapshot $snapshot
     * @param \DateTimeImmutable $now
     * @return PlainStatus
     */
    public function overall(DiagnosticsSnapshot $snapshot, \DateTimeImmutable $now): PlainStatus
    {
        $blocking = $this->blockingProblem($snapshot);

        if ($blocking !== null) {
            return $blocking;
        }

        $silence = $this->reportSilence($snapshot, $now);

        if ($silence !== null) {
            return $silence;
        }

        $attention = $this->countNeedingAttention($snapshot);

        if ($attention > 0) {
            return new PlainStatus(
                $attention === 1 ? 'One thing needs your attention' : sprintf('%d things need your attention', $attention),
                PlainStatus::BAD,
                'Details are listed below.'
            );
        }

        if ($this->anythingLearning($snapshot)) {
            return new PlainStatus(
                'Watchtower is connected and still learning',
                PlainStatus::INFO,
                'It is working out what is normal for your store. Until it has seen enough, '
                . 'some checks below will say they are still learning.'
            );
        }

        return new PlainStatus('Everything looks normal', PlainStatus::GOOD, 'Watchtower is connected and watching your store.');
    }

    /**
     * Extra things worth knowing that do not change the headline.
     *
     * @param DiagnosticsSnapshot $snapshot
     * @return PlainStatus[]
     */
    public function notices(DiagnosticsSnapshot $snapshot): array
    {
        $notices = [];
        $version = $snapshot->connectorVersion;

        if ($version->updateAvailable && !$version->belowMinimum) {
            $notices[] = new PlainStatus(
                sprintf(
                    'A newer Watchtower connector is available (you have %s, the latest is %s).',
                    $version->installedVersion ?? 'unknown',
                    $version->latestVersion ?? 'unknown'
                ),
                PlainStatus::INFO,
                'Update it with Composer to get the latest improvements.'
            );
        }

        if ($snapshot->environment->magentoEol?->isEol === true) {
            $notices[] = new PlainStatus(
                sprintf(
                    'Your Magento version stopped being supported on %s.',
                    $snapshot->environment->magentoEol->eolDate ?? 'an earlier date'
                ),
                PlainStatus::WARN,
                'It no longer receives security updates. Plan an upgrade.'
            );
        }

        if ($snapshot->bufferedReportCount > 0) {
            $notices[] = new PlainStatus(
                sprintf(
                    '%d %s waiting to be sent.',
                    $snapshot->bufferedReportCount,
                    $snapshot->bufferedReportCount === 1 ? 'report is' : 'reports are'
                ),
                PlainStatus::INFO,
                'They will go out automatically once the connection is working.'
            );
        }

        return $notices;
    }

    /**
     * "12 minutes ago" style wording for the last report.
     *
     * @param \DateTimeImmutable|null $then
     * @param \DateTimeImmutable $now
     * @return string
     */
    public function ago(?\DateTimeImmutable $then, \DateTimeImmutable $now): string
    {
        if ($then === null) {
            return 'Not yet';
        }

        $seconds = max(0, $now->getTimestamp() - $then->getTimestamp());

        if ($seconds < 120) {
            return 'Just now';
        }

        if ($seconds < 7200) {
            return sprintf('%d minutes ago', intdiv($seconds, 60));
        }

        if ($seconds < 172800) {
            return sprintf('%d hours ago', intdiv($seconds, 3600));
        }

        return sprintf('%d days ago', intdiv($seconds, 86400));
    }

    /**
     * @param DiagnosticsSnapshot $snapshot
     * @return int signals across the whole shop that need a look
     */
    public function countNeedingAttention(DiagnosticsSnapshot $snapshot): int
    {
        $count = $this->needsAttention($snapshot->cronHealth) ? 1 : 0;

        foreach ($snapshot->storeViews as $storeView) {
            foreach ($storeView->signals as $signal) {
                $count += $this->needsAttention($signal) ? 1 : 0;
            }
        }

        return $count;
    }

    /**
     * @param StoreViewSnapshot $storeView
     * @return bool
     */
    public function storeViewNeedsAttention(StoreViewSnapshot $storeView): bool
    {
        foreach ($storeView->signals as $signal) {
            if ($this->needsAttention($signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param StoreViewSnapshot $storeView
     * @return bool
     */
    public function storeViewLearning(StoreViewSnapshot $storeView): bool
    {
        foreach ($storeView->signals as $signal) {
            if ($this->isLearning($signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param SignalSnapshot $signal
     * @return PlainStatus
     */
    private function notEnoughData(SignalSnapshot $signal): PlainStatus
    {
        if ($this->insufficientDataLabel->isWarmingUp($signal)) {
            return new PlainStatus(
                'Still learning',
                PlainStatus::INFO,
                'Watchtower is working out what is normal here. This can take a few weeks.'
            );
        }

        return new PlainStatus(
            'Too quiet to judge',
            PlainStatus::INFO,
            'There is too little activity here for Watchtower to tell a real change from a quiet spell. '
            . 'Its other checks are not affected.'
        );
    }

    /**
     * @param SignalSnapshot $signal
     * @return PlainStatus
     */
    private function unusual(SignalSnapshot $signal): PlainStatus
    {
        $noun = self::RATE_NOUNS[$signal->category] ?? null;

        if ($noun === null) {
            return new PlainStatus('Needs attention', PlainStatus::BAD, 'This is not running as expected.');
        }

        $severe = $signal->status === SignalStatus::SevereDrop || $signal->status === SignalStatus::SevereSpike;
        $drop = $signal->status === SignalStatus::MildDrop || $signal->status === SignalStatus::SevereDrop;

        $comparison = $drop ? ($severe ? 'Far fewer' : 'Fewer') : ($severe ? 'Far more' : 'More');

        return new PlainStatus(
            'Needs attention',
            PlainStatus::BAD,
            sprintf('%s %s than usual. If you did not expect this, it is worth checking your store.', $comparison, $noun)
        );
    }

    /**
     * Problems that stop monitoring altogether.
     *
     * @param DiagnosticsSnapshot $snapshot
     * @return PlainStatus|null
     */
    private function blockingProblem(DiagnosticsSnapshot $snapshot): ?PlainStatus
    {
        if (!$snapshot->reachable && $snapshot->unreachableError === DiagnosticsSnapshotProvider::NOT_CONFIGURED_ERROR) {
            return new PlainStatus(
                'Watchtower is not set up yet',
                PlainStatus::INFO,
                'Add your connection key under Stores > Configuration > Watchtower to start watching your store.'
            );
        }

        if (!$snapshot->reachable) {
            return new PlainStatus(
                'Watchtower cannot be reached',
                PlainStatus::BAD,
                'Your store is not being watched until this is fixed. Check that this server can reach the internet, '
                . 'then reload this page.'
            );
        }

        if ($snapshot->keyValid === false) {
            return new PlainStatus(
                'The connection key was not accepted',
                PlainStatus::BAD,
                'Check the key under Stores > Configuration > Watchtower.'
            );
        }

        if ($snapshot->organizationPaused === true) {
            return new PlainStatus(
                'Your Watchtower account is paused',
                PlainStatus::BAD,
                'Alerts are off. Sign in to your Watchtower account to see why.'
            );
        }

        if ($snapshot->connectorVersion->belowMinimum) {
            return new PlainStatus(
                'This connector version is too old and has stopped reporting',
                PlainStatus::BAD,
                'Update it with Composer to start watching your store again.'
            );
        }

        return null;
    }

    /**
     * @param DiagnosticsSnapshot $snapshot
     * @param \DateTimeImmutable $now
     * @return PlainStatus|null
     */
    private function reportSilence(DiagnosticsSnapshot $snapshot, \DateTimeImmutable $now): ?PlainStatus
    {
        $last = $snapshot->lastSuccessfulSubmissionAt;

        if ($last === null) {
            return new PlainStatus(
                'Waiting for the first report',
                PlainStatus::INFO,
                'Reports are sent by Magento\'s scheduled jobs. If this stays here for more than an hour or two, '
                . 'check that cron is running.'
            );
        }

        if ($now->getTimestamp() - $last->getTimestamp() > self::STALE_REPORT_HOURS * 3600) {
            return new PlainStatus(
                'No report has reached Watchtower for a while',
                PlainStatus::WARN,
                sprintf(
                    'The last one arrived %s. Reports are sent by Magento\'s scheduled jobs, so check that cron is running.',
                    strtolower($this->ago($last, $now))
                )
            );
        }

        return null;
    }

    /**
     * @param DiagnosticsSnapshot $snapshot
     * @return bool
     */
    private function anythingLearning(DiagnosticsSnapshot $snapshot): bool
    {
        if ($this->isLearning($snapshot->cronHealth)) {
            return true;
        }

        foreach ($snapshot->storeViews as $storeView) {
            if ($this->storeViewLearning($storeView)) {
                return true;
            }
        }

        return false;
    }
}
