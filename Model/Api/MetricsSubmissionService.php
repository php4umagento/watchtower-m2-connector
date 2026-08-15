<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Api;

use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Organization\OrganizationStateRepository;

/**
 * Submits a batch of MetricReport to POST /api/installs/metrics: one request
 * per evaluation cycle per install, covering whatever signals produced a report
 * that tick.
 */
class MetricsSubmissionService
{
    private const PATH = '/api/installs/metrics';

    /**
     * @param Client $client
     * @param OrganizationStateRepository $organizationStateRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Client $client,
        private readonly OrganizationStateRepository $organizationStateRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Submits a batch of reports and returns the platform's outcome.
     *
     * @param string $baseUrl
     * @param string $apiKey
     * @param MetricReport[] $reports
     * @return MetricsSubmissionResult
     */
    public function submit(string $baseUrl, string $apiKey, array $reports): MetricsSubmissionResult
    {
        $payload = [
            'reports' => array_map($this->describeReport(...), $reports),
        ];

        $this->logger->debug('Watchtower submitting metric reports.', ['count' => count($reports)]);

        try {
            $response = $this->client->post($baseUrl, $apiKey, self::PATH, $payload);
        } catch (\Throwable $e) {
            $this->logger->debug('Watchtower metrics submission raised an exception.', ['error' => $e->getMessage()]);

            return new MetricsSubmissionResult(succeeded: false, errorMessage: $e->getMessage());
        }

        if ($response->statusCode !== 200) {
            $statusCode = $response->statusCode;
            $this->logger->debug('Watchtower metrics submission failed.', ['statusCode' => $statusCode]);

            // The platform returns 403 only when the organization is paused,
            // so a rejected submission refreshes the cached pause state
            // without waiting for the next ping.
            if ($statusCode === 403) {
                $this->organizationStateRepository->save(true, new \DateTimeImmutable());
            }

            return new MetricsSubmissionResult(
                succeeded: false,
                errorMessage: $response->body['message'] ?? sprintf('Unexpected HTTP %d.', $statusCode),
                retryAfterSeconds: $statusCode === 429 ? $response->retryAfterSeconds : null,
            );
        }

        // A 200 proves the organization is not paused (a 403 would have come
        // back instead), clearing a stale "paused" value cached from an
        // earlier failure.
        $this->organizationStateRepository->save(false, new \DateTimeImmutable());

        $body = $response->body ?? [];
        $accepted = (int) ($body['accepted'] ?? 0);
        $rejected = $body['rejected'] ?? [];

        $this->logger->debug('Watchtower metrics submission succeeded.', [
            'accepted' => $accepted,
            'rejectedCount' => count($rejected),
        ]);

        return new MetricsSubmissionResult(succeeded: true, accepted: $accepted, rejected: $rejected);
    }

    /**
     * Converts a report to the wire shape POST /api/installs/metrics expects.
     *
     * @param MetricReport $report
     * @return array{
     *     store_view_code: ?string,
     *     event_type: string,
     *     status: string,
     *     sequence_number: int,
     *     evaluated_at: string,
     *     reason: string,
     *     ruleset_version: string
     * }
     */
    private function describeReport(MetricReport $report): array
    {
        return [
            'store_view_code' => $report->storeViewCode,
            'event_type' => $report->eventType,
            'status' => $report->status->value,
            'sequence_number' => $report->sequenceNumber,
            // 'P' always emits an explicit numeric offset like "+00:00" rather
            // than a bare "Z"; the platform rejects an offset-less value with
            // a 422 instead of assuming UTC.
            'evaluated_at' => $report->evaluatedAt->format('Y-m-d\TH:i:sP'),
            'reason' => $report->reason->value,
            'ruleset_version' => $report->rulesetVersion,
        ];
    }
}
