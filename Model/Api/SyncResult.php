<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Api;

/**
 * Outcome of a POST /api/installs/sync call. An accepted store view is either
 * matched to an existing one ("synced") or created fresh ("created"); a
 * rejected one (soft-deleted on the platform, or a new one over the account's
 * allowance) is never retried as a failure.
 */
class SyncResult
{
    /**
     * @param bool $succeeded
     * @param string[] $synced
     * @param string[] $created
     * @param array<int,array{code:string,reason:string,reason_code?:string}> $rejected reason_code is only ever
     *     present for reasons the platform has given a stable machine meaning to (PRD §5.9) -- currently just
     *     "ignored_local_domain"; absent means "no special handling," never an error
     * @param string|null $errorMessage
     * @param MagentoEolInfo|null $magentoEol null when the platform couldn't determine EOL status
     *     (this request didn't report a Magento version/edition, or the platform's own lookup failed)
     */
    public function __construct(
        public readonly bool $succeeded,
        public readonly array $synced = [],
        public readonly array $created = [],
        public readonly array $rejected = [],
        public readonly ?string $errorMessage = null,
        public readonly ?MagentoEolInfo $magentoEol = null,
    ) {
    }
}
