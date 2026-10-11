<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Block\Adminhtml\Diagnostics;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Watchtower\Connector\Model\Diagnostics\DiagnosticsSnapshot;
use Watchtower\Connector\Model\Diagnostics\DiagnosticsSnapshotProvider;
use Watchtower\Connector\Model\Diagnostics\PlainLanguage;
use Watchtower\Connector\Model\Diagnostics\PlainStatus;

/**
 * Backs the diagnostics page: a read-only, plain-language view of the same
 * snapshot watchtower:status renders for engineers. Fetches the snapshot once
 * per render, since snapshot() itself issues several queries.
 */
class Overview extends Template
{
    private const MESSAGE_CLASS = [
        PlainStatus::GOOD => 'message-success',
        PlainStatus::INFO => 'message-info',
        PlainStatus::WARN => 'message-warning',
        PlainStatus::BAD => 'message-error',
    ];

    private const BADGE_CLASS = [
        PlainStatus::GOOD => 'grid-severity-notice',
        PlainStatus::INFO => 'grid-severity-minor',
        PlainStatus::WARN => 'grid-severity-major',
        PlainStatus::BAD => 'grid-severity-critical',
    ];

    /**
     * @var DiagnosticsSnapshot|null
     */
    private ?DiagnosticsSnapshot $snapshot = null;

    /**
     * @param Context $context
     * @param DiagnosticsSnapshotProvider $diagnosticsSnapshotProvider
     * @param PlainLanguage $plainLanguage
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly DiagnosticsSnapshotProvider $diagnosticsSnapshotProvider,
        private readonly PlainLanguage $plainLanguage,
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
            $this->snapshot = $this->diagnosticsSnapshotProvider->snapshot(new \DateTimeImmutable(), 1);
        }

        return $this->snapshot;
    }

    /**
     * The plain-language presenter the template renders through.
     *
     * @return PlainLanguage
     */
    public function plain(): PlainLanguage
    {
        return $this->plainLanguage;
    }

    /**
     * The instant the page is rendered at, for "minutes ago" wording.
     *
     * @return \DateTimeImmutable
     */
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    /**
     * The Magento message-box class for a status.
     *
     * @param PlainStatus $status
     * @return string
     */
    public function messageClass(PlainStatus $status): string
    {
        return self::MESSAGE_CLASS[$status->tone];
    }

    /**
     * The Magento severity-badge class for a status.
     *
     * @param PlainStatus $status
     * @return string
     */
    public function badgeClass(PlainStatus $status): string
    {
        return self::BADGE_CLASS[$status->tone];
    }
}
