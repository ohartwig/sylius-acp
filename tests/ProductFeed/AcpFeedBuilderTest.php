<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests\ProductFeed;

use Koh\SyliusAcp\ProductFeed\AcpFeedBuilder;
use Koh\SyliusAcp\ProductFeed\AcpProductMapper;
use Koh\SyliusAcp\ProductFeed\CatalogueStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Inventory\Checker\AvailabilityChecker;

#[CoversClass(AcpFeedBuilder::class)]
final class AcpFeedBuilderTest extends TestCase
{
    public function testProductsWithNothingOnOfferAreDroppedFromTheStream(): void
    {
        $sellable = FeedFixtures::product('SELLABLE');
        FeedFixtures::variant($sellable, 'V1');
        $unpriced = FeedFixtures::product('UNPRICED');
        FeedFixtures::variant($unpriced, 'V2', null);

        $calls = [];
        $stream = new class([$unpriced, $sellable], $calls) implements CatalogueStream {
            /** @param list<mixed> $calls */
            public function __construct(private readonly array $products, private array &$calls)
            {
            }

            public function products(ChannelInterface $channel, string $localeCode, int $batchSize): iterable
            {
                $this->calls[] = [$channel->getCode(), $localeCode, $batchSize];

                yield from $this->products;
            }
        };

        $builder = new AcpFeedBuilder($stream, new AcpProductMapper(new AvailabilityChecker()), 25);
        $products = iterator_to_array($builder->products(FeedFixtures::scope(localeCode: 'en_US')), false);

        self::assertSame(['SELLABLE'], array_column($products, 'id'));
        self::assertSame([['WEB', 'en_US', 25]], $calls);
        foreach ($products as $product) {
            AcpFeedSchema::assertValid($product, 'Product');
        }
    }

    public function testMetadataIsAValidFeedMetadataInUtc(): void
    {
        $builder = new AcpFeedBuilder($this->createStub(CatalogueStream::class), new AcpProductMapper(new AvailabilityChecker()));
        $now = new \DateTimeImmutable('2026-11-05 10:30:00', new \DateTimeZone('Europe/Berlin'));

        $metadata = $builder->metadata('feed_8f3K2x', 'DE', $now);

        self::assertSame(['id' => 'feed_8f3K2x', 'target_country' => 'DE', 'updated_at' => '2026-11-05T09:30:00Z'], $metadata);
        AcpFeedSchema::assertValid($metadata, 'FeedMetadata');

        $withoutCountry = $builder->metadata('feed_8f3K2x', null, $now);
        self::assertSame(['id' => 'feed_8f3K2x', 'updated_at' => '2026-11-05T09:30:00Z'], $withoutCountry);
        AcpFeedSchema::assertValid($withoutCountry, 'FeedMetadata');
    }
}
