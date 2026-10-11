<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Replay;

use Watchtower\Connector\Model\RateSignal\DispersionState;
use Watchtower\Connector\Model\RateSignal\DispersionStateRepository;

/**
 * Array-backed DispersionStateRepository, with the same fresh-state default
 * as the real one when nothing has been saved yet.
 */
class InMemoryDispersionStateRepository extends DispersionStateRepository
{
    /** @var array<string, DispersionState> */
    private array $states = [];

    public function __construct()
    {
    }

    public function get(int $storeViewId, string $category): DispersionState
    {
        return $this->states[$storeViewId . '|' . $category] ?? new DispersionState(
            storeViewId: $storeViewId,
            category: $category,
            pendingStatus: null,
            confirmedStatus: null,
            sequenceNumber: 1,
        );
    }

    public function save(DispersionState $state): void
    {
        $this->states[$state->storeViewId . '|' . $state->category] = $state;
    }
}
