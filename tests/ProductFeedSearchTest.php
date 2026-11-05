<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests;

use Doctrine\Common\Collections\ArrayCollection;
use Koh\SyliusAcp\Controller\ProductFeedController;
use Koh\SyliusAcp\Feed\FeedProductSource;
use Koh\SyliusAcp\Feed\ProductFeedBuilder;
use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTranslationInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Product\Model\ProductVariantTranslationInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * `?q=` is what the WebMCP search tool sends. The feed used to ignore it and
 * answer every search with the latest products, which an agent reads as
 * "these match".
 */
final class ProductFeedSearchTest extends TestCase
{
    private ChannelInterface $channel;

    protected function setUp(): void
    {
        $this->channel = $this->createStub(ChannelInterface::class);
    }

    public function testASearchTermSearchesByNameInTheContextLocale(): void
    {
        $source = $this->createMock(FeedProductSource::class);
        $source->expects(self::once())->method('findPage')
            ->with($this->channel, 'de_DE', 'cigar', 0, 100)
            ->willReturn([$this->product('CIGAR', inChannel: true)]);

        $feed = $this->feed($source, new Request(['q' => '  cigar ']));

        self::assertSame(['CIGAR-V'], array_column($feed['items'], 'id'));
    }

    public function testAnEmptyTermIsTheUnfilteredFeed(): void
    {
        $source = $this->createMock(FeedProductSource::class);
        $source->expects(self::once())->method('findPage')
            ->with($this->channel, 'de_DE', null, 0, 100)
            ->willReturn([]);

        $this->feed($source, new Request(['q' => '   ']));
    }

    public function testALongTermIsCut(): void
    {
        $source = $this->createMock(FeedProductSource::class);
        $source->expects(self::once())->method('findPage')
            ->with($this->channel, 'de_DE', str_repeat('a', 100), 0, 100)
            ->willReturn([]);

        $this->feed($source, new Request(['q' => str_repeat('a', 5000)]));
    }

    /** @return array{items: list<array<string, mixed>>, page: int, limit: int, count: int} */
    private function feed(FeedProductSource $source, Request $request): array
    {
        $factory = $this->createStub(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn(new AgentContext($this->channel, 'https://shop.example.com', 'de_DE', ['de_DE']));

        $response = (new ProductFeedController(new ProductFeedBuilder($source, null), $factory, new AgentJsonResponder()))($request);

        /** @var array{items: list<array<string, mixed>>, page: int, limit: int, count: int} $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function product(string $code, bool $inChannel, bool $enabled = true): ProductInterface
    {
        $translation = $this->createStub(ProductTranslationInterface::class);
        $translation->method('getName')->willReturn($code);
        $translation->method('getSlug')->willReturn(strtolower($code));

        $variantTranslation = $this->createStub(ProductVariantTranslationInterface::class);
        $variantTranslation->method('getName')->willReturn($code);

        $variant = $this->createStub(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn($code.'-V');
        $variant->method('getTranslation')->willReturn($variantTranslation);

        $product = $this->createStub(ProductInterface::class);
        $product->method('getCode')->willReturn($code);
        $product->method('getTranslation')->willReturn($translation);
        $product->method('isEnabled')->willReturn($enabled);
        $product->method('hasChannel')->willReturn($inChannel);
        $product->method('getEnabledVariants')->willReturn(new ArrayCollection([$variant]));

        return $product;
    }
}
