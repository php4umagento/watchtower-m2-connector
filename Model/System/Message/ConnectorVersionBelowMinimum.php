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
 * PRD FR26: a persistent Magento admin notice -- the same mechanism Magento
 * itself uses for security-patch/update notices -- stating the installed and
 * required versions, shown for as long as ConnectorVersionStateRepository's
 * persisted belowMinimum flag is true. That flag is only ever set by a real,
 * successful GET /api/installs/connector-version comparison (see
 * ReportingService and that repository's own docblocks), so this reads
 * purely local state and never makes a network call of its own.
 */
class ConnectorVersionBelowMinimum implements MessageInterface
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
        return 'watchtower_connector_below_minimum_version';
    }

    /**
     * @inheritdoc
     */
    public function isDisplayed()
    {
        return $this->connectorVersionStateRepository->get()->belowMinimum;
    }

    /**
     * @inheritdoc
     */
    public function getText()
    {
        $state = $this->connectorVersionStateRepository->get();

        // MessageInterface::getText() is documented as returning string, not
        // the Phrase __() hands back; rendering it here keeps the contract.
        return (string) __(
            'Watchtower Connector is out of date: installed version %1 is below the minimum supported '
                . 'version %2. Reporting is paused until the module is upgraded.',
            $this->escaper->escapeHtml($state->installedVersion ?? 'unknown'),
            $this->escaper->escapeHtml($state->minimumVersion ?? 'unknown')
        );
    }

    /**
     * @inheritdoc
     */
    public function getSeverity()
    {
        return self::SEVERITY_MAJOR;
    }
}
