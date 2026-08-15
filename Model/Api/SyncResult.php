<?php

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
     * @param array<int,array{code:string,reason:string}> $rejected
     * @param string|null $errorMessage
     */
    public function __construct(
        public readonly bool $succeeded,
        public readonly array $synced = [],
        public readonly array $created = [],
        public readonly array $rejected = [],
        public readonly ?string $errorMessage = null,
    ) {
    }
}
