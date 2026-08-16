<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Api;

use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Environment\ConnectorVersionReader;

/**
 * Calls GET /api/installs/connector-version and compares this install's own
 * version (ConnectorVersionReader) against the platform's response, per PRD
 * "5.8 Connector version check & self-disable" (FR24-FR27).
 *
 * Same-for-every-install, platform-wide config (not per-install data, unlike
 * every other endpoint this module calls), so unlike sync/metrics this is
 * never itself gated on organization_paused -- a paused organization's
 * connector still needs to know it must upgrade.
 *
 * A request failure (network error, non-200) is deliberately NOT itself a
 * reason to self-disable: FR24's own contract says only an explicit
 * minimum_version comparison result does that, so this always returns
 * belowMinimum=false on failure rather than fail closed. The caller
 * (ReportingService) is responsible for keeping the LAST successfully
 * determined state rather than treating a failed check as "recovered".
 */
class ConnectorVersionCheckService
{
    private const PATH = '/api/installs/connector-version';

    /**
     * @param Client $client
     * @param ConnectorVersionReader $connectorVersionReader
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Client $client,
        private readonly ConnectorVersionReader $connectorVersionReader,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Asks the platform for the current minimum/latest version and compares this install against both.
     *
     * @param string $baseUrl
     * @param string $apiKey
     * @return ConnectorVersionCheckResult
     */
    public function check(string $baseUrl, string $apiKey): ConnectorVersionCheckResult
    {
        $installedVersion = $this->connectorVersionReader->version();

        try {
            $response = $this->client->get($baseUrl, $apiKey, self::PATH);
        } catch (\Throwable $e) {
            $this->logger->debug('Watchtower connector-version check raised an exception.', [
                'error' => $e->getMessage(),
            ]);

            return new ConnectorVersionCheckResult(
                succeeded: false,
                installedVersion: $installedVersion,
                errorMessage: $e->getMessage(),
            );
        }

        if ($response->statusCode !== 200) {
            $statusCode = $response->statusCode;
            $this->logger->debug('Watchtower connector-version check failed.', ['statusCode' => $statusCode]);

            return new ConnectorVersionCheckResult(
                succeeded: false,
                installedVersion: $installedVersion,
                errorMessage: $response->body['message'] ?? sprintf('Unexpected HTTP %d.', $statusCode),
            );
        }

        $body = $response->body ?? [];
        $minimumVersion = is_string($body['minimum_version'] ?? null) ? $body['minimum_version'] : null;
        $latestVersion = is_string($body['latest_version'] ?? null) ? $body['latest_version'] : null;

        return new ConnectorVersionCheckResult(
            succeeded: true,
            installedVersion: $installedVersion,
            minimumVersion: $minimumVersion,
            latestVersion: $latestVersion,
            belowMinimum: $this->isBelow($installedVersion, $minimumVersion),
            updateAvailable: $this->isBelow($installedVersion, $latestVersion),
        );
    }

    /**
     * True only when both versions are known and $version is strictly below
     * $threshold -- an inconclusive comparison (either side unknown, e.g. a
     * dev checkout with no Composer version) is never treated as "below".
     *
     * @param string|null $version
     * @param string|null $threshold
     * @return bool
     */
    private function isBelow(?string $version, ?string $threshold): bool
    {
        if ($version === null || $threshold === null) {
            return false;
        }

        return version_compare($version, $threshold, '<');
    }
}
