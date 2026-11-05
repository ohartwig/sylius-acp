<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests\ProductFeed;

use Koh\SyliusAcp\ProductFeed\FeedScope;
use Sylius\Component\Addressing\Model\Country;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductImage;
use Sylius\Component\Core\Model\ProductTaxon;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\Taxon;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Model\Locale;
use Sylius\Component\Product\Model\ProductOption;
use Sylius\Component\Product\Model\ProductOptionValue;

/**
 * Real Sylius entities rather than stubs: the mapper walks translations,
 * taxon trees, option values and channel pricings, and a stub of each would
 * pin how it is called rather than what the feed says.
 */
final class FeedFixtures
{
    public static function channel(string $code = 'WEB'): Channel
    {
        $currency = new Currency();
        $currency->setCode('EUR');

        $channel = new Channel();
        $channel->setCode($code);
        $channel->setName('Example Shop');
        $channel->setHostname('shop.example.com');
        $channel->setBaseCurrency($currency);
        foreach (['de_DE', 'en_US'] as $localeCode) {
            $locale = new Locale();
            $locale->setCode($localeCode);
            $channel->addLocale($locale);
            if ('de_DE' === $localeCode) {
                $channel->setDefaultLocale($locale);
            }
        }
        $country = new Country();
        $country->setCode('DE');
        $channel->addCountry($country);

        return $channel;
    }

    public static function scope(?Channel $channel = null, string $localeCode = 'de_DE', ?array $seller = ['name' => 'Example Shop']): FeedScope
    {
        return new FeedScope(
            channel: $channel ?? self::channel(),
            localeCode: $localeCode,
            baseUrl: 'https://shop.example.com',
            currency: 'EUR',
            seller: $seller,
            targetCountry: 'DE',
        );
    }

    public static function product(string $code = 'CLASSIC_TEE', string $name = 'Classic Tee', ?string $slug = 'classic-tee', ?string $description = '<p>Organic <strong>cotton</strong>, regular fit.</p>'): Product
    {
        $product = new Product();
        $product->setCurrentLocale('de_DE');
        $product->setFallbackLocale('de_DE');
        $product->setCode($code);
        $product->setName($name);
        $product->setSlug($slug);
        $product->setDescription($description);

        return $product;
    }

    public static function variant(Product $product, string $code, ?int $price = 1990, ?int $originalPrice = null, string $channelCode = 'WEB', ?string $name = null, int $onHand = 5, bool $tracked = true): ProductVariant
    {
        $variant = new ProductVariant();
        $variant->setCurrentLocale('de_DE');
        $variant->setFallbackLocale('de_DE');
        $variant->setCode($code);
        $variant->setName($name);
        $variant->setTracked($tracked);
        $variant->setOnHand($onHand);

        if (null !== $price) {
            $pricing = new ChannelPricing();
            $pricing->setChannelCode($channelCode);
            $pricing->setPrice($price);
            $pricing->setOriginalPrice($originalPrice);
            $variant->addChannelPricing($pricing);
        }

        $product->addVariant($variant);

        return $variant;
    }

    public static function option(Product $product, string $code, string $name): ProductOption
    {
        $option = new ProductOption();
        $option->setCurrentLocale('de_DE');
        $option->setFallbackLocale('de_DE');
        $option->setCode($code);
        $option->setName($name);
        $product->addOption($option);

        return $option;
    }

    public static function optionValue(ProductOption $option, ProductVariant $variant, string $code, string $value): void
    {
        $optionValue = new ProductOptionValue();
        $optionValue->setCurrentLocale('de_DE');
        $optionValue->setFallbackLocale('de_DE');
        $optionValue->setCode($code);
        $optionValue->setValue($value);
        $optionValue->setOption($option);
        $variant->addOptionValue($optionValue);
    }

    public static function image(Product $product, string $path, ?string $type = null, ?ProductVariant $variant = null): void
    {
        $image = new ProductImage();
        $image->setPath($path);
        $image->setType($type);
        $product->addImage($image);
        $variant?->addImage($image);
    }

    public static function taxon(string $code, string $name, ?Taxon $parent = null): Taxon
    {
        $taxon = new Taxon();
        $taxon->setCurrentLocale('de_DE');
        $taxon->setFallbackLocale('de_DE');
        $taxon->setCode($code);
        $taxon->setName($name);
        $taxon->setParent($parent);

        return $taxon;
    }

    public static function classify(Product $product, Taxon $taxon, bool $main = false): void
    {
        $productTaxon = new ProductTaxon();
        $productTaxon->setTaxon($taxon);
        $product->addProductTaxon($productTaxon);
        if ($main) {
            $product->setMainTaxon($taxon);
        }
    }
}
