<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\HealthState;

use Watchtower\Connector\Model\Api\SignalStatus;

/**
 * Plain snapshot of one row in watchtower_health_state; a value object
 * rather than a full Magento Model/ResourceModel/Collection triad, since
 * this table has no admin grid, no EAV, and one caller.
 */
class HealthState
{
    /**
     * @param string $eventType
     * @param \DateTimeImmutable|null $lastSuccessAt
     * @param \DateTimeImmutable|null $lastFailureAt
     * @param SignalStatus|null $pendingStatus
     * @param SignalStatus|null $confirmedStatus
     * @param int $sequenceNumber
     */
    public function __construct(
        public readonly string $eventType,
        public readonly ?\DateTimeImmutable $lastSuccessAt,
        public readonly ?\DateTimeImmutable $lastFailureAt,
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
