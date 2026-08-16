<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Environment;

use Magento\Framework\App\ProductMetadataInterface;

/**
 * Thin wrapper over Magento's own ProductMetadataInterface, so callers depend
 * on this module's own class rather than reaching into the framework
 * directly -- the same reasoning as every other Reader in this module.
 */
class MagentoVersionReader
{
    /**
     * @param ProductMetadataInterface $productMetadata
     */
    public function __construct(
        private readonly ProductMetadataInterface $productMetadata
    ) {
    }

    /**
     * This install's Magento version, e.g. "2.4.9".
     *
     * @return string
     */
    public function version(): string
    {
        return $this->productMetadata->getVersion();
    }

    /**
     * This install's Magento edition, e.g. "Community" or "Enterprise" --
     * Magento's own edition string, not normalized or mapped here. The
     * platform owns the mapping to a magento.watch distribution slug, so
     * this module never has to track that mapping or update it when a new
     * distribution appears.
     *
     * @return string
     */
    public function edition(): string
    {
        return $this->productMetadata->getEdition();
    }
}
