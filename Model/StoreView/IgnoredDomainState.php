<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\StoreView;

/**
 * Snapshot of the singleton row in watchtower_ignored_domain_state: how many
 * store views the last sync rejected with reason_code ignored_local_domain
 * (PRD §5.9), plus one example code for the admin notice to name.
 */
class IgnoredDomainState
{
    /**
     * @param int $ignoredCount 0 when the last sync had no such rejections
     * @param string|null $exampleCode one rejected store view's code, null when $ignoredCount is 0
     * @param \DateTimeImmutable|null $occurredAt when the last sync ran, null if never synced
     */
    public function __construct(
        public readonly int $ignoredCount,
        public readonly ?string $exampleCode,
        public readonly ?\DateTimeImmutable $occurredAt,
    ) {
    }
}
