<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\ProductFeed;

use Sylius\Component\Core\Model\ImageInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;

/**
 * Maps a Sylius product to an ACP `Product` with its `Variant`s
 * (schema.feed.json, ACP 2026-04-17).
 *
 * Only fields the schema defines are emitted, and only when the shop has a
 * value for them: the schema forbids additional properties, and an empty string
 * or an empty object says "known and blank" where the truth is "unknown".
 *
 * `Variant.id` is the variant code, the identifier the cart service accepts as
 * `line_items[].id`. `Product.id` is the product code, which the cart reports as
 * `product_id`. Keep the three in step: the RFC requires that a feed variant id
 * is accepted at checkout as it stands.
 */
final class AcpProductMapper
{
    /** Primary listing asset first, as the schema reads `media[0]`. */
    private const MAIN_IMAGE_TYPE = 'main';

    public function __construct(private readonly AvailabilityCheckerInterface $availability)
    {
    }

    /**
     * @return array<string, mixed>|null null when no variant of the product can be bought in the channel
     */
    public function map(ProductInterface $product, FeedScope $scope): ?array
    {
        $code = $product->getCode();
        if (null === $code || '' === $code) {
            return null;
        }

        $translation = $product->getTranslation($scope->localeCode);
        $title = $this->text($translation->getName());
        $slug = $translation->getSlug();
        $url = null === $slug || '' === $slug ? null : $scope->url($scope->localeCode.'/products/'.$slug);

        $variants = [];
        foreach ($product->getEnabledVariants() as $variant) {
            if ($variant instanceof ProductVariantInterface) {
                $mapped = $this->variant($product, $variant, $scope, $title, $url);
                if (null !== $mapped) {
                    $variants[] = $mapped;
                }
            }
        }

        if ([] === $variants) {
            return null;
        }

        $mapped = ['id' => $code];
        if (null !== $title) {
            $mapped['title'] = $title;
        }

        $description = $this->plain($translation->getDescription()) ?? $this->plain($translation->getShortDescription());
        if (null !== $description) {
            $mapped['description'] = ['plain' => $description];
        }

        if (null !== $url) {
            $mapped['url'] = $url;
        }

        $media = $this->media($product->getImages(), $scope, $title);
        if ([] !== $media) {
            $mapped['media'] = $media;
        }

        $mapped['variants'] = $variants;

        return $mapped;
    }

    /** @return array<string, mixed>|null */
    private function variant(ProductInterface $product, ProductVariantInterface $variant, FeedScope $scope, ?string $productTitle, ?string $url): ?array
    {
        $code = $variant->getCode();
        $pricing = $variant->getChannelPricingForChannel($scope->channel);

        // A variant without a price in this channel cannot be put into a cart
        // here, so it is not on offer; listing it would hand an agent an id
        // the cart service rejects.
        if (null === $code || '' === $code || null === $pricing) {
            return null;
        }
        $price = $pricing->getPrice();
        if (null === $price || $price < 0) {
            return null;
        }

        $options = $this->options($product, $variant, $scope->localeCode);
        $title = $this->text($variant->getTranslation($scope->localeCode)->getName())
            ?? $this->composedTitle($productTitle, $options)
            ?? $code;

        $mapped = ['id' => $code, 'title' => $title];

        if (null !== $url) {
            $mapped['url'] = $url;
        }

        $mapped['price'] = ['amount' => $price, 'currency' => $scope->currency];

        // The strike-through price, only where it is one.
        $originalPrice = $pricing->getOriginalPrice();
        if (null !== $originalPrice && $originalPrice > $price) {
            $mapped['list_price'] = ['amount' => $originalPrice, 'currency' => $scope->currency];
        }

        // The same check the cart service and the shop's add-to-cart apply.
        $available = $this->availability->isStockAvailable($variant);
        $mapped['availability'] = ['available' => $available, 'status' => $available ? 'in_stock' : 'out_of_stock'];

        $categories = $this->categories($product, $scope->localeCode);
        if ([] !== $categories) {
            $mapped['categories'] = $categories;
        }

        $mapped['condition'] = ['new'];

        if ([] !== $options) {
            $mapped['variant_options'] = $options;
        }

        $media = $this->media($variant->getImages(), $scope, $title);
        if ([] !== $media) {
            $mapped['media'] = $media;
        }

        if (null !== $scope->seller && [] !== $scope->seller) {
            $mapped['seller'] = $scope->seller;
        }

        return $mapped;
    }

