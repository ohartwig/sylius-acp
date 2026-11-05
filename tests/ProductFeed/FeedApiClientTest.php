<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests\ProductFeed;

use Koh\SyliusAcp\ProductFeed\Api\FeedApiClient;
use Koh\SyliusAcp\ProductFeed\Api\FeedApiException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The merchant side of the agent-hosted Feed API (openapi.feed.yaml, ACP
 * 2026-04-17), against a mocked agent.
 */
#[CoversClass(FeedApiClient::class)]
#[CoversClass(FeedApiException::class)]
final class FeedApiClientTest extends TestCase
{
    /** @var list<float> */
    private array $slept = [];

    public function testUpsertSendsTheSpecHeadersAndASchemaValidBody(): void
    {
        $requests = [];
        $client = $this->client([new MockResponse('{"id":"feed_1","accepted":true}')], $requests);
        $products = [['id' => 'P1', 'title' => 'Classic Tee', 'variants' => [['id' => 'V1', 'title' => 'Classic Tee', 'price' => ['amount' => 1990, 'currency' => 'EUR']]]]];

        $response = $client->upsertProducts('feed/1', $products, 'idem-1');

        self::assertSame(['id' => 'feed_1', 'accepted' => true], $response);
        self::assertCount(1, $requests);
        [$method, $url, $options] = $requests[0];
        self::assertSame('PATCH', $method);
        self::assertSame('https://agent.example/acp/feeds/feed%2F1/products', $url);
        $headers = $this->headers($options);
        self::assertSame('Bearer secret-token', $headers['authorization']);
        self::assertSame('2026-04-17', $headers['api-version']);
        self::assertSame('idem-1', $headers['idempotency-key']);
        self::assertSame('application/json', $headers['content-type']);
        self::assertSame('application/json', $headers['accept']);
        self::assertSame(5.0, $options['timeout']);
        $body = json_decode((string) $options['body'], false, 512, \JSON_THROW_ON_ERROR);
        AcpFeedSchema::assertValid($body, 'UpsertProductsRequest');
        self::assertSame(['products' => $products], json_decode((string) $options['body'], true));
    }

    public function testCreateFeedPostsCreateFeedRequest(): void
    {
        $requests = [];
        $client = $this->client([
            new MockResponse('{"id":"feed_8f3K2x","target_country":"DE","updated_at":"2026-03-01T00:00:00Z"}', ['http_code' => 201]),
            new MockResponse('{"id":"feed_9","updated_at":"2026-03-01T00:00:00Z"}', ['http_code' => 200]),
        ], $requests);

        $feed = $client->createFeed('DE', 'idem-create');
        $client->createFeed(null, 'idem-create-2');

        self::assertSame('feed_8f3K2x', $feed['id']);
        self::assertSame('POST', $requests[0][0]);
        self::assertSame('https://agent.example/acp/feeds', $requests[0][1]);
        self::assertSame('{"target_country":"DE"}', $requests[0][2]['body']);
        self::assertSame('idem-create', $this->headers($requests[0][2])['idempotency-key']);
        self::assertSame('{}', $requests[1][2]['body'], 'an empty CreateFeedRequest is an object');
        AcpFeedSchema::assertValid(json_decode('{"target_country":"DE"}'), 'CreateFeedRequest');
        AcpFeedSchema::assertValid($feed, 'FeedMetadata');
    }

    public function testReadsCarryNoBodyAndNoIdempotencyKey(): void
    {
        $requests = [];
        $client = $this->client([
            new MockResponse('{"id":"feed_1"}'),
            new MockResponse('{"products":["junk",{"id":"P1","variants":[]}]}'),
        ], $requests);

        self::assertSame(['id' => 'feed_1'], $client->getFeed('feed_1'));
        self::assertSame([['id' => 'P1', 'variants' => []]], $client->getProducts('feed_1'));

        self::assertSame('GET', $requests[0][0]);
        self::assertSame('https://agent.example/acp/feeds/feed_1', $requests[0][1]);
        self::assertSame('https://agent.example/acp/feeds/feed_1/products', $requests[1][1]);
        $headers = $this->headers($requests[0][2]);
        self::assertArrayNotHasKey('idempotency-key', $headers);
        self::assertArrayNotHasKey('content-type', $headers);
        self::assertSame('2026-04-17', $headers['api-version']);
    }

