<?php

declare(strict_types=1);

namespace Watchtower\Connector\Test\Unit\Model\Api;

use Laminas\Http\Client\Adapter\Test as TestHttpAdapter;
use Magento\Framework\HTTP\LaminasClient;
use Magento\Framework\HTTP\LaminasClientFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Watchtower\Connector\Model\Api\Client;

/**
 * Exercises the real request/response path via Laminas's own Test adapter
 * (queues a canned raw HTTP response instead of hitting the network) rather
 * than mocking Client's internals; this is specifically what proves the
 * Retry-After parsing added for the buffering contract (connector-metrics-
 * spec.md's "Resilience and Buffering Contract") against a real
 * Laminas\Http\Response, not a hand-built stand-in.
 */
class ClientTest extends TestCase
{
    public function testA429ResponseWithANumericRetryAfterIsParsedInSeconds(): void
    {
        $client = $this->clientReturning(
            "HTTP/1.1 429 Too Many Requests\r\nRetry-After: 30\r\nContent-Type: application/json\r\n\r\n{}"
        );

        $response = $client->post('https://watchtower.test', 'secret-key', '/api/installs/metrics', ['reports' => []]);

        self::assertSame(429, $response->statusCode);
        self::assertSame(30, $response->retryAfterSeconds);
    }

    public function testA429ResponseWithAnHttpDateRetryAfterIsConvertedToSeconds(): void
    {
        $future = (new \DateTimeImmutable('+2 minutes'))->setTimezone(new \DateTimeZone('GMT'));
        $client = $this->clientReturning(
            "HTTP/1.1 429 Too Many Requests\r\nRetry-After: {$future->format('D, d M Y H:i:s')} GMT\r\n\r\n{}"
        );

        $response = $client->post('https://watchtower.test', 'secret-key', '/api/installs/metrics', ['reports' => []]);

        // Allow a couple of seconds of test-execution slack rather than
        // asserting an exact value against wall-clock time.
        self::assertGreaterThan(100, $response->retryAfterSeconds);
        self::assertLessThanOrEqual(120, $response->retryAfterSeconds);
    }

    public function testAResponseWithoutARetryAfterHeaderParsesToNull(): void
    {
        $client = $this->clientReturning("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n{\"accepted\":1}");

        $response = $client->post('https://watchtower.test', 'secret-key', '/api/installs/metrics', ['reports' => []]);

        self::assertSame(200, $response->statusCode);
        self::assertNull($response->retryAfterSeconds);
    }

    public function testAnUnparseableRetryAfterValueParsesToNullRatherThanThrowing(): void
    {
        $client = $this->clientReturning("HTTP/1.1 429 Too Many Requests\r\nRetry-After: not-a-real-value\r\n\r\n{}");

        $response = $client->post('https://watchtower.test', 'secret-key', '/api/installs/metrics', ['reports' => []]);

        self::assertNull($response->retryAfterSeconds);
    }

    /**
     * Client logs each request/response at debug level (method, path,
     * status code) -- the lowest layer in the module, so support can
     * see exactly what was attempted without needing a separate, higher-
     * level log line for every single HTTP round-trip. Must never include
     * the API key or a raw body, which is why request/path are logged
     * separately from the response, never together with the key itself.
     */
    public function testLogsTheRequestAndResponseAtDebugLevelWithoutTheApiKey(): void
    {
        $calls = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('debug')->willReturnCallback(
            function (string $message, array $context) use (&$calls): void {
                $calls[] = [$message, $context];
            }
        );

        $laminasClient = new LaminasClient();
        $adapter = new TestHttpAdapter();
        $adapter->setResponse("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n{\"accepted\":1}");
        $laminasClient->setAdapter($adapter);

        $factory = $this->createStub(LaminasClientFactory::class);
        $factory->method('create')->willReturn($laminasClient);

        $client = new Client($factory, new Json(), $logger);
        $client->post('https://watchtower.test', 'a-real-secret-key', '/api/installs/metrics', ['reports' => []]);

        self::assertSame(
            ['Watchtower API request.', ['method' => 'POST', 'path' => '/api/installs/metrics']],
            $calls[0]
        );
        self::assertSame(
            ['Watchtower API response.', ['method' => 'POST', 'path' => '/api/installs/metrics', 'statusCode' => 200]],
            $calls[1]
        );

        foreach ($calls as [$message, $context]) {
            self::assertStringNotContainsString('a-real-secret-key', $message . json_encode($context));
        }
    }

    private function clientReturning(string $rawHttpResponse): Client
    {
        $laminasClient = new LaminasClient();
        $adapter = new TestHttpAdapter();
        $adapter->setResponse($rawHttpResponse);
        $laminasClient->setAdapter($adapter);

        $factory = $this->createStub(LaminasClientFactory::class);
        $factory->method('create')->willReturn($laminasClient);

        return new Client($factory, new Json(), $this->createStub(LoggerInterface::class));
    }
}
