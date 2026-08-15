<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Api;

/**
 * Outcome of a GET /api/installs/ping call.
 */
class PingResult
{
    /**
     * @param bool $reachable
     * @param int|null $httpStatus
     * @param string|null $install
     * @param bool|null $organizationPaused
     * @param string|null $serverTime
     * @param array|null $entitledSignals
     * @param bool|null $alertingEnabled
     * @param string|null $errorMessage
     */
    public function __construct(
        public readonly bool $reachable,
        public readonly ?int $httpStatus = null,
        public readonly ?string $install = null,
        public readonly ?bool $organizationPaused = null,
        public readonly ?string $serverTime = null,
        public readonly ?array $entitledSignals = null,
        public readonly ?bool $alertingEnabled = null,
        public readonly ?string $errorMessage = null,
    ) {
    }

    /**
     * Distinct from reachable(): the platform can be reachable (a real HTTP
     * response arrived) while still rejecting the key (401) or being rate
     * limited (429); neither of those means "key valid".
     */
    public function keyValid(): bool
    {
        return $this->httpStatus === 200;
    }

    /**
     * Positive when this server's clock is ahead of the platform's.
     *
     * Null when the ping returned no server time, or an unparseable one.
     */
    public function clockSkewSeconds(): ?int
    {
        if ($this->serverTime === null) {
            return null;
        }

        try {
            $serverTime = new \DateTimeImmutable($this->serverTime);
        } catch (\Exception) {
            return null;
        }

        return (new \DateTimeImmutable())->getTimestamp() - $serverTime->getTimestamp();
    }
}