    public function testTheSpecsErrorShapeBecomesTheException(): void
    {
        $client = $this->client([new MockResponse(
            '{"type":"invalid_request","code":"feed_not_found","message":"Feed not found","param":"id"}',
            ['http_code' => 404],
        )]);

        try {
            $client->getFeed('missing');
            self::fail('expected FeedApiException');
        } catch (FeedApiException $exception) {
            self::assertSame('invalid_request', $exception->type);
            self::assertSame('feed_not_found', $exception->errorCode);
            self::assertSame('Feed not found', $exception->getMessage());
            self::assertSame('id', $exception->param);
            self::assertSame(404, $exception->status);
            self::assertSame('HTTP 404 invalid_request/feed_not_found: Feed not found (param: id)', $exception->describe());
        }
        self::assertSame([], $this->slept, '4xx is not retried');
    }

    public function testAnErrorWithoutParamOrOutsideTheShape(): void
    {
        $client = $this->client([
            new MockResponse('{"type":"invalid_request","code":"invalid_product_payload","message":"bad","param":7}', ['http_code' => 400]),
            new MockResponse('<html>Bad gateway</html>', ['http_code' => 400]),
        ]);

        try {
            $client->upsertProducts('f', [], 'k');
            self::fail('expected FeedApiException');
        } catch (FeedApiException $exception) {
            self::assertNull($exception->param);
            self::assertSame('HTTP 400 invalid_request/invalid_product_payload: bad', $exception->describe());
        }

        try {
            $client->upsertProducts('f', [], 'k');
            self::fail('expected FeedApiException');
        } catch (FeedApiException $exception) {
            self::assertSame('unexpected_response', $exception->type);
            self::assertSame('http_400', $exception->errorCode);
            self::assertSame('PATCH /feeds/f/products answered HTTP 400 without an ACP error object.', $exception->getMessage());
        }
    }

    public function testSomethingShapedAlmostLikeAnErrorIsNotTakenForOne(): void
    {
        foreach ([
            '{"code":"c","message":"m"}',
            '{"type":"t","message":"m"}',
            '{"type":"t","code":"c"}',
            '{"type":1,"code":"c","message":"m"}',
        ] as $body) {
            try {
                $this->client([new MockResponse($body, ['http_code' => 422])])->getFeed('f');
                self::fail('expected FeedApiException');
            } catch (FeedApiException $exception) {
                self::assertSame('http_422', $exception->errorCode, $body);
            }
        }
    }

