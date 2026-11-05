<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\ProductFeed;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ObjectManager;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;

/**
 * Keyset-paginated walk over the product table, ordered by id.
 *
 * Keyset rather than offset: an offset makes the database skip every row
 * before the page again, so the last batch of a large catalogue costs as much
 * as reading all of it. After each batch the entity manager is cleared, which
 * is what keeps memory flat — Doctrine otherwise holds every product, variant,
 * translation and image it ever loaded.
 */
final class DoctrineCatalogueStream implements CatalogueStream
{
    /** @param EntityRepository<ProductInterface> $products */
    public function __construct(
        private readonly EntityRepository $products,
        private readonly ?ObjectManager $manager = null,
    ) {
    }

    public function products(ChannelInterface $channel, string $localeCode, int $batchSize): iterable
    {
        $batchSize = max(1, $batchSize);
        $afterId = 0;

        do {
            /** @var list<ProductInterface> $batch */
            $batch = $this->queryBuilder($channel, $localeCode, $afterId, $batchSize)->getQuery()->getResult();

            foreach ($batch as $product) {
                $afterId = max($afterId, (int) $product->getId());

                yield $product;
            }

            $this->manager?->clear();
        } while (\count($batch) === $batchSize);
    }

    public function queryBuilder(ChannelInterface $channel, string $localeCode, int $afterId, int $batchSize): QueryBuilder
    {
        // The translation join is filtered to one locale, so there is at most
        // one row per product and the row limit is a product limit.
        return $this->products->createQueryBuilder('o')
            ->addSelect('translation')
            ->innerJoin('o.translations', 'translation', 'WITH', 'translation.locale = :locale')
            ->andWhere(':channel MEMBER OF o.channels')
            ->andWhere('o.enabled = :enabled')
            ->andWhere('o.id > :afterId')
            ->setParameter('locale', $localeCode)
            ->setParameter('channel', $channel)
            ->setParameter('enabled', true)
            ->setParameter('afterId', $afterId)
            ->orderBy('o.id', 'ASC')
            ->setMaxResults($batchSize);
    }
}
