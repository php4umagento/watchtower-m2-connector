<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\System\Message;

use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;
use Watchtower\Connector\Model\StoreView\IgnoredDomainStateRepository;

/**
 * PRD FR29: a persistent Magento admin notice, same mechanism as
 * ConnectorVersionBelowMinimum, shown while the last sync's ignored_count
 * is above zero. SEVERITY_NOTICE: expected and harmless, not a problem.
 */
class StoreViewsIgnoredAsLocalDomain implements MessageInterface
{
    private const DOCS_URL
        = 'https://watchtower-commerce.com/docs/connecting-magento-2/troubleshooting-no-data-received';

    /**
     * @param IgnoredDomainStateRepository $ignoredDomainStateRepository
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly IgnoredDomainStateRepository $ignoredDomainStateRepository,
        private readonly Escaper $escaper
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getIdentity()
    {
        return 'watchtower_store_views_ignored_as_local_domain';
    }

    /**
     * @inheritdoc
     */
    public function isDisplayed()
    {
        return $this->ignoredDomainStateRepository->get()->ignoredCount > 0;
    }

    /**
     * @inheritdoc
     */
    public function getText()
    {
        $state = $this->ignoredDomainStateRepository->get();

        // MessageInterface::getText() is documented as returning string, not
        // the Phrase __() hands back; rendering it here keeps the contract.
        return (string) __(
            'Watchtower ignored %1 store view(s) on the last sync (e.g. "%2") because their URL looks like '
                . 'a local or development environment, not a live storefront. This is expected outside '
                . 'production and does not affect your other stores. <a href="%3" target="_blank" '
                . 'rel="noopener">Learn more</a>.',
            $state->ignoredCount,
            $this->escaper->escapeHtml($state->exampleCode ?? 'unknown'),
            $this->escaper->escapeUrl(self::DOCS_URL)
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