    public function testRetryAfterMustBeWholeSeconds(): void
    {
        $client = $this->client([
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => ' 4 ']]),
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '5s']]),
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => 'x6']]),
            new MockResponse('{}'),
        ], maxAttempts: 4);

        $client->getFeed('f');

        self::assertSame([4.0, 2.0, 4.0], $this->slept);
    }

    public function testAtLeastOneAttemptIsMade(): void
    {
        $requests = [];
        $client = $this->client([new MockResponse('', ['http_code' => 503])], $requests, maxAttempts: 0);

        try {
            $client->getFeed('f');
            self::fail('expected FeedApiException');
        } catch (FeedApiException $exception) {
            self::assertSame(503, $exception->status);
        }
        self::assertCount(1, $requests);
        self::assertSame([], $this->slept);
    }

    public function testRateLimitsAndServerErrorsAreRetriedWithTheSameIdempotencyKey(): void
    {
        $requests = [];
        $client = $this->client([
            new MockResponse('{"type":"rate_limit_exceeded","code":"too_many","message":"slow down"}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '7']]),
            new MockResponse('', ['http_code' => 503, 'response_headers' => ['Retry-After' => '3600']]),
            new MockResponse('{"id":"f","accepted":true}'),
        ], $requests, maxAttempts: 3);

        $client->upsertProducts('f', [], 'same-key');

        self::assertCount(3, $requests);
        self::assertSame(['same-key', 'same-key', 'same-key'], array_map(fn (array $request): string => $this->headers($request[2])['idempotency-key'], $requests));
        self::assertSame([7.0, 60.0], $this->slept, 'Retry-After honoured, capped at a minute');
    }

    public function testRetriesStopAtMaxAttemptsWithTheLastError(): void
    {
        $client = $this->client([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 502, 'response_headers' => ['Retry-After' => 'Wed, 21 Oct 2015 07:28:00 GMT']]),
            new MockResponse('{"type":"service_unavailable","code":"down","message":"maintenance"}', ['http_code' => 503]),
        ], maxAttempts: 3);

        try {
            $client->getFeed('f');
            self::fail('expected FeedApiException');
        } catch (FeedApiException $exception) {
            self::assertSame('down', $exception->errorCode);
            self::assertSame(503, $exception->status);
        }
        self::assertSame([1.0, 2.0], $this->slept, 'exponential backoff without a usable Retry-After');
    }

    public function testATransportFailureIsRetriedThenReported(): void
    {
        $failure = static fn (): MockResponse => new MockResponse((static function (): \Generator {
            throw new TransportException('connection reset');
            yield '';
        })());
        $client = $this->client([$failure(), $failure()], maxAttempts: 2);

        try {
            $client->getFeed('f');
            self::fail('expected FeedApiException');
        } catch (FeedApiException $exception) {
            self::assertSame('transport_error', $exception->type);
            self::assertSame('request_failed', $exception->errorCode);
            self::assertStringContainsString('GET /feeds/f failed: connection reset', $exception->getMessage());
            self::assertNull($exception->status);
            self::assertStringStartsWith('transport_error/request_failed: ', $exception->describe());
        }
        self::assertSame([1.0], $this->slept);
    }

    public function testAnUpsertTheAgentDidNotAcceptIsAnError(): void
    {
        $client = $this->client([new MockResponse('{"id":"f","accepted":false}')]);

        $this->expectException(FeedApiException::class);
        $this->expectExceptionMessage('The agent did not accept the products');

        $client->upsertProducts('f', [], 'k');
    }

    public function testResponsesThatAreNotJsonObjectsAreErrors(): void
    {
        foreach (['not json', '[1,2]', '"text"', '{"products":"none"}', '{"products":{"a":1}}'] as $body) {
            $client = $this->client([new MockResponse($body)]);
            try {
                str_contains($body, 'products') ? $client->getProducts('f') : $client->getFeed('f');
                self::fail('expected FeedApiException for '.$body);
            } catch (FeedApiException $exception) {
                self::assertSame('unexpected_response', $exception->type);
                self::assertSame(200, $exception->status);
            }
        }

        self::assertSame([], $this->client([new MockResponse('{}')])->getFeed('f'));
        self::assertSame([], $this->client([new MockResponse('[]')])->getFeed('f'));
    }

    public function testConfigurationProblems(): void
    {
        $http = new MockHttpClient([]);
        self::assertSame('koh_acp.acp_feed.api.base_url is empty.', (new FeedApiClient($http, ' ', 't', '2026-04-17'))->configurationProblem());
        self::assertStringContainsString('must be an https:// URL', (string) (new FeedApiClient($http, 'http://agent.example', 't', '2026-04-17'))->configurationProblem());
        self::assertSame('koh_acp.acp_feed.api.bearer_token is empty.', (new FeedApiClient($http, 'https://agent.example', ' ', '2026-04-17'))->configurationProblem());
        self::assertNull((new FeedApiClient($http, 'https://agent.example', 't', '2026-04-17'))->configurationProblem());

        self::assertFalse((new FeedApiClient($http, '', '', '2026-04-17'))->isConfigured());
        self::assertFalse((new FeedApiClient($http, 'https://agent.example', '', '2026-04-17'))->isConfigured());
        self::assertFalse((new FeedApiClient($http, '', 't', '2026-04-17'))->isConfigured());
        self::assertTrue((new FeedApiClient($http, 'https://agent.example', 't', '2026-04-17'))->isConfigured());
        self::assertFalse((new FeedApiClient($http, '  ', 't', '2026-04-17'))->isConfigured());
        self::assertFalse((new FeedApiClient($http, 'https://agent.example', '  ', '2026-04-17'))->isConfigured());
    }

    public function testNothingIsSentWhileUnconfigured(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $url;

            return new MockResponse('{}');
        });

        try {
            (new FeedApiClient($http, 'http://agent.example', 't', '2026-04-17'))->getFeed('f');
            self::fail('expected FeedApiException');
        } catch (FeedApiException $exception) {
            self::assertSame('feed_api_not_configured', $exception->errorCode);
        }
        self::assertSame([], $requests);
    }

    /**
     * @param list<MockResponse>                                  $responses
     * @param list<array{string, string, array<string, mixed>}> $requests
     */
    private function client(array $responses, array &$requests = [], int $maxAttempts = 3): FeedApiClient
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$requests): MockResponse {
            $requests[] = [$method, $url, $options];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 599]);
        });

        return new FeedApiClient($http, ' https://agent.example/acp/ ', ' secret-token ', '2026-04-17', 5.0, $maxAttempts, function (float $seconds): void {
            $this->slept[] = $seconds;
        });
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, string>
     */
    private function headers(array $options): array
    {
        $headers = [];
        foreach ((array) ($options['headers'] ?? []) as $header) {
            [$name, $value] = explode(': ', (string) $header, 2);
            $headers[strtolower($name)] = $value;
        }

        return $headers;
    }
}
