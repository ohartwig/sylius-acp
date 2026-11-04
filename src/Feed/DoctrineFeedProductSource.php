<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Feed;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;

/**
 * The feed's product query over Sylius's product repository.
 *
 * Sylius's own findLatestByChannel() and findByNamePart() take a count but no
 * offset, so this builds the same query with both. Channel and enabled flag
 * are applied in SQL for the search too, so a page is never short because
 * filtering happened after the limit.
 */
final class DoctrineFeedProductSource implements FeedProductSource
{
    /** @param EntityRepository<ProductInterface> $products */
    public function __construct(private readonly EntityRepository $products)
    {
    }

    public function findPage(ChannelInterface $channel, string $localeCode, ?string $nameQuery, int $offset, int $limit): array
    {
        /** @var list<ProductInterface> $products */
        $products = $this->queryBuilder($channel, $localeCode, $nameQuery, $offset, $limit)->getQuery()->getResult();

        return $products;
    }

    public function queryBuilder(ChannelInterface $channel, string $localeCode, ?string $nameQuery, int $offset, int $limit): QueryBuilder
    {
        // The translation join is filtered to one locale, so there is at most
        // one translation row per product and the row limit is a product limit.
        $queryBuilder = $this->products->createQueryBuilder('o')
            ->addSelect('translation')
            ->innerJoin('o.translations', 'translation', 'WITH', 'translation.locale = :locale')
            ->andWhere(':channel MEMBER OF o.channels')
            ->andWhere('o.enabled = :enabled')
            ->setParameter('locale', $localeCode)
            ->setParameter('channel', $channel)
            ->setParameter('enabled', true)
            ->addOrderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit));

        if (null !== $nameQuery && '' !== $nameQuery) {
            $queryBuilder
                ->andWhere('translation.name LIKE :name')
                ->setParameter('name', '%'.addcslashes($nameQuery, '%_\\').'%');
        }

        return $queryBuilder;
    }
}
