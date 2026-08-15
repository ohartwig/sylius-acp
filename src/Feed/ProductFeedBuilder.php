<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Feed;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;

/**
 * Builds the agent-facing product feed.
 *
 * SKU-granular: one entry per variant, not per product. An agent comparing
 * prices needs the thing it can actually buy, and a product with three sizes at
 * three prices cannot be represented by one row without lying about at least
 * two of them.
 *
 * Age restriction is carried explicitly. A feed that omits it invites an agent
 * to treat a restricted item as ordinary stock.
 */
final class ProductFeedBuilder
{
    /** @param ProductRepositoryInterface<ProductInterface> $productRepository */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ?int $ageRestriction = null,
        private readonly int $defaultLimit = 100,
        private readonly int $maxLimit = 500,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, page: int, limit: int, count: int}
     */
    public function build(AgentContext $context, int $page = 1, ?int $limit = null): array
    {
        // Clamp on both ends. min() alone leaves the lower bound open, and the
        // limit comes straight off the query string: `?limit=-1` would survive
        // as -1, reach the repository as a negative max-result — which Doctrine
        // reads as "no limit" — and hand out the entire catalogue from a public,
        // unauthenticated route, with max_limit silently bypassed.
        $limit = max(1, min($limit ?? $this->defaultLimit, $this->maxLimit));
        $page = max(1, $page);

        // Fetch page+1 worth so the caller can be told there is more without a
        // second count query.
        $products = $this->productRepository->findLatestByChannel(
            $context->channel,
            $context->localeCode,
            $page * $limit,
        );

        $items = [];
        foreach (\array_slice($products, ($page - 1) * $limit, $limit) as $product) {
            foreach ($product->getEnabledVariants() as $variant) {
                if ($variant instanceof ProductVariantInterface) {
                    $items[] = $this->variant($product, $variant, $context);
                }
            }
        }

        return ['items' => $items, 'page' => $page, 'limit' => $limit, 'count' => \count($items)];
    }

    /** @return array<string, mixed> */
    private function variant(ProductInterface $product, ProductVariantInterface $variant, AgentContext $context): array
    {
        $translation = $product->getTranslation($context->localeCode);
        $pricing = $variant->getChannelPricingForChannel($context->channel);
        $slug = $translation->getSlug();
        // Fall back to the long description when the short one is absent or blank.
        $shortDescription = $translation->getShortDescription();
        $shortDescription = null !== $shortDescription && '' !== $shortDescription ? $shortDescription : null;

        $item = [
            'id' => $variant->getCode(),
            'item_group_id' => $product->getCode(),
            'title' => $variant->getTranslation($context->localeCode)->getName() ?? $translation->getName(),
            'description' => $this->plain($shortDescription ?? $translation->getDescription()),
            'link' => null === $slug ? null : $context->url('/'.$context->localeCode.'/products/'.$slug),
            'availability' => $variant->isInStock() ? 'in_stock' : 'out_of_stock',
            'condition' => 'new',
        ];

        if (null !== $pricing && null !== $pricing->getPrice()) {
            // Minor units and the currency separately: an agent doing
            // arithmetic on a formatted string gets it wrong for at least one
            // locale.
            $item['price'] = (string) $pricing->getPrice();
            $item['price_currency'] = $context->channel->getBaseCurrency()?->getCode();
        }

        if (null !== $this->ageRestriction) {
            $item['age_restriction'] = $this->ageRestriction;
        }

        return $item;
    }

    private function plain(?string $text): ?string
    {
        if (null === $text || '' === trim($text)) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));

        // Checked again after stripping, not only before it. `<p> </p>` is not
        // empty on the way in and is empty on the way out, so the guard above
        // lets it through and the item ships `description: ""` — which reads as
        // a description that exists and says nothing, rather than as a product
        // that has none.
        if ('' === $text) {
            return null;
        }

        return mb_strlen($text) > 500 ? mb_substr($text, 0, 497).'…' : $text;
    }
}
