<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Koh\SyliusAcp\Feed\DoctrineFeedProductSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Product;

/**
 * The page is cut by the database: offset and row limit go into the query,
 * and channel and enabled flag are filtered there too, so a search page is
 * never short and never pulls more than one page of rows.
 */
#[CoversClass(DoctrineFeedProductSource::class)]
final class DoctrineFeedProductSourceTest extends TestCase
{
    public function testTheQueryCarriesTheOffsetAndExactlyOnePageOfRows(): void
    {
        $queryBuilder = $this->source()->queryBuilder($this->createStub(ChannelInterface::class), 'de_DE', null, 49_999_500, 500);

        self::assertSame(49_999_500, $queryBuilder->getFirstResult());
        self::assertSame(500, $queryBuilder->getMaxResults());
    }

    public function testOnlyEnabledProductsOfTheChannelInTheLocaleAreSelected(): void
    {
        $channel = $this->createStub(ChannelInterface::class);
        $queryBuilder = $this->source()->queryBuilder($channel, 'de_DE', null, 0, 100);
        $dql = $queryBuilder->getDQL();

        self::assertStringContainsString(':channel MEMBER OF o.channels', $dql);
        self::assertStringContainsString('o.enabled = :enabled', $dql);
        self::assertStringContainsString('translation.locale = :locale', $dql);
        self::assertStringNotContainsString('LIKE', $dql);
        self::assertSame($channel, $queryBuilder->getParameter('channel')?->getValue());
        self::assertTrue($queryBuilder->getParameter('enabled')?->getValue());
        self::assertSame('de_DE', $queryBuilder->getParameter('locale')?->getValue());
    }

    public function testASearchNarrowsByNameAndTreatsWildcardsAsText(): void
    {
        $queryBuilder = $this->source()->queryBuilder($this->createStub(ChannelInterface::class), 'de_DE', '50%_off', 0, 100);

        self::assertStringContainsString('translation.name LIKE :name', $queryBuilder->getDQL());
        self::assertSame('%50\%\_off%', $queryBuilder->getParameter('name')?->getValue());
        self::assertStringContainsString(':channel MEMBER OF o.channels', $queryBuilder->getDQL());
    }

    private function source(): DoctrineFeedProductSource
    {
        $em = $this->createStub(EntityManagerInterface::class);

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturnCallback(
            static fn (string $alias): QueryBuilder => (new QueryBuilder($em))->select($alias)->from(Product::class, $alias),
        );

        return new DoctrineFeedProductSource($repository);
    }
}
