<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Cart;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;

/**
 * Renders a Sylius cart as an ACP 2026-04-17 `Cart`
 * (schema.cart.json#/$defs/Cart).
 *
 * Totals are estimates, as the cart RFC intends: shipping depends on an address
 * this API never asks for, so the total covers the items only and a message
 * says so. Taxes are reported as the shop computed them; with gross prices the
 * tax line is informational ("included") and not added on top.
 */
final class CartPresenter
{
    /** Fixed path of the buyer handoff, see config/routes.php. */
    public const CONTINUE_PATH = '/acp-cart/';

    public function __construct(
        private readonly AgentCartStore $store,
        private readonly ?int $ageRestriction = null,
    ) {
    }

    /**
     * @param list<array<string, string>> $extraMessages messages of the write that produced this state
     *
     * @return array<string, mixed>
     */
    public function present(OrderInterface $cart, AgentContext $context, array $extraMessages = [], bool $buyerGiven = false): array
    {
        $lineItems = [];
        $messages = $extraMessages;
        $base = 0;

        $index = 0;
        foreach ($cart->getItems() as $item) {
            $sufficient = $this->store->isStockSufficient($item);
            $lineItems[] = $this->lineItem($item, $index, $sufficient, (string) $cart->getLocaleCode());
            $base += $item->getUnitPrice() * $item->getQuantity();

            if (!$sufficient) {
                $messages[] = [
                    'type' => 'error',
                    'code' => 'out_of_stock',
                    'severity' => 'high',
                    'resolution' => 'recoverable',
                    'param' => \sprintf('$.line_items[%d]', $index),
                    'content_type' => 'plain',
                    'content' => \sprintf('Not enough stock for "%s" at this quantity.', (string) $item->getVariant()?->getCode()),
                ];
            }
            ++$index;
        }

        if (null !== $this->ageRestriction) {
            $messages[] = [
                'type' => 'error',
                'code' => 'age_verification_required',
                'severity' => 'high',
                'resolution' => 'requires_buyer_review',
                'param' => '$.line_items',
                'content_type' => 'plain',
                'content' => \sprintf(
                    'Age-restricted goods (%d+). Only the buyer can buy them, after confirming their age in the shop: hand them continue_url.',
                    $this->ageRestriction,
                ),
            ];
        }

        if ($buyerGiven) {
            $messages[] = [
                'type' => 'info',
                'param' => '$.buyer',
                'content_type' => 'plain',
                'content' => 'Buyer details are not stored with the cart; the buyer enters them at checkout.',
            ];
        }

        $messages[] = [
            'type' => 'info',
            'content_type' => 'plain',
            'content' => 'Totals are estimates without shipping. The buyer completes the purchase in the shop, where shipping, payment and the final total are settled: hand them continue_url.',
        ];

        return [
            'id' => (string) $cart->getTokenValue(),
            'line_items' => $lineItems,
            'currency' => strtolower((string) $cart->getCurrencyCode()),
            'totals' => $this->totals($cart, $base),
            'messages' => $messages,
            'continue_url' => $context->url(self::CONTINUE_PATH.rawurlencode((string) $cart->getTokenValue())),
            'expires_at' => $this->store->expiresAt($cart)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** @return array<string, mixed> */
    private function lineItem(OrderItemInterface $item, int $index, bool $sufficient, string $locale): array
    {
        $variant = $item->getVariant();
        $product = $variant?->getProduct();
        $code = (string) $variant?->getCode();
        // Same naming as the feed: the variant's name where it has one.
        $name = $variant?->getTranslation($locale)->getName()
            ?? $product?->getTranslation($locale)->getName()
            ?? $code;
        $unit = $item->getUnitPrice();
        $quantity = $item->getQuantity();

        $line = [
            'id' => 'li_'.(null !== $item->getId() ? (string) $item->getId() : (string) $index),
            'item' => ['id' => $code, 'name' => $name, 'unit_amount' => $unit],
            'quantity' => $quantity,
            'name' => $name,
            'unit_amount' => $unit,
            'variant_id' => $code,
            'availability_status' => $sufficient ? 'in_stock' : 'out_of_stock',
            'totals' => [
                ['type' => 'subtotal', 'display_text' => 'Subtotal', 'amount' => $unit * $quantity],
                ['type' => 'total', 'display_text' => 'Total', 'amount' => $item->getTotal()],
            ],
        ];

        $productCode = $product?->getCode();
        if (null !== $productCode) {
            $line['product_id'] = $productCode;
        }

        return $line;
    }

    /** @return list<array<string, mixed>> */
    private function totals(OrderInterface $cart, int $base): array
    {
        $totals = [['type' => 'items_base_amount', 'display_text' => 'Items', 'amount' => $base]];

        $discount = -$cart->getOrderPromotionTotal();
        if ($discount > 0) {
            $totals[] = ['type' => 'discount', 'display_text' => 'Discount', 'amount' => $discount];
        }

        $tax = $cart->getTaxTotal();
        if ($tax > 0) {
            $included = 0 === $cart->getTaxExcludedTotal();
            $totals[] = [
                'type' => 'tax',
                'display_text' => $included ? 'VAT (included)' : 'Tax',
                'amount' => $tax,
            ];
        }

        $totals[] = [
            'type' => 'total',
            'display_text' => 'Estimated total (excluding shipping)',
            'amount' => $cart->getItemsTotal(),
        ];

        return $totals;
    }
}
