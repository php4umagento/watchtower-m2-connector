<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Api;

/**
 * Outcome of a POST /api/installs/metrics call.
 */
class MetricsSubmissionResult
{
    /**
     * The platform returns this rejected[] reason for BOTH a genuinely
     * out-of-order sequence_number and a report it already accepted being
     * resent. Treat it as proof of prior delivery rather than something to
     * retry or to log at the same severity as an unexpected rejection (e.g.
     * an unrecognized store view code).
     */
    public const DEDUP_REJECTION_REASON = 'sequence_number is out of order or already recorded';

    /**
     * @param bool $succeeded
     * @param int $accepted
     * @param array<int,array{event_type:string,sequence_number:int,reason:string,store_view_code?:string}> $rejected
     * @param string|null $errorMessage
     * @param int|null $retryAfterSeconds
     */
    public function __construct(
        public readonly bool $succeeded,
        public readonly int $accepted = 0,
        public readonly array $rejected = [],
        public readonly ?string $errorMessage = null,
        /**
         * Set only for a 429 response; the platform's Retry-After must be
         * honored rather than guessing a backoff interval.
         */
        public readonly ?int $retryAfterSeconds = null,
    ) {
    }
}
