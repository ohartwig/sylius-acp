<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\ProductFeed\Api;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Client for an agent-hosted ACP Feed API (openapi.feed.yaml, ACP 2026-04-17).
 *
 * The merchant calls the agent, never the other way round. Every request
 * carries `Authorization: Bearer`, `API-Version` and JSON; mutating requests
 * carry an `Idempotency-Key`. A retry — after a 429, a 5xx or a broken
 * connection — repeats the same key, so the agent can tell a retry from a new
 * request. Errors in ACP's flat shape become a FeedApiException with the
 * agent's `type`, `code`, `message` and `param`.
 */
final class FeedApiClient
{
    private const JSON_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

    /** Longest Retry-After honoured; a scheduler run should not sleep for an hour. */
    private const MAX_RETRY_AFTER = 60;

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /**
     * @param ?\Closure(float): void $sleep seconds; injectable so tests do not wait
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl,
        #[\SensitiveParameter]
        private readonly string $bearerToken,
        private readonly string $apiVersion,
        private readonly float $timeout = 30.0,
        private readonly int $maxAttempts = 3,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->baseUrl) && '' !== trim($this->bearerToken);
    }

    /** What is missing or wrong in the configuration, or null when it is usable. */
    public function configurationProblem(): ?string
    {
        if ('' === trim($this->baseUrl)) {
            return 'koh_acp.acp_feed.api.base_url is empty.';
        }
        if (1 !== preg_match('#^https://[^/]+#', trim($this->baseUrl))) {
            return 'koh_acp.acp_feed.api.base_url must be an https:// URL (ACP requires HTTPS).';
        }
        if ('' === trim($this->bearerToken)) {
            return 'koh_acp.acp_feed.api.bearer_token is empty.';
        }

        return null;
    }

    /**
     * POST /feeds.
     *
     * @return array<string, mixed> FeedMetadata
     */
    public function createFeed(?string $targetCountry, string $idempotencyKey): array
    {
        $body = null === $targetCountry ? new \stdClass() : ['target_country' => $targetCountry];

        return $this->request('POST', '/feeds', $body, $idempotencyKey, [201, 200]);
    }

    /**
     * GET /feeds/{id}.
     *
     * @return array<string, mixed> FeedMetadata
     */
    public function getFeed(string $feedId): array
    {
        return $this->request('GET', '/feeds/'.rawurlencode($feedId), null, null, [200]);
    }

    /**
     * GET /feeds/{id}/products: the full product set the agent holds.
     *
     * @return list<array<string, mixed>>
     */
    public function getProducts(string $feedId): array
    {
        $response = $this->request('GET', '/feeds/'.rawurlencode($feedId).'/products', null, null, [200]);
        $products = $response['products'] ?? null;
        if (!\is_array($products) || !array_is_list($products)) {
            throw new FeedApiException('unexpected_response', 'invalid_products_response', 'The agent answered without a products list.', status: 200);
        }

        /** @var list<array<string, mixed>> $products */
        return array_values(array_filter($products, 'is_array'));
    }

    /**
     * PATCH /feeds/{id}/products: upsert by Product.id.
     *
     * @param list<array<string, mixed>> $products
     *
     * @return array<string, mixed> UpsertProductsResponse
     */
    public function upsertProducts(string $feedId, array $products, string $idempotencyKey): array
    {
        $response = $this->request('PATCH', '/feeds/'.rawurlencode($feedId).'/products', ['products' => $products], $idempotencyKey, [200]);
        if (true !== ($response['accepted'] ?? null)) {
            throw new FeedApiException('unexpected_response', 'upsert_not_accepted', 'The agent did not accept the products (accepted is not true).', status: 200);
        }

        return $response;
    }

    /**
     * @param array<mixed>|\stdClass|null $body
     * @param list<int>                   $expected
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array|\stdClass|null $body, ?string $idempotencyKey, array $expected): array
    {
        $problem = $this->configurationProblem();
        if (null !== $problem) {
            throw new FeedApiException('invalid_configuration', 'feed_api_not_configured', $problem);
        }

        $headers = [
            'Authorization' => 'Bearer '.trim($this->bearerToken),
            'API-Version' => $this->apiVersion,
            'Accept' => 'application/json',
        ];
        $options = ['headers' => $headers, 'timeout' => $this->timeout];
        if (null !== $body) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($body, self::JSON_FLAGS);
        }
        if (null !== $idempotencyKey) {
            $options['headers']['Idempotency-Key'] = $idempotencyKey;
        }

        $url = rtrim(trim($this->baseUrl), '/').$path;
        $attempts = max(1, $this->maxAttempts);

        for ($attempt = 1; ; ++$attempt) {
            try {
                $response = $this->http->request($method, $url, $options);
                $status = $response->getStatusCode();
                $content = $response->getContent(false);
            } catch (HttpClientException $exception) {
                if ($attempt < $attempts) {
                    ($this->sleep)($this->backoff($attempt));

                    continue;
                }

                throw new FeedApiException('transport_error', 'request_failed', \sprintf('%s %s failed: %s', $method, $path, $exception->getMessage()), previous: $exception);
            }

            if (\in_array($status, $expected, true)) {
                return $this->decode($content, $status);
            }

            if ((429 === $status || $status >= 500) && $attempt < $attempts) {
                ($this->sleep)($this->retryAfter($response) ?? $this->backoff($attempt));

                continue;
            }

            throw $this->error($content, $status, $method, $path);
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $content, int $status): array
    {
        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new FeedApiException('unexpected_response', 'invalid_json', 'The agent answered with invalid JSON.', status: $status, previous: $exception);
        }

        if (!\is_array($decoded) || (array_is_list($decoded) && [] !== $decoded)) {
            throw new FeedApiException('unexpected_response', 'invalid_json', 'The agent answered with something other than a JSON object.', status: $status);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function error(string $content, int $status, string $method, string $path): FeedApiException
    {
        $decoded = json_decode($content, true);
        if (\is_array($decoded)
            && \is_string($decoded['type'] ?? null)
            && \is_string($decoded['code'] ?? null)
            && \is_string($decoded['message'] ?? null)
        ) {
            return new FeedApiException(
                $decoded['type'],
                $decoded['code'],
                $decoded['message'],
                \is_string($decoded['param'] ?? null) ? $decoded['param'] : null,
                $status,
            );
        }

        return new FeedApiException('unexpected_response', 'http_'.$status, \sprintf('%s %s answered HTTP %d without an ACP error object.', $method, $path, $status), status: $status);
    }

    private function retryAfter(ResponseInterface $response): ?float
    {
        $value = $response->getHeaders(false)['retry-after'][0] ?? null;
        if (null === $value || 1 !== preg_match('/^\d+$/', trim($value))) {
            return null;
        }

        return (float) min((int) $value, self::MAX_RETRY_AFTER);
    }

    private function backoff(int $attempt): float
    {
        return (float) (2 ** ($attempt - 1));
    }
}
