<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests;

use Koh\SyliusAcp\Feed\ProductFeedBuilder;
use Koh\SyliusAgentContracts\Context\AgentContext;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTranslationInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Product\Model\ProductVariantTranslationInterface;

/**
 * The description an agent reads.
 *
 * It comes out of the editor's rich text, so it arrives with markup, entities
 * and whatever whitespace the paste brought along. An agent consuming JSON has
 * no renderer: markup left in place is not formatting, it is characters in a
 * sentence a model then reasons about. The truncation matters for the same
 * reason — a feed page carries hundreds of these, and an unbounded description
 * field is an unbounded response from a route that needs no credentials.
 *
 * The short description wins over the long one where both exist, which is a
 * choice worth recording: the long one is written for a product page with room
 * to breathe.
 */
final class ProductFeedDescriptionTest extends TestCase
{
    public function testMarkupIsStrippedRatherThanShippedToTheAgent(): void
    {
        self::assertSame(
            'Handgerollt in Kuba.',
            $this->description('<p>Handgerollt in <strong>Kuba</strong>.</p>'),
        );
    }

    public function testRunsOfWhitespaceCollapse(): void
    {
        // A paste from Word arrives with newlines and doubled spaces. Left
        // alone they survive JSON encoding as \n inside the sentence.
        self::assertSame('Eins Zwei Drei', $this->description("Eins\n\n  Zwei\t Drei"));
    }

    public function testAnEmptyDescriptionBecomesNullRatherThanAnEmptyString(): void
    {
        // null is "this product has none". An empty string reads as a
        // description that exists and says nothing.
        self::assertNull($this->description(''));
        self::assertNull($this->description('   '));
        self::assertNull($this->description(null));
    }

    public function testMarkupWithNoTextAtAllAlsoBecomesNull(): void
    {
        // Not tested by the empty case: this one is non-empty until the tags
        // are stripped, so it takes the other branch.
        self::assertNull($this->description('<p> </p>'));
    }

    public function testALongDescriptionIsTruncatedWithAnEllipsis(): void
    {
        $description = $this->description(str_repeat('a', 600));

        self::assertNotNull($description);
        self::assertSame(498, mb_strlen($description), '497 characters plus the ellipsis');
        self::assertStringEndsWith('…', $description);
    }

    public function testADescriptionAtTheLimitIsLeftWhole(): void
    {
        // 500 is not truncated; 501 is. Pinning the boundary rather than the
        // middle is what catches an off-by-one.
        self::assertSame(500, mb_strlen((string) $this->description(str_repeat('a', 500))));
        self::assertSame(498, mb_strlen((string) $this->description(str_repeat('a', 501))));
    }

    public function testTruncationCountsCharactersAndNotBytes(): void
    {
        // mb_substr, not substr: cutting 600 umlauts at byte 497 would split
        // one in half and emit invalid UTF-8, which json_encode then rejects.
        $description = $this->description(str_repeat('ü', 600));

        self::assertNotNull($description);
        self::assertSame(498, mb_strlen($description));
        self::assertNotFalse(json_encode($description));
    }

    public function testTheShortDescriptionWinsWhereBothExist(): void
    {
        self::assertSame('Kurz.', $this->description('Kurz.', longDescription: 'Lang und ausfuehrlich.'));
    }

    public function testTheLongDescriptionStandsInWhenThereIsNoShortOne(): void
    {
        self::assertSame('Lang.', $this->description(null, longDescription: 'Lang.'));
    }

    private function description(?string $shortDescription, ?string $longDescription = null): ?string
    {
        $translation = $this->createStub(ProductTranslationInterface::class);
        $translation->method('getName')->willReturn('Zigarre');
        $translation->method('getSlug')->willReturn('zigarre');
        $translation->method('getShortDescription')->willReturn($shortDescription);
        $translation->method('getDescription')->willReturn($longDescription);

        $variantTranslation = $this->createStub(ProductVariantTranslationInterface::class);
        $variantTranslation->method('getName')->willReturn('VAR-1');

        $pricing = $this->createStub(ChannelPricingInterface::class);
        $pricing->method('getPrice')->willReturn(1250);

        $variant = $this->createStub(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('VAR-1');
        $variant->method('getTranslation')->willReturn($variantTranslation);
        $variant->method('getChannelPricingForChannel')->willReturn($pricing);
        $variant->method('isInStock')->willReturn(true);

        $product = $this->createStub(ProductInterface::class);
        $product->method('getCode')->willReturn('PROD-1');
        $product->method('getTranslation')->willReturn($translation);
        $product->method('getEnabledVariants')
            ->willReturn(new \Doctrine\Common\Collections\ArrayCollection([$variant]));

        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('findLatestByChannel')->willReturn([$product]);

        $feed = (new ProductFeedBuilder($repository, null))->build($this->context());
        $item = $feed['items'][0];

        /** @var ?string $description */
        $description = $item['description'] ?? null;

        return $description;
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
