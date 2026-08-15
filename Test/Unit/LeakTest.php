<?php

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit;

use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Api\Client;
use Watchtower\Connector\Model\Api\MetricReport;
use Watchtower\Connector\Model\Api\MetricsSubmissionService;
use Watchtower\Connector\Model\Api\ReportReason;
use Watchtower\Connector\Model\Api\Response;
use Watchtower\Connector\Model\Api\SignalStatus;
use Watchtower\Connector\Model\Api\StoreViewSyncService;
use Watchtower\Connector\Model\Organization\OrganizationStateRepository;
use Watchtower\Connector\Model\RateSignal\DispersionEvaluator;
use Watchtower\Connector\Model\RateSignal\DispersionState;
use Watchtower\Connector\Model\RateSignal\DispersionStateRepository;
use Watchtower\Connector\Model\Rollup\HourlyCountSample;
use Watchtower\Connector\Model\Rollup\RollupRepository;
use Watchtower\Connector\Model\StoreView\LiveStoreViewResolver;

/**
 * Nothing this module sends to Watchtower may contain a raw business
 * number, and the API key must never appear anywhere other than the
 * Authorization header. Covers both wire payloads: store-view sync and
 * metric reports.
 */
class LeakTest extends TestCase
{
    use StoreStubTrait;

    private const ALLOWED_SYNC_PAYLOAD_KEYS = ['code', 'name', 'url', 'website_name', 'store_name', 'store_view_id'];

