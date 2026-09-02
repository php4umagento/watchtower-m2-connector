<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Block\Adminhtml\Diagnostics;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Diagnostics\DiagnosticsSnapshot;
use Watchtower\Connector\Model\Diagnostics\DiagnosticsSnapshotProvider;
use Watchtower\Connector\Model\Diagnostics\SignalSnapshot;
use Watchtower\Connector\Model\Diagnostics\StoreViewSnapshot;
use Watchtower\Connector\Model\Diagnostics\SubmissionOutcome;
use Watchtower\Connector\Model\Seed\SeedCoverageLabel;

/**
 * Backs the diagnostics page -- a thin read-only wrapper around
 * DiagnosticsSnapshotProvider, the same assembly layer watchtower:status
 * renders headlessly. Fetches the snapshot once per render (cached on first
 * access) rather than once per template call, since snapshot() itself issues
 * several queries: ping, buffer, event counter, per-store-view state reads.
 */
class Overview extends Template
{
    /**
     * @var DiagnosticsSnapshot|null
     */
    private ?DiagnosticsSnapshot $snapshot = null;

    /**
     * @param Context $context
     * @param DiagnosticsSnapshotProvider $diagnosticsSnapshotProvider
     * @param SeedCoverageLabel $seedCoverageLabel
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly DiagnosticsSnapshotProvider $diagnosticsSnapshotProvider,
        private readonly SeedCoverageLabel $seedCoverageLabel,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The full diagnostics reading, fetched once and cached for this render.
     *
     * @return DiagnosticsSnapshot
     */
    public function getSnapshot(): DiagnosticsSnapshot
    {
        if ($this->snapshot === null) {
            $this->snapshot = $this->diagnosticsSnapshotProvider->snapshot(new \DateTimeImmutable());
        }

        return $this->snapshot;
    }

    /**
     * Renders a nullable DateTimeImmutable for display, or a fallback label.
     *
     * @param \DateTimeImmutable|null $dateTime
     * @param string $ifNull
     * @return string
     */
    public function formatDateTime(?\DateTimeImmutable $dateTime, string $ifNull = 'never'): string
    {
        if ($dateTime === null) {
            return $ifNull;
        }

        return $dateTime->format(\DateTimeInterface::ATOM);
    }

    /**
     * The merchant-facing status label for a signal, matching the wording the
     * Watchtower dashboard itself uses for the same statuses, or a fallback
     * for one never reported.
     *
     * @param SignalSnapshot $signal
     * @return string
     */
    public function statusLabel(SignalSnapshot $signal): string
    {
        if ($signal->status === null) {
            return 'No data yet';
        }

        return match ($signal->status) {
            SignalStatus::Normal => 'Normal',
            SignalStatus::MildDrop => 'Mild drop',
            SignalStatus::SevereDrop => 'Severe drop',
            SignalStatus::MildSpike => 'Mild spike',
            SignalStatus::SevereSpike => 'Severe spike',
            SignalStatus::InsufficientData => 'Warming up',
        };
    }

    /**
     * The merchant-facing reason label for a signal's last report, or a fallback for one never reported.
     *
     * @param SignalSnapshot $signal
     * @return string
     */
    public function reasonLabel(SignalSnapshot $signal): string
    {
        if ($signal->reason === null) {
            return 'No data yet';
        }

        return match ($signal->reason) {
            ReportReason::Heartbeat => 'Routine check-in',
            ReportReason::Transition => 'Status changed',
        };
    }

    /**
     * Which named checks drove a signal's last raw classification, rendered
     * as e.g. "dispersion, seasonal", or an empty string when there is
     * nothing to attribute (cron_health/integration_health, or a rate-based
     * signal on the inter-arrival/low-volume path).
     *
     * @param SignalSnapshot $signal
     * @return string
     */
    public function ensembleAttributionLabel(SignalSnapshot $signal): string
    {
        return implode(', ', $signal->ensembleDrivingChecks);
    }

    /**
     * A signal's estimated detection latency for a full outage, rendered as
     * e.g. "~19h (low-volume mode)", or an empty string when the signal
     * isn't in Low-Volume Signal Mode -- most signals never show this.
     *
     * @param SignalSnapshot $signal
     * @return string
     */
    public function detectionLatencyLabel(SignalSnapshot $signal): string
    {
        if ($signal->estimatedDetectionLatencyHours === null) {
            return '';
        }

        return sprintf('~%dh (low-volume mode)', (int) ceil($signal->estimatedDetectionLatencyHours));
    }

    /**
     * A signal's local baseline seed coverage in merchant-facing wording
     * (e.g. "cart history seeded: 26 days"), or an empty string when this
     * signal has never been seeded -- not a HistorySeeder category
     * (cron_health/integration_health) or seeding simply hasn't run yet.
     *
     * @param SignalSnapshot $signal
     * @return string
     */
    public function seedCoverageLabel(SignalSnapshot $signal): string
    {
        if ($signal->seedCoverage === null) {
            return '';
        }

        return $this->seedCoverageLabel->describe($signal->seedCoverage);
    }

    /**
     * Whether a store view has a signal genuinely worth a merchant's
     * attention, so its collapsible block can default to expanded -- a real
     * problem should never be hidden behind an extra click. Still building a
     * baseline (InsufficientData) is excluded on purpose: it is expected and
     * temporary, not something wrong, and storeViewWarmingUp() below covers
     * it with a calmer note instead.
     *
     * @param StoreViewSnapshot $storeView
     * @return bool
     */
    public function storeViewNeedsAttention(StoreViewSnapshot $storeView): bool
    {
        foreach ($storeView->signals as $signal) {
            if ($signal->status !== null
                && $signal->status !== SignalStatus::Normal
                && $signal->status !== SignalStatus::InsufficientData) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a store view has a signal still building its baseline and
     * nothing worse -- shown as a calm "Warming up" note rather than the
     * "Needs attention" treatment storeViewNeedsAttention() reserves for an
     * actual anomaly.
     *
     * @param StoreViewSnapshot $storeView
     * @return bool
     */
    public function storeViewWarmingUp(StoreViewSnapshot $storeView): bool
    {
        foreach ($storeView->signals as $signal) {
            if ($signal->status === SignalStatus::InsufficientData) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why a submission's reports were rejected, rendered as e.g.
     * "sequence_number is out of order or already recorded x3" to match
     * watchtower:status's own CLI rendering of the same data.
     *
     * @param SubmissionOutcome $outcome
     * @return string
     */
    public function formatRejectionReasons(SubmissionOutcome $outcome): string
    {
        if ($outcome->rejectionReasons === []) {
            return '';
        }

        $parts = [];
        foreach ($outcome->rejectionReasons as $reason => $count) {
            $parts[] = sprintf('%s x%d', $reason, $count);
        }

        return implode(', ', $parts);
    }
}
