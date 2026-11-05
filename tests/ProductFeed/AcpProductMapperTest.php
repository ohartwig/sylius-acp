<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests\ProductFeed;

use Koh\SyliusAcp\ProductFeed\AcpProductMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Inventory\Checker\AvailabilityChecker;

/**
 * Sylius product → ACP `Product` / `Variant`, every result validated against
 * schema.feed.json of ACP 2026-04-17.
 */
#[CoversClass(AcpProductMapper::class)]
final class AcpProductMapperTest extends TestCase
{
    public function testAFullProductMapsToTheSpecShape(): void
    {
        $product = FeedFixtures::product();
        $product->setShortDescription('Short.');
        $colour = FeedFixtures::option($product, 'colour', 'Farbe');
        $size = FeedFixtures::option($product, 'size', 'Größe');
        $red = FeedFixtures::variant($product, 'CLASSIC_TEE_RED_M', 1990, 2490);
        // Added in the other order: the output follows the product's options.
        FeedFixtures::optionValue($size, $red, 'm', 'M');
        FeedFixtures::optionValue($colour, $red, 'red', 'Rot');
        FeedFixtures::image($product, 'ab/cd/tee-back.jpg');
        FeedFixtures::image($product, 'ab/cd/tee-front.jpg', 'main');
        FeedFixtures::image($product, 'ab/cd/tee-red.jpg', null, $red);
        $root = FeedFixtures::taxon('category', 'Kategorie');
        $apparel = FeedFixtures::taxon('apparel', 'Bekleidung', $root);
        $shirts = FeedFixtures::taxon('shirts', 'Shirts', $apparel);
        FeedFixtures::classify($product, $shirts, main: true);

        $mapped = $this->map($product);

        self::assertSame([
            'id' => 'CLASSIC_TEE',
            'title' => 'Classic Tee',
            'description' => ['plain' => 'Organic cotton, regular fit.'],
            'url' => 'https://shop.example.com/de_DE/products/classic-tee',
            'media' => [
                ['type' => 'image', 'url' => 'https://shop.example.com/media/image/ab/cd/tee-front.jpg', 'alt_text' => 'Classic Tee'],
                ['type' => 'image', 'url' => 'https://shop.example.com/media/image/ab/cd/tee-back.jpg', 'alt_text' => 'Classic Tee'],
                ['type' => 'image', 'url' => 'https://shop.example.com/media/image/ab/cd/tee-red.jpg', 'alt_text' => 'Classic Tee'],
            ],
            'variants' => [[
                'id' => 'CLASSIC_TEE_RED_M',
                'title' => 'Classic Tee – Rot / M',
                'url' => 'https://shop.example.com/de_DE/products/classic-tee',
                'price' => ['amount' => 1990, 'currency' => 'EUR'],
                'list_price' => ['amount' => 2490, 'currency' => 'EUR'],
                'availability' => ['available' => true, 'status' => 'in_stock'],
                'categories' => [['value' => 'Bekleidung > Shirts', 'taxonomy' => 'merchant']],
                'condition' => ['new'],
                'variant_options' => [['name' => 'Farbe', 'value' => 'Rot'], ['name' => 'Größe', 'value' => 'M']],
                'media' => [['type' => 'image', 'url' => 'https://shop.example.com/media/image/ab/cd/tee-red.jpg', 'alt_text' => 'Classic Tee – Rot / M']],
                'seller' => ['name' => 'Example Shop'],
            ]],
        ], $mapped);
        AcpFeedSchema::assertValid($mapped, 'Product');
    }

    public function testAMinimalProductStillValidates(): void
    {
        $product = FeedFixtures::product(slug: null, description: null);
        FeedFixtures::variant($product, 'V1', 0);

        $mapped = $this->map($product, seller: null);

        self::assertSame([
            'id' => 'CLASSIC_TEE',
            'title' => 'Classic Tee',
            'variants' => [[
                'id' => 'V1',
                'title' => 'Classic Tee',
                'price' => ['amount' => 0, 'currency' => 'EUR'],
                'availability' => ['available' => true, 'status' => 'in_stock'],
                'condition' => ['new'],
            ]],
        ], $mapped);
        AcpFeedSchema::assertValid($mapped, 'Product');
    }

    public function testTheVariantsOwnNameWinsOverTheComposedTitle(): void
    {
        $product = FeedFixtures::product();
        $size = FeedFixtures::option($product, 'size', 'Größe');
        $variant = FeedFixtures::variant($product, 'V1', name: '  Classic Tee, Größe M  ');
        FeedFixtures::optionValue($size, $variant, 'm', 'M');

        self::assertSame('Classic Tee, Größe M', $this->map($product)['variants'][0]['title'] ?? null);
    }

