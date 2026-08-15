<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Api;

use Watchtower\Connector\Model\Organization\OrganizationStateRepository;

/**
 * Calls GET /api/installs/ping. Takes baseUrl/apiKey explicitly rather than
 * reading Model\Config itself, so a caller can test credentials that are not
 * saved yet.
 */
class PingService
{
    private const PATH = '/api/installs/ping';

    /**
     * @param Client $client
     * @param OrganizationStateRepository $organizationStateRepository
     */
    public function __construct(
        private readonly Client $client,
        private readonly OrganizationStateRepository $organizationStateRepository
    ) {
    }

    /**
     * Checks connectivity and key validity against the platform.
     *
     * @param string $baseUrl
     * @param string $apiKey
     * @return PingResult
     */
    public function ping(string $baseUrl, string $apiKey): PingResult
    {
        try {
            $response = $this->client->get($baseUrl, $apiKey, self::PATH);
        } catch (\Throwable $e) {
            return new PingResult(reachable: false, errorMessage: $e->getMessage());
        }

        if ($response->statusCode !== 200) {
            return new PingResult(
                reachable: true,
                httpStatus: $response->statusCode,
                errorMessage: $response->body['message'] ?? null,
            );
        }

        $body = $response->body ?? [];
        $organizationPaused = $body['organization_paused'] ?? null;

        // Authoritative refresh of the cached pause state; the only other
        // source is the absence of a 403 on a metrics submission.
        if ($organizationPaused !== null) {
            $this->organizationStateRepository->save((bool) $organizationPaused, new \DateTimeImmutable());
        }

        return new PingResult(
            reachable: true,
            httpStatus: 200,
            install: $body['install'] ?? null,
            organizationPaused: $organizationPaused,
            serverTime: $body['server_time'] ?? null,
            entitledSignals: $body['entitled_signals'] ?? null,
            alertingEnabled: $body['alerting_enabled'] ?? null,
        );
    }
}