    public function testSyncPayloadContainsOnlyTheDocumentedIdentityFields(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore()]);

        $capturedPayload = null;

        $client = $this->createMock(Client::class);
        $client->expects(self::once())
            ->method('post')
            ->with(
                self::anything(),
                self::anything(),
                '/api/installs/sync',
                self::callback(function (array $payload) use (&$capturedPayload) {
                    $capturedPayload = $payload;

                    return true;
                })
            )
            ->willReturn(new Response(200, ['synced' => [], 'created' => ['default'], 'rejected' => []]));

        $service = new StoreViewSyncService(
            new LiveStoreViewResolver($storeManager),
            $client,
            $this->noOpOrganizationStateRepository(),
            $this->createStub(LoggerInterface::class)
        );
        $service->sync('https://watchtower.test', 'secret-api-key-value');

        self::assertIsArray($capturedPayload);
        self::assertArrayHasKey('store_views', $capturedPayload);
        self::assertCount(1, $capturedPayload['store_views']);

        $entry = $capturedPayload['store_views'][0];

        self::assertSame(
            self::ALLOWED_SYNC_PAYLOAD_KEYS,
            array_keys($entry),
            'The sync payload must contain exactly the identity fields the platform documents -- '
            . 'no additional field (numeric or otherwise) may be added without an explicit leak review.'
        );

        // store_view_id is the one numeric field, and it is Magento's own
        // entity id (identity metadata), never a business count; every
        // other field must be a string.
        foreach (self::ALLOWED_SYNC_PAYLOAD_KEYS as $key) {
            if ($key === 'store_view_id') {
                self::assertIsInt($entry[$key]);

                continue;
            }

            self::assertIsString($entry[$key]);
        }
    }

    public function testApiKeyNeverAppearsInTheSyncRequestBody(): void
    {
        $apiKey = 'a-very-secret-key-that-must-never-leak-into-the-body';

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->activeStore()]);

        $capturedPayload = null;

        $client = $this->createMock(Client::class);
        $client->method('post')
            ->with(
                self::anything(),
                $apiKey,
                self::anything(),
                self::callback(function (array $payload) use (&$capturedPayload) {
                    $capturedPayload = $payload;

                    return true;
                })
            )
            ->willReturn(new Response(200, ['synced' => [], 'created' => ['default'], 'rejected' => []]));

        $service = new StoreViewSyncService(
            new LiveStoreViewResolver($storeManager),
            $client,
            $this->noOpOrganizationStateRepository(),
            $this->createStub(LoggerInterface::class)
        );
        $service->sync('https://watchtower.test', $apiKey);

        $serializedPayload = json_encode($capturedPayload);

        self::assertStringNotContainsString(
            $apiKey,
            $serializedPayload,
            'The API key leaked into the request payload -- it must only ever appear in the Authorization header.'
        );
    }

    private const ALLOWED_METRIC_REPORT_KEYS = [
        'store_view_code', 'event_type', 'status', 'sequence_number', 'evaluated_at', 'reason', 'ruleset_version',
    ];

    public function testMetricReportPayloadContainsOnlyTheDocumentedFieldsWithAnExplicitUtcOffset(): void
    {
        $capturedPayload = null;

        $client = $this->createMock(Client::class);
        $client->expects(self::once())
            ->method('post')
            ->with(
                self::anything(),
                self::anything(),
                '/api/installs/metrics',
                self::callback(function (array $payload) use (&$capturedPayload) {
                    $capturedPayload = $payload;

                    return true;
                })
            )
            ->willReturn(new Response(200, ['accepted' => 1, 'rejected' => []]));

        $report = new MetricReport(
            storeViewCode: null,
            eventType: 'cron_health',
            status: SignalStatus::Normal,
            sequenceNumber: 1,
            evaluatedAt: new \DateTimeImmutable('2026-08-13T15:00:00+00:00'),
            reason: ReportReason::Transition,
            rulesetVersion: '1.0.0',
        );

        $service = new MetricsSubmissionService(
            $client,
            $this->noOpOrganizationStateRepository(),
            $this->createStub(LoggerInterface::class)
        );
        $service->submit('https://watchtower.test', 'secret-api-key-value', [$report]);

        self::assertIsArray($capturedPayload);
        $entry = $capturedPayload['reports'][0];

        self::assertSame(self::ALLOWED_METRIC_REPORT_KEYS, array_keys($entry));
        self::assertMatchesRegularExpression(
            '/(Z|[+-]\d{2}:?\d{2})$/',
            $entry['evaluated_at'],
            'evaluated_at must carry an explicit UTC offset -- InstallMetricsController 422s an offset-less value.'
        );
        self::assertContains($entry['status'], array_map(fn (SignalStatus $s) => $s->value, SignalStatus::cases()));
        self::assertIsInt($entry['sequence_number']);
    }

    public function testApiKeyNeverAppearsInTheMetricReportRequestBody(): void
    {
        $apiKey = 'a-very-secret-key-that-must-never-leak-into-a-metric-report';
        $capturedPayload = null;

        $client = $this->createMock(Client::class);
        $client->method('post')
            ->with(
                self::anything(),
                $apiKey,
                self::anything(),
                self::callback(function (array $payload) use (&$capturedPayload) {
                    $capturedPayload = $payload;

                    return true;
                })
            )
            ->willReturn(new Response(200, ['accepted' => 1, 'rejected' => []]));

        $report = new MetricReport(
            storeViewCode: null,
            eventType: 'cron_health',
            status: SignalStatus::SevereDrop,
            sequenceNumber: 4,
            evaluatedAt: new \DateTimeImmutable('2026-08-13T15:00:00+00:00'),
            reason: ReportReason::Transition,
            rulesetVersion: '1.0.0',
        );

        $service = new MetricsSubmissionService(
            $client,
            $this->noOpOrganizationStateRepository(),
            $this->createStub(LoggerInterface::class)
        );
        $service->submit('https://watchtower.test', $apiKey, [$report]);

        self::assertStringNotContainsString($apiKey, json_encode($capturedPayload));
    }

    /**
     * "Time since last event" is a different disclosure shape from a
     * bucketed count and does not automatically inherit the count-based
     * clearance above, so it gets its own dedicated proof rather than
     * relying on the general MetricReport-shape test to stand in for it.
     * Drives a REAL DispersionEvaluator through a real below-the-volume-
     * floor, gap-exceeding-threshold scenario (the exact fixture shape
     * DispersionEvaluatorTest's own low-volume tests use) so the payload
     * asserted against here is genuinely produced by the inter-arrival
     * code path, not a hand-built MetricReport standing in for it.
     */
    public function testTheInterArrivalPathsWirePayloadIsByteForByteTheSameShapeAsEveryOtherSignals(): void
    {
        $capturedPayload = null;

        $client = $this->createMock(Client::class);
        $client->expects(self::once())
            ->method('post')
            ->with(
                self::anything(),
                self::anything(),
                '/api/installs/metrics',
                self::callback(function (array $payload) use (&$capturedPayload) {
                    $capturedPayload = $payload;

                    return true;
                })
            )
            ->willReturn(new Response(200, ['accepted' => 1, 'rejected' => []]));

        $evaluatedHour = new \DateTimeImmutable('2026-08-13T15:00:00+00:00');

        // A sparse series below the volume floor (observedCount=0) with a
        // gap that exceeds its own historical threshold -- the exact
        // low-volume/SevereDrop shape DispersionEvaluatorTest's own
        // testAZeroCountWithAGapExceedingTheThresholdReportsSevereDrop uses.
        $series = [
            new HourlyCountSample($evaluatedHour->modify('-4 weeks -1 hours'), 100),
            new HourlyCountSample($evaluatedHour->modify('-4 weeks'), 0),
            new HourlyCountSample($evaluatedHour->modify('-3 weeks -1 hours'), 100),
            new HourlyCountSample($evaluatedHour->modify('-3 weeks'), 0),
            new HourlyCountSample($evaluatedHour->modify('-2 weeks -1 hours'), 100),
            new HourlyCountSample($evaluatedHour->modify('-2 weeks'), 0),
            new HourlyCountSample($evaluatedHour->modify('-1 weeks -10 hours'), 100),
            new HourlyCountSample($evaluatedHour->modify('-1 weeks'), 0),
            new HourlyCountSample($evaluatedHour->modify('-9 hours'), 100),
            new HourlyCountSample($evaluatedHour, 0),
        ];

        $rollupRepository = $this->createStub(RollupRepository::class);
        $rollupRepository->method('allHourlyCountsInWindow')->willReturn($series);

        $stateRepository = $this->createStub(DispersionStateRepository::class);
        $stateRepository->method('get')->willReturn(new DispersionState(
            storeViewId: 7,
            category: 'checkout',
            pendingStatus: null,
            confirmedStatus: SignalStatus::SevereDrop,
            sequenceNumber: 3,
        ));

        $evaluator = new DispersionEvaluator($rollupRepository, $stateRepository);
        $report = $evaluator->evaluate(7, 'default', 'checkout', 0, $evaluatedHour, $evaluatedHour);

        self::assertSame(
            SignalStatus::SevereDrop,
            $report->status,
            'Fixture must actually exercise the inter-arrival path.'
        );

        $service = new MetricsSubmissionService(
            $client,
            $this->noOpOrganizationStateRepository(),
            $this->createStub(LoggerInterface::class)
        );
        $service->submit('https://watchtower.test', 'secret-api-key-value', [$report]);

        self::assertIsArray($capturedPayload);
        $entry = $capturedPayload['reports'][0];

        self::assertSame(
            self::ALLOWED_METRIC_REPORT_KEYS,
            array_keys($entry),
            'The inter-arrival path must produce exactly the same wire shape as every other signal -- '
            . 'no raw gap-hours value, timestamp-of-last-event, or percentile may appear on the wire.'
        );
        self::assertIsString($entry['status']);
        self::assertNotSame(
            'SEVERE_DROP_LOW_VOLUME',
            $entry['status'],
            'Mode must never be encoded into the status value itself.'
        );
    }

    /**
     * Mode selection is never transmitted. Confirms by construction, not
     * just by the absence of a field on one sample payload above, that
     * the classes
     * computing the inter-arrival mode switch have no path to the network
     * layer at all. RollupRepository, InterArrivalGapCalculator, and
     * InterArrivalGapResult never reference Client or MetricsSubmission*,
     * so a raw gap value or mode decision literally cannot reach a wire
     * payload except by first being reduced to a SignalStatus -- the same
     * six-value enum every other signal already uses.
     */
    public function testTheLowVolumeModeSwitchHasNoCodePathToTheNetworkLayer(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        $filesToCheck = [
            $moduleRoot . '/Model/RateSignal/InterArrivalGapCalculator.php',
            $moduleRoot . '/Model/RateSignal/InterArrivalGapResult.php',
            $moduleRoot . '/Model/Rollup/RollupRepository.php',
        ];

        foreach ($filesToCheck as $file) {
            self::assertFileExists($file);
            $source = file_get_contents($file);

            self::assertStringNotContainsString(
                'Api\\Client',
                $source,
                "$file must never reference the HTTP client."
            );
            self::assertStringNotContainsString(
                'MetricsSubmission',
                $source,
                "$file must never reference the submission service."
            );
            self::assertDoesNotMatchRegularExpression(
                '/->(?:info|debug|notice|warning|error|critical|alert|emergency|log)\s*\(/i',
                $source,
                "$file must never log anything -- it has no LoggerInterface dependency for a reason."
            );
        }
    }

    /**
     * Static-source guard: nothing in this module may combine a logger call
     * with anything that looks like it is passing the API key, or a raw HTTP
     * response/body, along. Cheap, catches an obvious future regression even
     * before it reaches a runtime test. Deliberately over-inclusive (matches
     * $apiKey, $key, $token, $body, $response, getBody()/getRawKey()-style
     * calls near a logger call); a false positive here just means someone
     * has to rename a nearby variable, which is a fine price for the module
     * never accidentally logging a credential.
     */
    public function testNoSourceFileLogsTheApiKeyOrARawHttpBody(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        $sensitivePattern = '(?:apiKey|api_key|\$key|\$token|rawBody|responseBody|getBody\(\)|\$response(?!\s*=))';
        $offendingFiles = [];

        // .phtml included alongside .php: the admin diagnostics page
        // renders real request/response-derived data, so a template-only
        // leak would otherwise be invisible to this guard.
        $iterator = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($moduleRoot)),
            '/\.(php|phtml)$/'
        );

        foreach ($iterator as $file) {
            if (str_contains((string) $file, '/Test/')) {
                continue;
            }

            $source = file_get_contents((string) $file);

            // Logger calls (info/debug/etc.) AND any direct output sink a
            // CLI command or template could use instead of a logger --
            // $output->writeln() in a Console\Command, or echo/print in a
            // .phtml -- since a leak reaching stdout is exactly as real as
            // one reaching a log file.
            $sinkCallPattern = '/(?:->(?:info|debug|notice|warning|error|critical|alert|emergency|log|writeln)'
                . '\s*\(|\becho\b|\bprint\b)[^;]*' . $sensitivePattern . '/i';

            if (preg_match($sinkCallPattern, $source)) {
                $offendingFiles[] = (string) $file;
            }
        }

        self::assertSame(
            [],
            $offendingFiles,
            'A logger call appears to reference the API key or a raw HTTP body directly.'
        );
    }

    private function noOpOrganizationStateRepository(): OrganizationStateRepository
    {
        $repository = $this->createStub(OrganizationStateRepository::class);
        $repository->method('isPaused')->willReturn(false);

        return $repository;
    }
}
