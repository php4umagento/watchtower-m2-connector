<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Diagnostics;

/**
 * One live store view's own signal snapshots, for diagnostics.
 */
class StoreViewSnapshot
{
    /**
     * @param int $storeViewId
     * @param string $storeViewCode
     * @param SignalSnapshot[] $signals basket_quote/checkout/customer_account, plus integration_health when configured
     */
    public function __construct(
        public readonly int $storeViewId,
        public readonly string $storeViewCode,
        public readonly array $signals,
    ) {
    }
}
