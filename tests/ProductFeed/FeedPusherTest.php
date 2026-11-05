<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests\ProductFeed;

use Koh\SyliusAcp\ProductFeed\AcpFeedBuilder;
use Koh\SyliusAcp\ProductFeed\AcpProductMapper;
use Koh\SyliusAcp\ProductFeed\Api\FeedApiClient;
use Koh\SyliusAcp\ProductFeed\Api\FeedApiException;
use Koh\SyliusAcp\ProductFeed\CatalogueStream;
use Koh\SyliusAcp\ProductFeed\FeedPusher;
use Koh\SyliusAcp\ProductFeed\PushReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Inventory\Checker\AvailabilityChecker;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(FeedPusher::class)]
#[CoversClass(PushReport::class)]
final class FeedPusherTest extends TestCase
{
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $requests = [];

    public function testTheCatalogueGoesOutInBatchesAfterTheFeedIsConfirmed(): void
    {
        $agent = fn (string $method, string $url): MockResponse => 'GET' === $method
            ? new MockResponse('{"id":"feed_1"}')
            : new MockResponse('{"id":"feed_1","accepted":true}');
        $progress = [];

        $report = $this->pusher($this->products(5), $agent, batchSize: 2)->push(
            FeedFixtures::scope(),
            'feed_1',
            progress: static function (int $count) use (&$progress): void {
                $progress[] = $count;
            },
        );

        self::assertSame([5, 3, 0], [$report->products, $report->requests, $report->retired]);
        self::assertSame([2, 4, 5], $progress);
        self::assertSame(['GET', 'PATCH', 'PATCH', 'PATCH'], array_column($this->requests, 0));
        self::assertSame('https://agent.example/feeds/feed_1', $this->requests[0][1]);

        $keys = [];
        $sent = [];
        foreach (\array_slice($this->requests, 1) as [, $url, $options]) {
            self::assertSame('https://agent.example/feeds/feed_1/products', $url);
            $body = json_decode((string) $options['body'], false, 512, \JSON_THROW_ON_ERROR);
            AcpFeedSchema::assertValid($body, 'UpsertProductsRequest');
            foreach ($body->products as $product) {
                $sent[] = $product->id;
            }
            $keys[] = $this->header($options, 'idempotency-key');
        }
        self::assertSame(['P1', 'P2', 'P3', 'P4', 'P5'], $sent);
        self::assertCount(3, array_unique($keys), 'one key per request');
        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^koh-acp-[0-9a-f]{40}$/', $key);
        }
    }

    public function testAnUnknownFeedStopsBeforeAnyProductIsSent(): void
    {
        $agent = static fn (): MockResponse => new MockResponse('{"type":"invalid_request","code":"feed_not_found","message":"Feed not found","param":"id"}', ['http_code' => 404]);

        try {
            $this->pusher($this->products(2), $agent)->push(FeedFixtures::scope(), 'gone');
            self::fail('expected FeedApiException');
        } catch (FeedApiException $exception) {
            self::assertSame('feed_not_found', $exception->errorCode);
        }
        self::assertCount(1, $this->requests);
    }

    public function testRetireMissingWithdrawsWhatTheShopNoLongerPublishes(): void
    {
        $held = [
            // Still published: left alone.
            ['id' => 'P1', 'variants' => [['id' => 'V1', 'title' => 'V1']]],
            ['title' => 'no id'],
            // Gone and already withdrawn: not sent again.
            ['id' => 'DONE', 'variants' => [['id' => 'D', 'title' => 'D', 'availability' => ['available' => false, 'status' => 'discontinued']]]],
            // Gone from the shop: withdrawn, trimmed to schema fields.
            ['id' => 'OLD', 'title' => 'Old', 'last_update_id' => 'upd_1', 'variants' => [
                ['id' => 'OLD_V', 'title' => 'Old', 'price' => ['amount' => 100, 'currency' => 'EUR'], 'availability' => ['available' => true, 'status' => 'in_stock'], 'agent_note' => 'x'],
                ['id' => 'BROKEN'],
                'junk',
            ]],
            // Gone, nothing to withdraw.
            ['id' => 'EMPTY', 'variants' => []],
        ];
        $agent = static fn (string $method, string $url): MockResponse => match (true) {
            'GET' === $method && str_ends_with($url, '/products') => new MockResponse(json_encode(['products' => $held], \JSON_THROW_ON_ERROR)),
            'GET' === $method => new MockResponse('{"id":"feed_1"}'),
            default => new MockResponse('{"id":"feed_1","accepted":true}'),
        };

        $report = $this->pusher($this->products(1), $agent, batchSize: 1)->push(FeedFixtures::scope(), 'feed_1', retireMissing: true);

        self::assertSame([1, 2, 1], [$report->products, $report->requests, $report->retired]);
        $retired = json_decode((string) $this->requests[3][2]['body'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['products' => [[
            'id' => 'OLD',
            'title' => 'Old',
            'variants' => [[
                'id' => 'OLD_V',
                'title' => 'Old',
                'price' => ['amount' => 100, 'currency' => 'EUR'],
                'availability' => ['available' => false, 'status' => 'discontinued'],
            ]],
        ]]], $retired);
        AcpFeedSchema::assertValid(json_decode((string) $this->requests[3][2]['body'], false), 'UpsertProductsRequest');
    }

    public function testRetiredProductsAreBatchedToo(): void
    {
        $held = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $id) {
            $held[] = ['id' => $id, 'variants' => [['id' => $id.'1', 'title' => $id]]];
        }
        $agent = static fn (string $method, string $url): MockResponse => match (true) {
            'GET' === $method && str_ends_with($url, '/products') => new MockResponse(json_encode(['products' => $held], \JSON_THROW_ON_ERROR)),
            'GET' === $method => new MockResponse('{"id":"feed_1"}'),
            default => new MockResponse('{"id":"feed_1","accepted":true}'),
        };

        $report = $this->pusher([], $agent, batchSize: 2)->push(FeedFixtures::scope(), 'feed_1', retireMissing: true);

        self::assertSame([0, 3, 5], [$report->products, $report->requests, $report->retired]);
    }

    public function testEachRunUsesFreshIdempotencyKeysAndNeedsNoProgressCallback(): void
    {
        $agent = static fn (string $method): MockResponse => 'GET' === $method ? new MockResponse('{"id":"f"}') : new MockResponse('{"id":"f","accepted":true}');
        $pusher = $this->pusher($this->products(3), $agent, batchSize: 2);

        $pusher->push(FeedFixtures::scope(), 'f');
        $pusher->push(FeedFixtures::scope(), 'f');

        $keys = [];
        foreach ($this->requests as [$method, , $options]) {
            if ('PATCH' === $method) {
                $keys[] = $this->header($options, 'idempotency-key');
            }
        }
        self::assertCount(4, $keys);
        self::assertCount(4, array_unique($keys), 'a scheduled re-run of the same catalogue is a new request, not a replay');
    }

    public function testABatchSizeBelowOneSendsOneProductPerRequest(): void
    {
        $agent = static fn (string $method): MockResponse => 'GET' === $method ? new MockResponse('{"id":"f"}') : new MockResponse('{"id":"f","accepted":true}');

        $report = $this->pusher($this->products(2), $agent, batchSize: 0)->push(FeedFixtures::scope(), 'f');

        self::assertSame(2, $report->requests);
    }

    /** @return list<Product> */
    private function products(int $count): array
    {
        $products = [];
        for ($i = 1; $i <= $count; ++$i) {
            $product = FeedFixtures::product('P'.$i, 'Product '.$i);
            FeedFixtures::variant($product, 'P'.$i.'_V');
            $products[] = $product;
        }

        return $products;
    }

    /**
     * @param list<Product>                      $products
     * @param callable(string, string): MockResponse $agent
     */
    private function pusher(array $products, callable $agent, int $batchSize = 100): FeedPusher
    {
        $stream = new class($products) implements CatalogueStream {
            /** @param list<Product> $products */
            public function __construct(private readonly array $products)
            {
            }

            public function products(ChannelInterface $channel, string $localeCode, int $batchSize): iterable
            {
                yield from $this->products;
            }
        };

        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($agent): MockResponse {
            $this->requests[] = [$method, $url, $options];

            return $agent($method, $url);
        });

        return new FeedPusher(
            new AcpFeedBuilder($stream, new AcpProductMapper(new AvailabilityChecker())),
            new FeedApiClient($http, 'https://agent.example', 'token', '2026-04-17', sleep: static function (): void {}),
            $batchSize,
        );
    }

    /** @param array<string, mixed> $options */
    private function header(array $options, string $name): string
    {
        foreach ((array) ($options['headers'] ?? []) as $header) {
            [$key, $value] = explode(': ', (string) $header, 2);
            if (strtolower($key) === $name) {
                return $value;
            }
        }

        return '';
    }
}
