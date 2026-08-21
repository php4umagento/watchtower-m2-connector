<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\System\Message;

use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;
use Watchtower\Connector\Model\Environment\ConnectorVersionStateRepository;

/**
 * PRD FR27: a persistent, non-blocking admin notice for "at/above
 * minimum_version but below latest_version" -- distinct from
 * ConnectorVersionBelowMinimum, this never affects sync/metrics
 * submission. Suppressed while belowMinimum is also true: that state
 * already shows its own, more urgent notice for the same underlying gap.
 */
class ConnectorUpdateAvailable implements MessageInterface
{
    /**
     * @param ConnectorVersionStateRepository $connectorVersionStateRepository
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly ConnectorVersionStateRepository $connectorVersionStateRepository,
        private readonly Escaper $escaper
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getIdentity()
    {
        return 'watchtower_connector_update_available';
    }

    /**
     * @inheritdoc
     */
    public function isDisplayed()
    {
        $state = $this->connectorVersionStateRepository->get();

        return $state->updateAvailable && !$state->belowMinimum;
    }

    /**
     * @inheritdoc
     */
    public function getText()
    {
        $state = $this->connectorVersionStateRepository->get();

        return (string) __(
            'A newer version of Watchtower Connector is available: %1 (you have %2). '
                . 'Update when convenient -- reporting is unaffected.',
            $this->escaper->escapeHtml($state->latestVersion ?? 'unknown'),
            $this->escaper->escapeHtml($state->installedVersion ?? 'unknown')
        );
    }

    /**
     * @inheritdoc
     */
    public function getSeverity()
    {
        return self::SEVERITY_NOTICE;
    }
}
