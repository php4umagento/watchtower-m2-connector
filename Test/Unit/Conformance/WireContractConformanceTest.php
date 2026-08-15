<?php

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Conformance;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Api\Client;
use Watchtower\Connector\Model\Api\MetricReport;
use Watchtower\Connector\Model\Api\MetricsSubmissionResult;
use Watchtower\Connector\Model\Api\MetricsSubmissionService;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\Response;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Organization\OrganizationStateRepository;

/**
 * Locks the wire contract itself: the exact field set, PHP types, and
 * string formats MetricsSubmissionService::describeReport() puts on the
 * network, plus the literal wording of DEDUP_REJECTION_REASON.
 *
 * Deliberately does NOT re-cover ground owned elsewhere: Retry-After
 * header parsing (ClientTest.php), 429/403 response handling
 * (MetricsSubmissionServiceTest.php), and the 500-report batch cap's
 * boundary behavior (ReportingServiceTest.php).
 */
class WireContractConformanceTest extends TestCase
{
    /**
     * Each report in the request body carries exactly these 7 fields,
     * no more, no fewer.
     */
    public function testWirePayloadForEachReportContainsExactlyTheSevenDocumentedFields(): void
    {
        $payload = $this->submitAndCapturePayload($this->report());

        self::assertSame(
            ['store_view_code', 'event_type', 'status', 'sequence_number', 'evaluated_at', 'reason', 'ruleset_version'],
            array_keys($payload['reports'][0])
        );
    }

    /**
     * `evaluated_at` must include an explicit UTC offset; an offset-less
     * value is rejected by the platform with a 422, not silently assumed
     * to be UTC. The connector uses PHP's 'P' format specifier (not
     * DateTimeInterface::ATOM's bare "Z") so the offset is always the
     * explicit numeric form the platform's evaluatedAtRules() expects --
     * see MetricsSubmissionService::describeReport()'s own comment.
     */
    public function testEvaluatedAtCarriesAnExplicitNumericUtcOffsetNeverAnOffsetlessValue(): void
    {
        $payload = $this->submitAndCapturePayload(
            $this->report(evaluatedAt: new \DateTimeImmutable('2026-08-14T09:30:00+00:00'))
        );

        self::assertSame('2026-08-14T09:30:00+00:00', $payload['reports'][0]['evaluated_at']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $payload['reports'][0]['evaluated_at']
        );
    }

    /**
     * `sequence_number` is an integer on the wire and must fit in an
     * unsigned 32-bit integer. A silent downstream cast to string or
     * float would still round-trip through
     * many test assertions unnoticed, but a real HTTP client serializes
     * a PHP int and a numeric string to JSON differently -- this locks the
     * actual PHP type reaching the payload, not just its printed value.
     */
    public function testSequenceNumberIsSerializedAsAnIntegerNotAString(): void
    {
        $payload = $this->submitAndCapturePayload($this->report(sequenceNumber: 42));

        self::assertSame(42, $payload['reports'][0]['sequence_number']);
        self::assertIsInt($payload['reports'][0]['sequence_number']);
    }

    /**
     * A dedup rejection shares the SAME reason string as a genuinely
     * out-of-order report: "sequence_number is out of order or already
     * recorded". The connector depends on this exact literal to
     * distinguish a confirmed-prior-delivery rejection from a genuine
     * failure, so it is asserted here against the documented wording
     * rather than only against itself.
     */
    public function testDedupRejectionReasonConstantMatchesThePlatformsExactLiteralWording(): void
    {
        self::assertSame(
            'sequence_number is out of order or already recorded',
            MetricsSubmissionResult::DEDUP_REJECTION_REASON
        );
    }

    /**
     * `status`/`reason`/`ruleset_version` are all plain strings on the
     * wire, not the connector's own
     * internal SignalStatus/ReportReason enum instances -- describeReport()
     * must resolve ->value before the payload is serialized.
     */
    public function testStatusAndReasonAreSerializedAsTheirStringValuesNotEnumInstances(): void
    {
        $payload = $this->submitAndCapturePayload(
            $this->report(status: SignalStatus::SevereDrop, reason: ReportReason::Transition)
        );

        self::assertSame('SEVERE_DROP', $payload['reports'][0]['status']);
        self::assertIsString($payload['reports'][0]['status']);
        self::assertSame('transition', $payload['reports'][0]['reason']);
        self::assertIsString($payload['reports'][0]['reason']);
    }

    /**
     * @param MetricReport $report
     * @return array{reports: array<int, array<string, mixed>>}
     */
    private function submitAndCapturePayload(MetricReport $report): array
    {
        $captured = null;
        $client = $this->createStub(Client::class);
        $client->method('post')->willReturnCallback(
            function (string $baseUrl, string $apiKey, string $path, array $payload) use (&$captured) {
                $captured = $payload;

                return new Response(200, ['accepted' => 1, 'rejected' => []]);
            }
        );

        $service = new MetricsSubmissionService(
            $client,
            $this->createStub(OrganizationStateRepository::class),
            $this->createStub(LoggerInterface::class)
        );
        $service->submit('https://watchtower.test', 'a-key', [$report]);

        return $captured;
    }

    private function report(
        ?SignalStatus $status = null,
        ?int $sequenceNumber = null,
        ?\DateTimeImmutable $evaluatedAt = null,
        ?ReportReason $reason = null,
    ): MetricReport {
        return new MetricReport(
            storeViewCode: 'default',
            eventType: 'checkout',
            status: $status ?? SignalStatus::Normal,
            sequenceNumber: $sequenceNumber ?? 1,
            evaluatedAt: $evaluatedAt ?? new \DateTimeImmutable('2026-08-14T09:00:00+00:00'),
            reason: $reason ?? ReportReason::Heartbeat,
            rulesetVersion: '1.0.1',
        );
    }
}