    public function testAVariantWithoutAPriceInTheChannelIsLeftOut(): void
    {
        // The cart service could not price it; the feed must not offer it.
        $product = FeedFixtures::product();
        FeedFixtures::variant($product, 'NO_PRICE', null);
        FeedFixtures::variant($product, 'OTHER_CHANNEL', 990, channelCode: 'OTHER');
        FeedFixtures::variant($product, 'NEGATIVE', -1);
        FeedFixtures::variant($product, 'PRICED', 990);

        self::assertSame(['PRICED'], array_column($this->map($product)['variants'] ?? [], 'id'));
    }

    public function testAProductWithNothingOnOfferIsLeftOut(): void
    {
        $product = FeedFixtures::product();
        FeedFixtures::variant($product, 'NO_PRICE', null);

        self::assertNull($this->map($product));
        self::assertNull($this->map(FeedFixtures::product()));
    }

    public function testAProductOrVariantWithoutCodeIsLeftOut(): void
    {
        $product = FeedFixtures::product();
        $product->setCode(null);
        FeedFixtures::variant($product, 'V1');
        self::assertNull($this->map($product));

        $product = FeedFixtures::product();
        FeedFixtures::variant($product, '');
        FeedFixtures::variant($product, 'V2');
        self::assertSame(['V2'], array_column($this->map($product)['variants'] ?? [], 'id'));
    }

    public function testDisabledVariantsAreLeftOut(): void
    {
        $product = FeedFixtures::product();
        FeedFixtures::variant($product, 'OFF')->setEnabled(false);
        FeedFixtures::variant($product, 'ON');

        self::assertSame(['ON'], array_column($this->map($product)['variants'] ?? [], 'id'));
    }

    public function testListPriceOnlyWhereItIsAboveThePrice(): void
    {
        $product = FeedFixtures::product();
        FeedFixtures::variant($product, 'SAME', 1000, 1000);
        FeedFixtures::variant($product, 'LOWER', 1000, 900);
        FeedFixtures::variant($product, 'HIGHER', 1000, 1001);

        $variants = $this->map($product)['variants'] ?? [];

        self::assertArrayNotHasKey('list_price', $variants[0]);
        self::assertArrayNotHasKey('list_price', $variants[1]);
        self::assertSame(['amount' => 1001, 'currency' => 'EUR'], $variants[2]['list_price']);
    }

    public function testAvailabilityFollowsTheShopsStockCheck(): void
    {
        $product = FeedFixtures::product();
        FeedFixtures::variant($product, 'NONE', onHand: 0);
        FeedFixtures::variant($product, 'HELD', onHand: 2)->setOnHold(2);
        FeedFixtures::variant($product, 'UNTRACKED', onHand: 0, tracked: false);
        FeedFixtures::variant($product, 'ONE', onHand: 1);

        $mapped = $this->map($product);
        $availability = array_column($mapped['variants'] ?? [], 'availability', 'id');

        $out = ['available' => false, 'status' => 'out_of_stock'];
        $in = ['available' => true, 'status' => 'in_stock'];
        self::assertSame(['NONE' => $out, 'HELD' => $out, 'UNTRACKED' => $in, 'ONE' => $in], $availability);
        AcpFeedSchema::assertValid($mapped, 'Product');
    }

    public function testTheShortDescriptionStandsInForAMissingLongOne(): void
    {
        $product = FeedFixtures::product(description: '<p> </p>');
        $product->setShortDescription("Kurz &amp; gut.\n\nZweiter   Satz&apos;s &#039;Ende&#039;.");
        FeedFixtures::variant($product, 'V1');

        self::assertSame(['plain' => "Kurz & gut. Zweiter Satz's 'Ende'."], $this->map($product)['description'] ?? null);
    }

    public function testBlockTagsDoNotGlueWordsTogether(): void
    {
        $product = FeedFixtures::product(description: '<p>Eins</p><P>Zwei</P><br/>Drei<li>Vier</li><b>Fünf</b>,<em>sechs</em>');
        FeedFixtures::variant($product, 'V1');

        self::assertSame(['plain' => 'Eins Zwei Drei Vier Fünf,sechs'], $this->map($product)['description'] ?? null);
    }

    public function testNoDescriptionAtAllIsLeftOutRatherThanEmpty(): void
    {
        // The schema requires at least one property in Description.
        $product = FeedFixtures::product(description: '');
        $product->setShortDescription('   ');
        FeedFixtures::variant($product, 'V1');

        self::assertArrayNotHasKey('description', $this->map($product) ?? []);
    }

