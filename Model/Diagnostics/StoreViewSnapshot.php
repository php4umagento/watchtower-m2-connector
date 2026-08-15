<?php

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
