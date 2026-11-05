<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests\Cart;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Persistence\ObjectManager;
use Koh\SyliusAcp\Cart\AgentCartStore;
use Koh\SyliusAgentContracts\Context\AgentContext;
use PHPUnit\Framework\MockObject\MockObject;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTranslationInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Product\Model\ProductVariantTranslationInterface;
use Sylius\Component\Product\Repository\ProductVariantRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;

/**
 * Builders shared by the cart tests. Mocks rather than Sylius entities: the
 * totals a cart shows come from Sylius' order processors, which these tests do
 * not run — what is under test is how the bundle reads them.
 */
trait CartFixtures
{
    private function channel(): ChannelInterface&MockObject
    {
        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getCode')->willReturn('EUR');
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getBaseCurrency')->willReturn($currency);
        $channel->method('getCode')->willReturn('WEB');

        return $channel;
    }

    private function context(?ChannelInterface $channel = null): AgentContext
    {
        return new AgentContext($channel ?? $this->channel(), 'https://shop.example.com', 'de_DE', ['de_DE', 'en_GB']);
    }

    private function variant(
        string $code,
        bool $enabled = true,
        bool $productEnabled = true,
        bool $inChannel = true,
        ?string $name = null,
        string $productName = 'Product',
    ): ProductVariantInterface&MockObject {
        $productTranslation = $this->createMock(ProductTranslationInterface::class);
        $productTranslation->method('getName')->willReturn($productName);
        $product = $this->createMock(ProductInterface::class);
        $product->method('isEnabled')->willReturn($productEnabled);
        $product->method('hasChannel')->willReturn($inChannel);
        $product->method('getCode')->willReturn('P_'.$code);
        $product->method('getTranslation')->willReturn($productTranslation);

        $variantTranslation = $this->createMock(ProductVariantTranslationInterface::class);
        $variantTranslation->method('getName')->willReturn($name);
        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn($code);
        $variant->method('isEnabled')->willReturn($enabled);
        $variant->method('getProduct')->willReturn($product);
        $variant->method('getTranslation')->willReturn($variantTranslation);

        return $variant;
    }

    private function item(?ProductVariantInterface $variant, int $unitPrice = 1000, int $quantity = 1, ?int $total = null, ?int $id = 7): OrderItemInterface&MockObject
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getVariant')->willReturn($variant);
        $item->method('getUnitPrice')->willReturn($unitPrice);
        $item->method('getQuantity')->willReturn($quantity);
        $item->method('getTotal')->willReturn($total ?? $unitPrice * $quantity);
        $item->method('getId')->willReturn($id);

        return $item;
    }

    /**
     * @param list<OrderItemInterface> $items
     */
    private function cart(
        array $items = [],
        string $token = 'acp_00000000000000000000000000000000_0000000000000000',
        ?\DateTimeInterface $updatedAt = null,
        int $itemsTotal = 1000,
        int $promotionTotal = 0,
        int $taxTotal = 0,
        int $taxExcluded = 0,
        string $state = 'cart',
        ?string $locale = 'de_DE',
    ): OrderInterface&MockObject {
        $cart = $this->createMock(OrderInterface::class);
        $cart->method('getItems')->willReturn(new ArrayCollection($items));
        $cart->method('getTokenValue')->willReturn($token);
        $cart->method('getCurrencyCode')->willReturn('EUR');
        $cart->method('getLocaleCode')->willReturn($locale);
        $cart->method('getUpdatedAt')->willReturn($updatedAt ?? new \DateTime());
        $cart->method('getItemsTotal')->willReturn($itemsTotal);
        $cart->method('getOrderPromotionTotal')->willReturn($promotionTotal);
        $cart->method('getTaxTotal')->willReturn($taxTotal);
        $cart->method('getTaxExcludedTotal')->willReturn($taxExcluded);
        $cart->method('getState')->willReturn($state);

        return $cart;
    }

    /**
     * @param array<string, ProductVariantInterface> $variants by code
     */
    private function store(
        array $variants = [],
        ?OrderInterface $newCart = null,
        ?OrderRepositoryInterface $orders = null,
        ?ObjectManager $manager = null,
        ?OrderModifierInterface $modifier = null,
        bool $stock = true,
        string $period = '2 days',
        ?OrderItemQuantityModifierInterface $quantities = null,
        ?FactoryInterface $itemFactory = null,
    ): AgentCartStore {
        $orderFactory = $this->createMock(FactoryInterface::class);
        $orderFactory->method('createNew')->willReturn($newCart ?? $this->createMock(OrderInterface::class));
        if (null === $itemFactory) {
            $itemFactory = $this->createMock(FactoryInterface::class);
            $itemFactory->method('createNew')->willReturnCallback(fn () => $this->createMock(OrderItemInterface::class));
        }
        $variantRepository = $this->createMock(ProductVariantRepositoryInterface::class);
        $variantRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria) => $variants[$criteria['code']] ?? null,
        );
        $availability = $this->createMock(AvailabilityCheckerInterface::class);
        $availability->method('isStockSufficient')->willReturn($stock);

        return new AgentCartStore(
            $orderFactory,
            $itemFactory,
            $quantities ?? $this->createMock(OrderItemQuantityModifierInterface::class),
            $modifier ?? $this->createMock(OrderModifierInterface::class),
            $orders ?? $this->createMock(OrderRepositoryInterface::class),
            $variantRepository,
            $availability,
            $manager ?? $this->createMock(ObjectManager::class),
            'test-secret',
            $period,
        );
    }
}