    public function testCategoriesAreDeduplicatedWithTheMainTaxonFirst(): void
    {
        $product = FeedFixtures::product();
        FeedFixtures::variant($product, 'V1');
        $root = FeedFixtures::taxon('category', 'Kategorie');
        $a = FeedFixtures::taxon('a', 'Alpha', $root);
        $b = FeedFixtures::taxon('b', 'Beta', $root);
        $unnamed = FeedFixtures::taxon('c', '', $root);
        FeedFixtures::classify($product, $a);
        FeedFixtures::classify($product, $unnamed);
        FeedFixtures::classify($product, $b, main: true);

        self::assertSame(
            [['value' => 'Beta', 'taxonomy' => 'merchant'], ['value' => 'Alpha', 'taxonomy' => 'merchant']],
            $this->map($product)['variants'][0]['categories'] ?? null,
        );
    }

    public function testADeepPathReadsFromTheTopDown(): void
    {
        $product = FeedFixtures::product();
        FeedFixtures::variant($product, 'V1');
        $root = FeedFixtures::taxon('category', 'Kategorie');
        $a = FeedFixtures::taxon('a', 'Genuss', $root);
        $b = FeedFixtures::taxon('b', 'Zigarren', $a);
        $unnamed = FeedFixtures::taxon('u', '', $b);
        $c = FeedFixtures::taxon('c', 'Kuba', $unnamed);
        FeedFixtures::classify($product, $c);

        self::assertSame([['value' => 'Genuss > Zigarren > Kuba', 'taxonomy' => 'merchant']], $this->map($product)['variants'][0]['categories'] ?? null);
    }

    public function testAProductFiledUnderTheRootItselfGetsTheRootsName(): void
    {
        $product = FeedFixtures::product();
        FeedFixtures::variant($product, 'V1');
        FeedFixtures::classify($product, FeedFixtures::taxon('category', 'Kategorie'));

        self::assertSame([['value' => 'Kategorie', 'taxonomy' => 'merchant']], $this->map($product)['variants'][0]['categories'] ?? null);
    }

    public function testImagesWithoutPathOrRepeatedAreSkipped(): void
    {
        $product = FeedFixtures::product();
        FeedFixtures::variant($product, 'V1');
        FeedFixtures::image($product, '');
        FeedFixtures::image($product, 'a.jpg');
        FeedFixtures::image($product, 'a.jpg', 'main');
        FeedFixtures::image($product, 'b.jpg');

        $mapped = $this->map($product);
        self::assertSame(['https://shop.example.com/media/image/a.jpg', 'https://shop.example.com/media/image/b.jpg'], array_column($mapped['media'] ?? [], 'url'));
        self::assertArrayNotHasKey('media', $mapped['variants'][0] ?? []);
    }

    public function testTheLocaleOfTheScopeIsUsed(): void
    {
        $product = FeedFixtures::product();
        $product->setCurrentLocale('en_US');
        $product->setName('Classic Tee EN');
        $product->setSlug('classic-tee-en');
        FeedFixtures::variant($product, 'V1');

        $mapped = $this->map($product, localeCode: 'en_US');

        self::assertSame('Classic Tee EN', $mapped['title'] ?? null);
        self::assertSame('https://shop.example.com/en_US/products/classic-tee-en', $mapped['url'] ?? null);
    }

    public function testAnOptionWithoutTranslatedTextIsLeftOut(): void
    {
        $product = FeedFixtures::product();
        $unused = FeedFixtures::option($product, 'unused', 'Unbenutzt');
        $size = FeedFixtures::option($product, 'size', 'Größe');
        $blank = FeedFixtures::option($product, 'blank', ' ');
        $variant = FeedFixtures::variant($product, 'V1');
        FeedFixtures::optionValue($size, $variant, 'm', 'M');
        FeedFixtures::optionValue($blank, $variant, 'x', 'X');

        self::assertSame([['name' => 'Größe', 'value' => 'M']], $this->map($product)['variants'][0]['variant_options'] ?? null);
        self::assertNotNull($unused);
    }

    /**
     * @param array{name?: string}|null $seller
     *
     * @return array<string, mixed>|null
     */
    private function map(Product $product, string $localeCode = 'de_DE', ?array $seller = ['name' => 'Example Shop']): ?array
    {
        return (new AcpProductMapper(new AvailabilityChecker()))->map($product, FeedFixtures::scope(localeCode: $localeCode, seller: $seller));
    }
}
