<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests\Cart;

use Doctrine\Persistence\ObjectManager;
use Koh\SyliusAcp\Controller\CartHandoffController;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\OrderBundle\Controller\AddToCartCommandInterface;
use Sylius\Bundle\OrderBundle\Factory\AddToCartCommandFactoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\SyliusCartEvents;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CartHandoffControllerTest extends TestCase
{
    use CartFixtures;

    private const ID = 'acp_00000000000000000000000000000000_0000000000000000';

    private EventDispatcher $dispatcher;

    /** @var list<array{OrderInterface, int}> */
    private array $added = [];

    /** @var \WeakMap<OrderItemInterface, int> */
    private \WeakMap $quantities;

    protected function setUp(): void
    {
        $this->quantities = new \WeakMap();
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addListener(SyliusCartEvents::CART_ITEM_ADD, function (GenericEvent $event): void {
            $command = $event->getSubject();
            self::assertInstanceOf(AddToCartCommandInterface::class, $command);
            $cart = $command->getCart();
            self::assertInstanceOf(OrderInterface::class, $cart);
            $this->added[] = [$cart, $this->quantities[$command->getCartItem()] ?? 0];
        });
    }

    public function testTheLinesGoIntoTheBuyersCartThroughTheShopsAddToCartEvent(): void
    {
        $buyerCart = $this->buyerCart();
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::once())->method('persist')->with($buyerCart);
        $manager->expects(self::once())->method('flush');
        $request = $this->request();

        $response = $this->controller($this->agentCart(), $buyerCart, $manager)($request, self::ID);

        self::assertSame('/en_GB/sylius_shop_cart_summary', $response->headers->get('Location'));
        self::assertSame([[$buyerCart, 2], [$buyerCart, 1]], $this->added);
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame(['koh_acp.cart_handoff.added'], $this->flashes($request, 'success'));
    }

    public function testOpeningTheLinkAgainDoesNotAddTheItemsTwice(): void
    {
        $controller = $this->controller($this->agentCart(), $this->buyerCart());
        $request = $this->request();

        $controller($request, self::ID);
        $again = $controller($request, self::ID);

        self::assertCount(2, $this->added);
        self::assertSame('/de_DE/sylius_shop_cart_summary', $again->headers->get('Location'), 'the agent cart locale');
    }

    public function testAChangedAgentCartCanBeHandedOverAgain(): void
    {
        $request = $this->request();
        $this->controller($this->agentCart(new \DateTime('-2 hours')), $this->buyerCart())($request, self::ID);
        $this->controller($this->agentCart(new \DateTime('-1 hour')), $this->buyerCart())($request, self::ID);

        self::assertCount(4, $this->added);
    }

    public function testAGateThatRefusesStopsTheHandoffAndRollsBack(): void
    {
        $this->dispatcher->addListener(SyliusCartEvents::CART_ITEM_ADD, static function (): void {
            throw new \RuntimeException('age not confirmed');
        }, 512);
        $buyerCart = $this->buyerCart(id: 5);
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::once())->method('refresh')->with($buyerCart);
        $manager->expects(self::never())->method('flush');
        $request = $this->request();

        $response = $this->controller($this->agentCart(), $buyerCart, $manager)($request, self::ID);

        self::assertSame('/de_DE/sylius_shop_homepage', $response->headers->get('Location'));
        self::assertSame(['koh_acp.cart_handoff.blocked'], $this->flashes($request, 'info'));
        self::assertSame([], $this->added);

        // Once the buyer satisfied the gate, the same link works.
        self::assertNull($request->getSession()->get(CartHandoffController::SESSION_KEY));
    }

    public function testANewBuyerCartIsNotRefreshedAfterARefusal(): void
    {
        $this->dispatcher->addListener(SyliusCartEvents::CART_ITEM_ADD, static function (): void {
            throw new \RuntimeException('no');
        }, 512);
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::never())->method('refresh');

        $this->controller($this->agentCart(), $this->buyerCart(id: null), $manager)($this->request(), self::ID);
    }

    public function testAnExpiredOrUnknownCartLandsOnTheShopWithANotice(): void
    {
        $request = $this->request();

        $response = $this->controller(null, $this->buyerCart())($request, self::ID);

        self::assertSame('/de_DE/sylius_shop_homepage', $response->headers->get('Location'));
        self::assertSame(['koh_acp.cart_handoff.expired'], $this->flashes($request, 'info'));
    }

    public function testLinesWithoutAVariantAreSkipped(): void
    {
        $agentCart = $this->cart([$this->item(null), $this->item($this->variant('A'), quantity: 4)], token: self::ID);

        $this->controller($agentCart, $this->buyerCart())($this->request(), self::ID);

        self::assertSame(4, $this->added[0][1]);
        self::assertCount(1, $this->added);
    }

    public function testTheSessionRemembersOnlyTheLatestTwentyHandoffs(): void
    {
        $request = $this->request();
        $earlier = [];
        for ($i = 0; $i < 25; ++$i) {
            $earlier['acp_old_'.$i] = '1';
        }
        $request->getSession()->set(CartHandoffController::SESSION_KEY, $earlier);

        $this->controller($this->agentCart(), $this->buyerCart())($request, self::ID);

        $remembered = $request->getSession()->get(CartHandoffController::SESSION_KEY);
        self::assertIsArray($remembered);
        self::assertCount(20, $remembered);
        self::assertArrayHasKey(self::ID, $remembered);
        self::assertArrayHasKey('acp_old_6', $remembered);
        self::assertArrayNotHasKey('acp_old_5', $remembered);
    }

    public function testWithoutALocaleOnTheAgentCartTheChannelsIsUsed(): void
    {
        $this->dispatcher->addListener(SyliusCartEvents::CART_ITEM_ADD, static function (): void {
            throw new \RuntimeException('no');
        }, 512);
        $agentCart = $this->cart([$this->item($this->variant('A'))], token: self::ID, locale: null);

        $response = $this->controller($agentCart, $this->buyerCart())($this->request(), self::ID);

        self::assertSame('/de_DE/sylius_shop_homepage', $response->headers->get('Location'));
    }

    public function testConfiguredRoutesAreUsed(): void
    {
        $response = $this->controller($this->agentCart(), $this->buyerCart(), cartRoute: 'app_cart')($this->request(), self::ID);
        self::assertSame('/en_GB/app_cart', $response->headers->get('Location'));

        $response = $this->controller(null, $this->buyerCart(), fallbackRoute: 'app_home')($this->request(), self::ID);
        self::assertSame('/de_DE/app_home', $response->headers->get('Location'));
    }

    public function testOffMeans404(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller($this->agentCart(), $this->buyerCart(), enabled: false)($this->request(), self::ID);
    }

    public function testAForeignOrderModelIsALogicError(): void
    {
        $this->expectException(\LogicException::class);

        $this->controller($this->agentCart(), $this->createMock(BaseOrderInterface::class))($this->request(), self::ID);
    }

    private function agentCart(?\DateTimeInterface $updatedAt = null): OrderInterface
    {
        return $this->cart(
            [$this->item($this->variant('A'), quantity: 2), $this->item($this->variant('B'), quantity: 1)],
            token: self::ID,
            updatedAt: $updatedAt ?? new \DateTime('-1 hour'),
        );
    }

    private function buyerCart(?int $id = 5): OrderInterface
    {
        $cart = $this->createMock(OrderInterface::class);
        $cart->method('getId')->willReturn($id);
        $cart->method('getLocaleCode')->willReturn('en_GB');

        return $cart;
    }

    private function controller(
        ?OrderInterface $agentCart,
        BaseOrderInterface $buyerCart,
        ?ObjectManager $manager = null,
        bool $enabled = true,
        string $cartRoute = 'sylius_shop_cart_summary',
        string $fallbackRoute = 'sylius_shop_homepage',
    ): CartHandoffController {
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('findCartByTokenValueAndChannel')->willReturn($agentCart);
        $contexts = $this->createMock(AgentContextFactoryInterface::class);
        $contexts->method('create')->willReturn($this->context());
        $cartContext = $this->createMock(CartContextInterface::class);
        $cartContext->method('getCart')->willReturn($buyerCart);
        $itemFactory = $this->createMock(FactoryInterface::class);
        $itemFactory->method('createNew')->willReturnCallback(function (): OrderItemInterface {
            $item = $this->createMock(OrderItemInterface::class);
            $item->expects(self::once())->method('setVariant')->with(self::isInstanceOf(\Sylius\Component\Core\Model\ProductVariantInterface::class));

            return $item;
        });
        $quantities = $this->createMock(OrderItemQuantityModifierInterface::class);
        $quantities->method('modify')->willReturnCallback(function (OrderItemInterface $item, int $quantity): void {
            $this->quantities[$item] = $quantity;
        });
        $commands = $this->createMock(AddToCartCommandFactoryInterface::class);
        $commands->method('createWithCartAndCartItem')->willReturnCallback(function (BaseOrderInterface $cart, $item): AddToCartCommandInterface {
            $command = $this->createMock(AddToCartCommandInterface::class);
            $command->method('getCart')->willReturn($cart);
            $command->method('getCartItem')->willReturn($item);

            return $command;
        });
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route, array $parameters): string => '/'.$parameters['_locale'].'/'.$route);

        return new CartHandoffController(
            $this->store(orders: $orders),
            $contexts,
            $cartContext,
            $itemFactory,
            $quantities,
            $commands,
            $this->dispatcher,
            $manager ?? $this->createMock(ObjectManager::class),
            $router,
            $enabled,
            $cartRoute,
            $fallbackRoute,
        );
    }

    private function request(): Request
    {
        $request = Request::create('/acp-cart/'.self::ID);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /** @return list<string> */
    private function flashes(Request $request, string $type): array
    {
        $session = $request->getSession();
        self::assertInstanceOf(Session::class, $session);

        return $session->getFlashBag()->peek($type);
    }
}
