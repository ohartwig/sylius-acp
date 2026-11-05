<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Cart;

use Doctrine\Persistence\ObjectManager;
use Koh\SyliusAcp\Http\AcpError;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Product\Repository\ProductVariantRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;

/**
 * ACP carts kept as ordinary Sylius carts (orders in the `cart` state).
 *
 * The shop prices them with its own order processors — channel pricing,
 * catalogue and cart promotions, taxes — so the estimate an agent sees is the
 * one the buyer will see. An agent cart belongs to no customer and to no
 * session: it is a draft that only becomes the buyer's when the buyer opens
 * `continue_url` (see CartHandoffController), and Sylius' own expired-carts
 * cleanup removes it like any abandoned cart.
 *
 * Its id is the order's token: a prefix, so that this API can only ever reach
 * carts it created and never a shopper's session cart, a random nonce, and a
 * seal binding the cart to the agent platform that created it — another
 * platform's key does not open it.
 */
final class AgentCartStore
{
    public const TOKEN_PREFIX = 'acp_';

    /**
     * @param FactoryInterface<OrderInterface>                           $orderFactory
     * @param FactoryInterface<OrderItemInterface>                       $orderItemFactory
     * @param OrderRepositoryInterface<OrderInterface>                   $orderRepository
     * @param ProductVariantRepositoryInterface<ProductVariantInterface> $variantRepository
     * @param string                                                     $secret           binds a cart id to the caller that created it
     * @param string                                                     $expirationPeriod Sylius' `sylius_order.cart_expiration_period`
     */
    public function __construct(
        private readonly FactoryInterface $orderFactory,
        private readonly FactoryInterface $orderItemFactory,
        private readonly OrderItemQuantityModifierInterface $quantityModifier,
        private readonly OrderModifierInterface $orderModifier,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ProductVariantRepositoryInterface $variantRepository,
        private readonly AvailabilityCheckerInterface $availabilityChecker,
        private readonly ObjectManager $orderManager,
        private readonly string $secret,
        private readonly string $expirationPeriod = '2 days',
    ) {
    }

    public function create(ChannelInterface $channel, string $localeCode, CartRequest $request, string $owner): CartWrite
    {
        [$resolved, $messages] = $this->resolve($channel, $request);

        $cart = $this->orderFactory->createNew();
        $cart->setChannel($channel);
        $cart->setCurrencyCode($channel->getBaseCurrency()?->getCode());
        $cart->setLocaleCode($localeCode);
        $nonce = bin2hex(random_bytes(16));
        $cart->setTokenValue(self::TOKEN_PREFIX.$nonce.'_'.$this->seal($nonce, $owner));

        $this->fill($cart, $resolved);
        // Sylius' expired-carts cleanup and expires_at both read it.
        $cart->setUpdatedAt(new \DateTime());

        $this->orderManager->persist($cart);
        $this->orderManager->flush();

        return new CartWrite($cart, $messages);
    }

    public function replace(OrderInterface $cart, CartRequest $request): CartWrite
    {
        $channel = $cart->getChannel();
        if (!$channel instanceof ChannelInterface) {
            throw new \LogicException('An ACP cart always has a channel.');
        }

        // Resolve first: a request in which nothing is available leaves the
        // cart as it was instead of emptying it.
        [$resolved, $messages] = $this->resolve($channel, $request);

        $cart->clearItems();
        $this->fill($cart, $resolved);
        $cart->setUpdatedAt(new \DateTime());

        $this->orderManager->flush();

        return new CartWrite($cart, $messages);
    }

    /**
     * @param string|null $owner the caller's identity; null only for the buyer
     *                           handoff, where holding the id is the permission
     */
    public function find(string $id, ChannelInterface $channel, ?string $owner): ?OrderInterface
    {
        if (1 !== preg_match('/^'.self::TOKEN_PREFIX.'([0-9a-f]{32})_([0-9a-f]{16})$/', $id, $parts)) {
            return null;
        }
        if (null !== $owner && !hash_equals($this->seal($parts[1], $owner), $parts[2])) {
            return null;
        }

        $cart = $this->orderRepository->findCartByTokenValueAndChannel($id, $channel);
        if (!$cart instanceof OrderInterface || BaseOrderInterface::STATE_CART !== $cart->getState()) {
            return null;
        }

        return $this->expiresAt($cart) > new \DateTimeImmutable() ? $cart : null;
    }

    public function cancel(OrderInterface $cart): void
    {
        $this->orderManager->remove($cart);
        $this->orderManager->flush();
    }

    /**
     * When Sylius' expired-carts cleanup may remove the cart: the expiration
     * period after its last change.
     */
    public function expiresAt(OrderInterface $cart): \DateTimeImmutable
    {
        $touched = $cart->getUpdatedAt() ?? $cart->getCreatedAt() ?? new \DateTime();

        return \DateTimeImmutable::createFromInterface($touched)->modify('+'.$this->expirationPeriod);
    }

    private function seal(string $nonce, string $owner): string
    {
        return substr(hash_hmac('sha256', $nonce."\0".$owner, $this->secret), 0, 16);
    }

    /**
     * @param list<array{ProductVariantInterface, int}> $resolved
     */
    private function fill(OrderInterface $cart, array $resolved): void
    {
        foreach ($resolved as [$variant, $quantity]) {
            $item = $this->orderItemFactory->createNew();
            $item->setVariant($variant);
            $this->quantityModifier->modify($item, $quantity);
            // Merges repeated variants and runs the order processors.
            $this->orderModifier->addToOrder($cart, $item);
        }
    }

    /**
     * Unknown, disabled or foreign-channel items are dropped with an error
     * message each; the RFC forbids dropping them silently. Items short of
     * stock stay in the cart and are flagged by CartPresenter, so the agent
     * decides. Nothing available at all is a 422, as the RFC allows.
     *
     * @return array{list<array{ProductVariantInterface, int}>, list<array<string, string>>}
     */
    private function resolve(ChannelInterface $channel, CartRequest $request): array
    {
        $resolved = [];
        $messages = [];

        foreach ($request->lines as $index => $line) {
            $variant = $this->variantRepository->findOneBy(['code' => $line->id]);
            if ($this->purchasable($variant, $channel)) {
                \assert($variant instanceof ProductVariantInterface);
                $resolved[] = [$variant, $line->quantity];

                continue;
            }

            $messages[] = [
                'type' => 'error',
                'code' => 'not_found',
                'severity' => 'medium',
                'resolution' => 'recoverable',
                'param' => \sprintf('$.line_items[%d]', $index),
                'content_type' => 'plain',
                'content' => \sprintf('Item "%s" is not sold in this shop; it was not added.', $line->id),
            ];
        }

        if ([] === $resolved) {
            throw AcpError::invalid('out_of_stock', 'None of the requested items is available.', '$.line_items', 422);
        }

        return [$resolved, $messages];
    }

    private function purchasable(?object $variant, ChannelInterface $channel): bool
    {
        if (!$variant instanceof ProductVariantInterface || !$variant->isEnabled()) {
            return false;
        }

        $product = $variant->getProduct();

        return $product instanceof ProductInterface && $product->isEnabled() && $product->hasChannel($channel);
    }

    public function isStockSufficient(OrderItemInterface $item): bool
    {
        $variant = $item->getVariant();

        return null !== $variant && $this->availabilityChecker->isStockSufficient($variant, $item->getQuantity());
    }
}
