<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\RateSignal;

use Watchtower\Connector\Model\Api\SignalStatus;

/**
 * Plain snapshot of one row in watchtower_dispersion_state, keyed by
 * (store_view_id, category) rather than cron_health's single event_type,
 * since Check A is evaluated per store view.
 */
class DispersionState
{
    /**
     * @param int $storeViewId
     * @param string $category
     * @param SignalStatus|null $pendingStatus
     * @param SignalStatus|null $confirmedStatus
     * @param int $sequenceNumber
     */
    public function __construct(
        public readonly int $storeViewId,
        public readonly string $category,
        public readonly ?SignalStatus $pendingStatus,
        public readonly ?SignalStatus $confirmedStatus,
        public readonly int $sequenceNumber,
    ) {
    }

    /**
     * Whether this is a fresh state with no prior confirmed status.
     *
     * @return bool
     */
    public function isFirstEvaluation(): bool
    {
        return $this->confirmedStatus === null;
    }
}
