<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\Api;

/**
 * Raw HTTP outcome of a call to the Watchtower platform, before any
 * endpoint-specific interpretation.
 */
class Response
{
    /**
     * @param int $statusCode
     * @param array|null $body
     * @param int|null $retryAfterSeconds
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly ?array $body,
        /**
         * Parsed Retry-After header, in seconds, when present; null on any
         * response without one. Parsed for every status code even though only
         * a 429 acts on it.
         */
        public readonly ?int $retryAfterSeconds = null,
    ) {
    }
}
