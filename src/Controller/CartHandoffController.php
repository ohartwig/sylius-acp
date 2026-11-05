<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Controller;

use Koh\SyliusAcp\Cart\AgentCartStore;
use Koh\SyliusAgentContracts\Cart\CartHandoff;
use Koh\SyliusAgentContracts\Cart\CartHandoffLine;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
 *
 * The handoff itself is {@see CartHandoff}, shared with the UCP permalink;
 * this controller finds the agent cart and picks notices and destinations.
 */
final class CartHandoffController
{
    public const SESSION_KEY = 'koh_acp.handoffs';

    public function __construct(
        private readonly AgentCartStore $store,
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly CartHandoff $handoff,
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
        if ($this->handoff->wasHandedOver($session, self::SESSION_KEY, $id, $version)) {
            return $this->redirect($this->cartRoute, $locale);
        }

        $result = $this->handoff->handOver(self::lines($agentCart));
        if ($result->isRefused()) {
            return $this->fallback($session, 'koh_acp.cart_handoff.blocked', $locale);
        }

        $this->handoff->remember($session, self::SESSION_KEY, $id, $version);
        $this->handoff->flash($session, 'success', 'koh_acp.cart_handoff.added');

        return $this->redirect($this->cartRoute, $result->cart?->getLocaleCode() ?? $locale);
    }

    /**
     * Lines whose variant has gone since the agent built the cart are skipped.
     *
     * @return list<CartHandoffLine>
     */
    private static function lines(OrderInterface $agentCart): array
    {
        $lines = [];
        foreach ($agentCart->getItems() as $agentItem) {
            $variant = $agentItem->getVariant();
            if (null !== $variant) {
                $lines[] = new CartHandoffLine($variant, $agentItem->getQuantity());
            }
        }

        return $lines;
    }

    private function fallback(SessionInterface $session, string $message, string $locale): Response
    {
        $this->handoff->flash($session, 'info', $message);

        return $this->redirect($this->fallbackRoute, $locale);
    }

    private function redirect(string $route, string $locale): Response
    {
        return $this->handoff->redirect($this->urlGenerator->generate($route, ['_locale' => $locale]));
    }
}
