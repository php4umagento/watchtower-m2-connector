<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Api;

/**
 * The platform's own end-of-life determination for this install's reported
 * Magento version/edition, echoed back on a sync response. Computed
 * platform-side (against magento.watch) rather than by the connector itself,
 * so a new Magento release or lifecycle change is visible without a
 * connector update.
 */
class MagentoEolInfo
{
    /**
     * @param bool $isEol
     * @param string|null $eolDate ISO 8601 date, e.g. "2027-04-09"
     * @param string|null $statusLabel platform-supplied label, e.g. "supported"
     */
    public function __construct(
        public readonly bool $isEol,
        public readonly ?string $eolDate,
        public readonly ?string $statusLabel,
    ) {
    }
}
