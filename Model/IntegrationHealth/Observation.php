<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\IntegrationHealth;

/**
 * The freshest success/failure evidence found for one configured
 * integration_health source this tick; either can be null if nothing
 * matching exists in the lookback window. Evaluator combines this with the
 * persisted state to decide the current raw status; this class does no
 * interpretation of its own.
 */
class Observation
{
    /**
     * @param \DateTimeImmutable|null $latestSuccessAt
     * @param \DateTimeImmutable|null $latestFailureAt
     */
    public function __construct(
        public readonly ?\DateTimeImmutable $latestSuccessAt,
        public readonly ?\DateTimeImmutable $latestFailureAt,
    ) {
    }
}