    /**
     * Option selections in the product's option order, so "Colour, Size" does
     * not read "Size, Colour" on every other variant.
     *
     * @return list<array{name: string, value: string}>
     */
    private function options(ProductInterface $product, ProductVariantInterface $variant, string $localeCode): array
    {
        $values = [];
        foreach ($variant->getOptionValues() as $optionValue) {
            $optionCode = $optionValue->getOption()?->getCode();
            if (null !== $optionCode) {
                $values[$optionCode] = $optionValue;
            }
        }

        $options = [];
        foreach ($product->getOptions() as $option) {
            $optionValue = $values[(string) $option->getCode()] ?? null;
            if (null === $optionValue) {
                continue;
            }

            $name = $this->text($option->getTranslation($localeCode)->getName());
            $value = $this->text($optionValue->getTranslation($localeCode)->getValue());
            if (null !== $name && null !== $value) {
                $options[] = ['name' => $name, 'value' => $value];
            }
        }

        return $options;
    }

    /** @param list<array{name: string, value: string}> $options */
    private function composedTitle(?string $productTitle, array $options): ?string
    {
        if (null === $productTitle || [] === $options) {
            return $productTitle;
        }

        return $productTitle.' – '.implode(' / ', array_column($options, 'value'));
    }

    /**
     * The product's taxons as merchant category paths, main taxon first. The
     * tree root is left out of the path: it is the name of the tree
     * ("Category"), not a category.
     *
     * @return list<array{value: string, taxonomy: string}>
     */
    private function categories(ProductInterface $product, string $localeCode): array
    {
        $taxons = [];
        $mainTaxon = $product->getMainTaxon();
        if (null !== $mainTaxon) {
            $taxons[] = $mainTaxon;
        }
        foreach ($product->getTaxons() as $taxon) {
            $taxons[] = $taxon;
        }

        $categories = [];
        foreach ($taxons as $taxon) {
            $path = $this->taxonPath($taxon, $localeCode);
            if (null !== $path && !isset($categories[$path])) {
                $categories[$path] = ['value' => $path, 'taxonomy' => 'merchant'];
            }
        }

        return array_values($categories);
    }

    private function taxonPath(TaxonInterface $taxon, string $localeCode): ?string
    {
        $names = [];
        $isRoot = null === $taxon->getParent();
        foreach (array_reverse($taxon->getAncestors()->toArray()) as $ancestor) {
            if (null === $ancestor->getParent()) {
                continue;
            }
            $name = $this->text($ancestor->getTranslation($localeCode)->getName());
            if (null !== $name) {
                $names[] = $name;
            }
        }

        $name = $this->text($taxon->getTranslation($localeCode)->getName());
        if (null === $name) {
            return null;
        }

        return $isRoot ? $name : implode(' > ', [...$names, $name]);
    }

    /**
     * @param iterable<ImageInterface> $images
     *
     * @return list<array{type: string, url: string, alt_text?: string}>
     */
    private function media(iterable $images, FeedScope $scope, ?string $altText): array
    {
        $main = [];
        $other = [];
        $seen = [];
        foreach ($images as $image) {
            $path = $image->getPath();
            if (null === $path || '' === $path || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;

            $item = ['type' => 'image', 'url' => $scope->mediaUrl($path)];
            if (null !== $altText) {
                $item['alt_text'] = $altText;
            }

            if (self::MAIN_IMAGE_TYPE === $image->getType()) {
                $main[] = $item;
            } else {
                $other[] = $item;
            }
        }

        return [...$main, ...$other];
    }

    private function text(?string $text): ?string
    {
        if (null === $text) {
            return null;
        }

        $text = trim($text);

        return '' === $text ? null : $text;
    }

    /**
     * Plain text from the editor's rich text: tags out, entities decoded,
     * whitespace collapsed. The schema has `html` too, but agents must sanitise
     * it before rendering; plain text needs no such trust.
     */
    private function plain(?string $text): ?string
    {
        if (null === $text) {
            return null;
        }

        // A space before block tags and line breaks, so `<p>One</p><p>Two</p>`
        // does not become "OneTwo"; inline tags such as <strong> stay glued to
        // their punctuation. The collapse below removes the surplus.
        $text = (string) preg_replace('#<(?=/?(?:p|div|br|li|ul|ol|h[1-6]|tr|td|th|table|blockquote|section|article)\b)#i', ' <', $text);
        $text = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return $this->text((string) preg_replace('/\s+/u', ' ', $text));
    }
}
