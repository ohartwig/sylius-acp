<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Controller;

use Doctrine\Persistence\ObjectManager;
use Koh\SyliusAcp\Cart\AgentCartStore;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Sylius\Bundle\OrderBundle\Factory\AddToCartCommandFactoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\SyliusCartEvents;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * `continue_url` of an ACP cart: hands the agent's cart to the buyer.
 *
 * The buyer opens the link in their browser. Each line goes into the buyer's
 * own cart through `sylius.cart_item_add` — the event the shop's add-to-cart
 * button fires — so every gate the shop hangs on that event (age confirmation,
 * quantity rules) runs for the buyer exactly as if they had clicked. If a gate
 * refuses, nothing is added and the buyer lands on the shop with a notice; once
 * they have satisfied it, the same link works. From the cart the buyer checks
 * out normally: address, age declaration, shipping and payment are the shop's.
 *
 * Opening the link twice does not add the items twice: the session remembers
 * which version of which agent cart it took over.
 */
final class CartHandoffController
{
    public const SESSION_KEY = 'koh_acp.handoffs';

    /**
     * @param FactoryInterface<OrderItemInterface> $orderItemFactory
     */
    public function __construct(
        private readonly AgentCartStore $store,
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly CartContextInterface $cartContext,
        private readonly FactoryInterface $orderItemFactory,
        private readonly OrderItemQuantityModifierInterface $quantityModifier,
        private readonly AddToCartCommandFactoryInterface $addToCartCommandFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ObjectManager $orderManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly bool $enabled,
        private readonly string $cartRoute,
        private readonly string $fallbackRoute,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if (!$this->enabled) {
            throw new NotFoundHttpException('This shop does not offer ACP carts.');
        }

        $context = $this->contextFactory->create();
        $session = $request->getSession();
        $agentCart = $this->store->find($id, $context->channel, null);
        if (null === $agentCart) {
            return $this->fallback($session, 'koh_acp.cart_handoff.expired', $context->localeCode);
        }

        $locale = $agentCart->getLocaleCode() ?? $context->localeCode;
        $version = (string) ($agentCart->getUpdatedAt() ?? $agentCart->getCreatedAt())?->format('U.u');
        $handoffs = $session->get(self::SESSION_KEY, []);
        $handoffs = \is_array($handoffs) ? $handoffs : [];
        if (($handoffs[$id] ?? null) === $version) {
            return $this->redirect($this->cartRoute, $locale);
        }

        $buyerCart = $this->cartContext->getCart();
        if (!$buyerCart instanceof OrderInterface) {
            throw new \LogicException(\sprintf('The ACP cart handoff needs a Sylius Core order, got %s.', $buyerCart::class));
        }

        try {
            foreach ($agentCart->getItems() as $agentItem) {
                $variant = $agentItem->getVariant();
                if (null === $variant) {
                    continue;
                }

                $item = $this->orderItemFactory->createNew();
                $item->setVariant($variant);
                $this->quantityModifier->modify($item, $agentItem->getQuantity());

                $this->eventDispatcher->dispatch(
                    new GenericEvent($this->addToCartCommandFactory->createWithCartAndCartItem($buyerCart, $item)),
                    SyliusCartEvents::CART_ITEM_ADD,
                );
            }
        } catch (\RuntimeException) {
            // A gate said no. Undo what earlier lines changed in memory, so a
            // later flush elsewhere cannot persist half a handoff.
            if (null !== $buyerCart->getId()) {
                $this->orderManager->refresh($buyerCart);
            }

            return $this->fallback($session, 'koh_acp.cart_handoff.blocked', $locale);
        }

        $this->orderManager->persist($buyerCart);
        $this->orderManager->flush();

        $handoffs[$id] = $version;
        $session->set(self::SESSION_KEY, \array_slice($handoffs, -20, null, true));
        $this->flash($session, 'success', 'koh_acp.cart_handoff.added');

        return $this->redirect($this->cartRoute, $buyerCart->getLocaleCode() ?? $locale);
    }

    private function fallback(SessionInterface $session, string $message, string $locale): Response
    {
        $this->flash($session, 'info', $message);

        return $this->redirect($this->fallbackRoute, $locale);
    }

    private function flash(SessionInterface $session, string $type, string $message): void
    {
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    private function redirect(string $route, string $locale): Response
    {
        $response = new RedirectResponse($this->urlGenerator->generate($route, ['_locale' => $locale]));
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
