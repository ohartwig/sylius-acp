<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests\Cart;

use Koh\SyliusAcp\Cart\CartPresenter;
use PHPUnit\Framework\TestCase;

final class CartPresenterTest extends TestCase
{
    use CartFixtures;

    public function testItRendersTheAcpCartShape(): void
    {
        $cart = $this->cart(
            [$this->item($this->variant('TEE_M', name: 'T-shirt, size M'), 1990, 2, 3980, 11)],
            updatedAt: new \DateTime('2026-11-05 12:30:00', new \DateTimeZone('Europe/Berlin')),
            itemsTotal: 3980,
        );

        $doc = (new CartPresenter($this->store()))->present($cart, $this->context());

        self::assertSame(['id', 'line_items', 'currency', 'totals', 'messages', 'continue_url', 'expires_at'], array_keys($doc));
        self::assertSame('acp_00000000000000000000000000000000_0000000000000000', $doc['id']);
        self::assertSame('eur', $doc['currency']);
        self::assertSame('https://shop.example.com/acp-cart/acp_00000000000000000000000000000000_0000000000000000', $doc['continue_url']);
        self::assertSame('2026-11-07T11:30:00Z', $doc['expires_at']);
        self::assertSame([[
            'id' => 'li_11',
            'item' => ['id' => 'TEE_M', 'name' => 'T-shirt, size M', 'unit_amount' => 1990],
            'quantity' => 2,
            'name' => 'T-shirt, size M',
            'unit_amount' => 1990,
            'variant_id' => 'TEE_M',
            'availability_status' => 'in_stock',
            'totals' => [
                ['type' => 'subtotal', 'display_text' => 'Subtotal', 'amount' => 3980],
                ['type' => 'total', 'display_text' => 'Total', 'amount' => 3980],
            ],
            'product_id' => 'P_TEE_M',
        ]], $doc['line_items']);
        self::assertSame([
            ['type' => 'items_base_amount', 'display_text' => 'Items', 'amount' => 3980],
            ['type' => 'total', 'display_text' => 'Estimated total (excluding shipping)', 'amount' => 3980],
        ], $doc['totals']);
        self::assertSame([[
            'type' => 'info',
            'content_type' => 'plain',
            'content' => 'Totals are estimates without shipping. The buyer completes the purchase in the shop, where shipping, payment and the final total are settled: hand them continue_url.',
        ]], $doc['messages']);
    }

    public function testAVariantWithoutANameIsNamedAfterItsProduct(): void
    {
        $doc = (new CartPresenter($this->store()))->present($this->cart([$this->item($this->variant('A', productName: 'Cigar'), id: null)]), $this->context());

        self::assertSame('Cigar', $doc['line_items'][0]['name']);
        self::assertSame('li_0', $doc['line_items'][0]['id']);
    }

    public function testTheItemsBaseAmountAddsUpEveryLine(): void
    {
        $cart = $this->cart([$this->item($this->variant('A'), 1000, 2), $this->item($this->variant('B'), 250, 3)], itemsTotal: 2750);

        self::assertSame(2750, (new CartPresenter($this->store()))->present($cart, $this->context())['totals'][0]['amount']);
    }

    public function testALineWhoseVariantVanishedStillRenders(): void
    {
        $doc = (new CartPresenter($this->store()))->present($this->cart([$this->item(null)], token: 'acp_t'), $this->context());

        self::assertSame(['id' => '', 'name' => '', 'unit_amount' => 1000], $doc['line_items'][0]['item']);
        self::assertSame('out_of_stock', $doc['line_items'][0]['availability_status']);
        self::assertArrayNotHasKey('product_id', $doc['line_items'][0]);
        self::assertSame('Not enough stock for "" at this quantity.', $doc['messages'][0]['content']);
    }

    public function testDiscountsAndIncludedTaxAreReportedButNotAddedOnTop(): void
    {
        $cart = $this->cart([$this->item($this->variant('A'), 1000, 2, 1800)], itemsTotal: 1800, promotionTotal: -200, taxTotal: 287);

        self::assertSame([
            ['type' => 'items_base_amount', 'display_text' => 'Items', 'amount' => 2000],
            ['type' => 'discount', 'display_text' => 'Discount', 'amount' => 200],
            ['type' => 'tax', 'display_text' => 'VAT (included)', 'amount' => 287],
            ['type' => 'total', 'display_text' => 'Estimated total (excluding shipping)', 'amount' => 1800],
        ], (new CartPresenter($this->store()))->present($cart, $this->context())['totals']);
    }

    public function testExcludedTaxIsLabelledAsTax(): void
    {
        $cart = $this->cart([$this->item($this->variant('A'))], itemsTotal: 1190, taxTotal: 190, taxExcluded: 190);

        $totals = (new CartPresenter($this->store()))->present($cart, $this->context())['totals'];

        self::assertSame(['type' => 'tax', 'display_text' => 'Tax', 'amount' => 190], $totals[1]);
    }

    public function testShortStockIsFlaggedOnTheLine(): void
    {
        $doc = (new CartPresenter($this->store(stock: false)))->present($this->cart([$this->item($this->variant('A')), $this->item($this->variant('B'))]), $this->context());

        self::assertSame('out_of_stock', $doc['line_items'][1]['availability_status']);
        self::assertSame([
            'type' => 'error',
            'code' => 'out_of_stock',
            'severity' => 'high',
            'resolution' => 'recoverable',
            'param' => '$.line_items[1]',
            'content_type' => 'plain',
            'content' => 'Not enough stock for "B" at this quantity.',
        ], $doc['messages'][1]);
        self::assertSame('$.line_items[0]', $doc['messages'][0]['param']);
    }

    public function testAnAgeRestrictedCatalogueSendsTheBuyerToTheShop(): void
    {
        $doc = (new CartPresenter($this->store(), 18))->present($this->cart([$this->item($this->variant('A'))]), $this->context());

        self::assertSame([
            'type' => 'error',
            'code' => 'age_verification_required',
            'severity' => 'high',
            'resolution' => 'requires_buyer_review',
            'param' => '$.line_items',
            'content_type' => 'plain',
            'content' => 'Age-restricted goods (18+). Only the buyer can buy them, after confirming their age in the shop: hand them continue_url.',
        ], $doc['messages'][0]);
    }

    public function testWriteMessagesComeFirstAndBuyerDataIsNotKept(): void
    {
        $write = [['type' => 'error', 'code' => 'not_found', 'content_type' => 'plain', 'content' => 'x']];

        $messages = (new CartPresenter($this->store()))->present($this->cart([$this->item($this->variant('A'))]), $this->context(), $write, true)['messages'];

        self::assertSame($write[0], $messages[0]);
        self::assertSame([
            'type' => 'info',
            'param' => '$.buyer',
            'content_type' => 'plain',
            'content' => 'Buyer details are not stored with the cart; the buyer enters them at checkout.',
        ], $messages[1]);
        self::assertCount(3, $messages);
    }
}
