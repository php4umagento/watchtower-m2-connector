<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

declare(strict_types=1);

namespace Watchtower\Connector\Model\Api;

use Laminas\Http\Client\Adapter\Curl as LaminasCurlAdapter;
use Laminas\Http\Request;
use Magento\Framework\HTTP\LaminasClientFactory;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Thin HTTP client for the Watchtower platform API. Every request carries the
 * install-scoped bearer key; this class must never log the key or any request
 * or response body.
 *
 * Logs at debug level only (method, path, status code). Magento persists debug
 * entries only while "Enable Debug Logging" (Stores > Configuration > Advanced
 * > Developer > Debug) is on, which doubles as this module's verbosity switch
 * for support escalations.
 */
class Client
{
    /**
     * @param LaminasClientFactory $httpClientFactory
     * @param SerializerInterface $serializer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly LaminasClientFactory $httpClientFactory,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Issues a GET request to the platform.
     *
     * @param string $baseUrl
     * @param string $apiKey
     * @param string $path
     * @return Response
     * @throws \Laminas\Http\Client\Exception\ExceptionInterface on a network-level
     *         failure (DNS, connection refused, timeout); no HTTP response at all
     */
    public function get(string $baseUrl, string $apiKey, string $path): Response
    {
        return $this->request(Request::METHOD_GET, $baseUrl, $apiKey, $path);
    }

    /**
     * Issues a POST request to the platform.
     *
     * @param string $baseUrl
     * @param string $apiKey
     * @param string $path
     * @param array $payload
     * @return Response
     * @throws \Laminas\Http\Client\Exception\ExceptionInterface on a network-level
     *         failure (DNS, connection refused, timeout); no HTTP response at all
     */
    public function post(string $baseUrl, string $apiKey, string $path, array $payload): Response
    {
        return $this->request(Request::METHOD_POST, $baseUrl, $apiKey, $path, $payload);
    }

    /**
     * Builds and sends the underlying HTTP request, common to get() and post().
     *
     * @param string $method
     * @param string $baseUrl
     * @param string $apiKey
     * @param string $path
     * @param array|null $payload
     * @return Response
     */
    private function request(
        string $method,
        string $baseUrl,
        string $apiKey,
        string $path,
        ?array $payload = null
    ): Response {
        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Accept' => 'application/json',
        ];

        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        // Magento\Framework\HTTP\LaminasClient hardcodes its own legacy
        // Magento\Framework\HTTP\Adapter\Curl, which on Magento <=2.4.7
        // passes associative headers straight to CURLOPT_HTTPHEADER
        // without converting them to "Name: value" strings first -- curl
        // silently drops them, including Authorization, so every request
        // 401s. Fixed upstream in 2.4.8's Adapter\Curl::normalizeHeaders().
        // Laminas's own Curl adapter has always formatted headers
        // correctly, so overriding the adapter here fixes every Magento
        // version without touching Magento's vendor code. Passed through
        // the factory's own options (not a later setAdapter() call) so
        // Laminas\Http\Client::getAdapter() lazily builds it only if
        // nothing else has already set one -- a caller providing its own
        // pre-configured client (e.g. a test double) is left untouched.
        $client = $this->httpClientFactory->create([
            'options' => ['adapter' => LaminasCurlAdapter::class],
        ]);
        $client->setUri(rtrim($baseUrl, '/') . $path);
        $client->setMethod($method);
        $client->setHeaders($headers);

        if ($payload !== null) {
            $client->setRawBody($this->serializer->serialize($payload));
        }

        $this->logger->debug('Watchtower API request.', ['method' => $method, 'path' => $path]);

        $response = $client->send();
        $statusCode = $response->getStatusCode();

        $this->logger->debug('Watchtower API response.', [
            'method' => $method,
            'path' => $path,
            'statusCode' => $statusCode,
        ]);

        $body = null;
        $rawBody = $response->getBody();
        if ($rawBody !== '') {
            try {
                $decoded = $this->serializer->unserialize($rawBody);
                $body = is_array($decoded) ? $decoded : null;
            } catch (\InvalidArgumentException) {
                $body = null;
            }
        }

        return new Response($statusCode, $body, $this->retryAfterSeconds($response));
    }

    /**
     * Retry-After (RFC 7231 §7.1.3) is either delta-seconds (a plain
     * integer) or an HTTP-date. Try the common numeric case first, fall
     * back to date parsing, and give up cleanly (null) rather than guessing
     * on anything unparseable.
     *
     * @param \Laminas\Http\Response $response
     * @return int|null
     */
    private function retryAfterSeconds(\Laminas\Http\Response $response): ?int
    {
        $header = $response->getHeaders()->get('Retry-After');
        if ($header === false) {
            return null;
        }

        // Headers::get() returns an ArrayIterator instead of a single header
        // object if the response somehow repeated the header; take the
        // first occurrence rather than fatal on a non-standard response.
        if ($header instanceof \ArrayIterator) {
            $header = $header->current();
            if ($header === false || $header === null) {
                return null;
            }
        }

        // Laminas's own RetryAfter header class parses a delta-seconds
        // value into an int at getFieldValue() time (not always a string,
        // despite the interface's usual contract); cast explicitly rather
        // than pass a possibly-int value into ctype_digit(), which is
        // deprecated in PHP 8.3 for non-string input.
        $value = (string) $header->getFieldValue();
        if (ctype_digit($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? max(0, $timestamp - time()) : null;
    }
}
