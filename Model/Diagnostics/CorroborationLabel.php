<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Diagnostics;

use Watchtower\Connector\Model\Api\SignalStatus;

/**
 * Merchant-facing text for a signal's corroborating category (see
 * FunnelCorroboration): whether an earlier, full-page-cache-immune funnel
 * step shows real activity while this signal itself is too thin to score.
 */
class CorroborationLabel
{
    private const ACTIVE_STATUSES = [
        SignalStatus::Normal,
        SignalStatus::MildSpike,
        SignalStatus::SevereSpike,
    ];

    private const CATEGORY_LABELS = [
        'basket_quote' => 'cart',
        'checkout' => 'checkout',
        'customer_account' => 'account',
    ];

    /**
     * Empty when the signal isn't INSUFFICIENT_DATA, has no mapped
     * corroborating category, or that category has never been evaluated --
     * silence is preferred over guessing at a claim there's no data for.
     *
     * @param SignalSnapshot $signal
     * @return string
     */
    public function describe(SignalSnapshot $signal): string
    {
        if ($signal->status !== SignalStatus::InsufficientData
            || $signal->corroboratingCategory === null
            || $signal->corroboratingStatus === null
        ) {
            return '';
        }

        $label = self::CATEGORY_LABELS[$signal->corroboratingCategory] ?? $signal->corroboratingCategory;

        if (in_array($signal->corroboratingStatus, self::ACTIVE_STATUSES, true)) {
            return sprintf('%s activity is normal this period. Likely a quiet stretch, not a fault.', ucfirst($label));
        }

        if ($signal->corroboratingStatus === SignalStatus::InsufficientData) {
            return sprintf('No %s activity either. No funnel activity detected this period.', $label);
        }

        return sprintf('%s activity is also down this period.', ucfirst($label));
    }
}
