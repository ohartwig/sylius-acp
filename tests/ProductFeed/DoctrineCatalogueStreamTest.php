<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests\ProductFeed;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ObjectManager;
use Koh\SyliusAcp\ProductFeed\DoctrineCatalogueStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Product;

/**
 * Keyset batches with the entity manager cleared in between: the memory of a
 * full export stays at one batch, and no batch is an OFFSET scan.
 */
#[CoversClass(DoctrineCatalogueStream::class)]
final class DoctrineCatalogueStreamTest extends TestCase
{
    public function testTheQuerySelectsPublishableProductsAfterTheLastIdInIdOrder(): void
    {
        $channel = $this->createStub(ChannelInterface::class);
        $queryBuilder = $this->stream([])->queryBuilder($channel, 'de_DE', 41, 250);
        $dql = $queryBuilder->getDQL();

        self::assertStringContainsString('translation.locale = :locale', $dql);
        self::assertStringContainsString(':channel MEMBER OF o.channels', $dql);
        self::assertStringContainsString('o.enabled = :enabled', $dql);
        self::assertStringContainsString('o.id > :afterId', $dql);
        self::assertStringEndsWith('ORDER BY o.id ASC', $dql);
        self::assertSame(250, $queryBuilder->getMaxResults());
        self::assertSame(0, $queryBuilder->getFirstResult(), 'keyset, not offset');
        self::assertSame(41, $queryBuilder->getParameter('afterId')?->getValue());
        self::assertSame($channel, $queryBuilder->getParameter('channel')?->getValue());
        self::assertSame('de_DE', $queryBuilder->getParameter('locale')?->getValue());
        self::assertTrue($queryBuilder->getParameter('enabled')?->getValue());
    }

    public function testItWalksAllBatchesAndClearsTheManagerAfterEach(): void
    {
        $products = [];
        foreach ([3, 5, 9, 12, 20] as $id) {
            $product = $this->createStub(Product::class);
            $product->method('getId')->willReturn($id);
            $products[] = $product;
        }

        $afterIds = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::exactly(3))->method('clear');
        $stream = $this->stream([[$products[0], $products[1]], [$products[2], $products[3]], [$products[4]]], $manager, $afterIds);

        $seen = iterator_to_array($stream->products($this->createStub(ChannelInterface::class), 'de_DE', 2), false);

        self::assertSame($products, $seen);
        self::assertSame([0, 5, 12], $afterIds);
    }

    public function testAFullLastBatchCostsOneEmptyQueryAndABatchSizeBelowOneIsOne(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn(7);
        $afterIds = [];
        $stream = $this->stream([[$product], []], null, $afterIds, $maxResults);

        self::assertCount(1, iterator_to_array($stream->products($this->createStub(ChannelInterface::class), 'de_DE', 0), false));
        self::assertSame([0, 7], $afterIds);
        self::assertSame(1, $maxResults);
    }

    /**
     * @param list<list<Product>> $batches
     * @param list<int>           $afterIds
     */
    private function stream(array $batches, ?ObjectManager $manager = null, array &$afterIds = [], ?int &$maxResults = null): DoctrineCatalogueStream
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturnCallback(
            function (string $alias) use ($em, &$batches, &$afterIds, &$maxResults): QueryBuilder {
                $queryBuilder = $this->getMockBuilder(QueryBuilder::class)
                    ->setConstructorArgs([$em])
                    ->onlyMethods(['getQuery'])
                    ->getMock();
                $queryBuilder->method('getQuery')->willReturnCallback(
                    function () use ($queryBuilder, &$batches, &$afterIds, &$maxResults): Query {
                        $afterIds[] = $queryBuilder->getParameter('afterId')?->getValue();
                        $maxResults = $queryBuilder->getMaxResults();
                        $query = $this->createStub(Query::class);
                        $query->method('getResult')->willReturn(array_shift($batches) ?? []);

                        return $query;
                    },
                );

                return $queryBuilder->select($alias)->from(Product::class, $alias);
            },
        );

        return new DoctrineCatalogueStream($repository, $manager);
    }
}
