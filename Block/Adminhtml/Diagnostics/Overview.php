<?php

declare(strict_types=1);

namespace Watchtower\Connector\Block\Adminhtml\Diagnostics;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Watchtower\Connector\Model\Diagnostics\DiagnosticsSnapshot;
use Watchtower\Connector\Model\Diagnostics\DiagnosticsSnapshotProvider;
use Watchtower\Connector\Model\Diagnostics\SignalSnapshot;
use Watchtower\Connector\Model\Diagnostics\SubmissionOutcome;

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
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly DiagnosticsSnapshotProvider $diagnosticsSnapshotProvider,
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
     * The status label for a signal, or a fallback for one never reported.
     *
     * @param SignalSnapshot $signal
     * @return string
     */
    public function statusLabel(SignalSnapshot $signal): string
    {
        return $signal->status?->value ?? 'no data yet';
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
