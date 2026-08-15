<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests;

use Koh\SyliusAcp\Controller\ProductFeedController;
use Koh\SyliusAcp\Feed\ProductFeedBuilder;
use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The controller is thin, and the thin part is where a query string meets the
 * builder — the only place in this package where a stranger's input reaches
 * anything, on a route that needs no credentials.
 *
 * The builder is final, and that is treated as a hint rather than an obstacle:
 * these tests drive the real one and read the response, instead of asserting
 * that a doubled builder was called with certain arguments. What a caller can
 * observe is the feed, so that is what is pinned — the same values a broken
 * controller would get wrong, without a test that would keep passing if the
 * builder's own guards were removed.
 *
 * `limit` is passed as null when absent rather than as a number, so
 * default_limit stays owned by the builder alone. Two owners of one cap is how
 * it ends up applying on one path and not the other.
 */
final class ProductFeedControllerTest extends TestCase
{
    public function testItServesTheFeedAsJson(): void
    {
        $response = $this->call(new Request());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function testWithoutParametersTheBuildersDefaultLimitApplies(): void
    {
        $feed = $this->feed(new Request());

        self::assertSame(1, $feed['page']);
        self::assertSame(100, $feed['limit']);
    }

    public function testThePageParameterIsPassedThrough(): void
    {
        self::assertSame(7, $this->feed(new Request(['page' => '7']))['page']);
    }

    public function testAnOversizedLimitIsStillCappedWhenItArrivesThroughTheController(): void
    {
        // The cap lives in the builder. This is the end-to-end proof that the
        // controller does not route around it.
        self::assertSame(500, $this->feed(new Request(['limit' => '100000']))['limit']);
    }

    public function testANegativeLimitCannotBypassTheCapThroughTheController(): void
    {
        // `?limit=-1` reaching the repository as a negative max-result is read
        // by Doctrine as "no limit" — the whole catalogue from a public route.
        self::assertSame(1, $this->feed(new Request(['limit' => '-1']))['limit']);
    }

    public function testANonNumericPageDoesNotReachTheBuilderAsText(): void
    {
        // (int) 'abc' is 0, and the builder raises 0 to 1. What matters is that
        // no string survives the cast — the builder's signature is int.
        self::assertSame(1, $this->feed(new Request(['page' => 'abc']))['page']);
    }

    public function testANegativePageIsRaisedRatherThanOffsettingBackwards(): void
    {
        self::assertSame(1, $this->feed(new Request(['page' => '-5']))['page']);
    }

    public function testAnEmptyLimitIsTreatedAsSuppliedRatherThanAbsent(): void
    {
        // `?limit=` is present-but-empty, which casts to 0 and is clamped to 1.
        // Reading it as "absent" would instead hand back the default of 100 —
        // the two take different paths through the cap, so which one applies is
        // worth stating.
        self::assertSame(1, $this->feed(new Request(['limit' => '']))['limit']);
    }

    public function testTheResponseIsCacheableForFifteenMinutes(): void
    {
        // A catalogue feed behind no credentials is exactly what a cache is
        // for; without it every agent poll reaches the database.
        self::assertSame(900, $this->call(new Request())->getMaxAge());
    }

    /** @return array{items: list<array<string, mixed>>, page: int, limit: int, count: int} */
    private function feed(Request $request): array
    {
        /** @var array{items: list<array<string, mixed>>, page: int, limit: int, count: int} $decoded */
        $decoded = json_decode((string) $this->call($request)->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function call(Request $request): Response
    {
        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('findLatestByChannel')->willReturn([]);

        $factory = $this->createStub(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn($this->context());

        $controller = new ProductFeedController(
            new ProductFeedBuilder($repository, null),
            $factory,
            new AgentJsonResponder(),
        );

        return $controller($request);
    }

    private function context(): AgentContext
    {
        $currency = $this->createStub(CurrencyInterface::class);
        $currency->method('getCode')->willReturn('EUR');

        $channel = $this->createStub(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');
        $channel->method('getName')->willReturn('Tabak Budde');
        $channel->method('getBaseCurrency')->willReturn($currency);

        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']);
    }
}
