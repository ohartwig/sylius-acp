<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests;

use Koh\SyliusAcp\Controller\ProductFeedController;
use Koh\SyliusAcp\Feed\FeedProductSource;
use Koh\SyliusAcp\Feed\ProductFeedBuilder;
use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AUD-010-F03: the page number came straight off an anonymous query string,
 * was clamped only from below, and the repository was asked for page × limit
 * rows. ?page=100000&limit=500 loaded the whole catalogue for one response;
 * a value past PHP_INT_MAX / limit ended in a TypeError.
 */
#[CoversClass(ProductFeedBuilder::class)]
final class ProductFeedPaginationTest extends TestCase
{
    /** @var list<array{offset: int, limit: int}> */
    private array $asked = [];

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function pages(): iterable
    {
        yield 'first page' => ['1', 0];
        yield 'second page' => ['2', 500];
        yield 'last allowed page' => ['1000', 999 * 500];
    }

    #[DataProvider('pages')]
    public function testTheSourceIsAskedForExactlyOnePageAtItsOffset(string $page, int $offset): void
    {
        $response = $this->call(['page' => $page, 'limit' => '500']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([['offset' => $offset, 'limit' => 500]], $this->asked);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pagesBeyondTheCap(): iterable
    {
        yield 'far page' => ['100000'];
        yield 'overflowing page' => ['99999999999999999999'];
        yield 'PHP_INT_MAX' => [(string) \PHP_INT_MAX];
    }

    #[DataProvider('pagesBeyondTheCap')]
    public function testAPageBeyondTheCapAnswersAnEmptyListWithoutAQuery(string $page): void
    {
        $response = $this->call(['page' => $page, 'limit' => '500']);

        self::assertSame(200, $response->getStatusCode());
        /** @var array{items: list<mixed>, count: int} $feed */
        $feed = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([], $feed['items']);
        self::assertSame(0, $feed['count']);
        self::assertSame([], $this->asked, 'the database was asked for a page beyond the cap');
    }

    public function testNonsenseAndNegativePagesAreThePageOne(): void
    {
        $this->call(['page' => '-5']);
        $this->call(['page' => 'abc']);

        self::assertSame([['offset' => 0, 'limit' => 100], ['offset' => 0, 'limit' => 100]], $this->asked);
    }

    /** @param array<string, string> $query */
    private function call(array $query): Response
    {
        $source = $this->createStub(FeedProductSource::class);
        $source->method('findPage')->willReturnCallback(function (ChannelInterface $channel, string $locale, ?string $name, int $offset, int $limit): array {
            $this->asked[] = ['offset' => $offset, 'limit' => $limit];

            return [];
        });

        $factory = $this->createStub(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn(new AgentContext(
            $this->createStub(ChannelInterface::class),
            'https://shop.example.com',
            'de_DE',
            ['de_DE'],
        ));

        $controller = new ProductFeedController(
            new ProductFeedBuilder($source, null, 100, 500, 1000),
            $factory,
            new AgentJsonResponder(),
        );

        return $controller(new Request($query));
    }
}
