<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests\Cart;

use Doctrine\Persistence\ObjectManager;
use Koh\SyliusAcp\Cart\CartLine;
use Koh\SyliusAcp\Cart\CartRequest;
use Koh\SyliusAcp\Http\AcpError;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;

final class AgentCartStoreTest extends TestCase
{
    use CartFixtures;

    public function testACartIsASyliusCartOfTheChannelWithASealedToken(): void
    {
        $channel = $this->channel();
        $cart = $this->createMock(OrderInterface::class);
        $cart->expects(self::once())->method('setChannel')->with($channel);
        $cart->expects(self::once())->method('setCurrencyCode')->with('EUR');
        $cart->expects(self::once())->method('setLocaleCode')->with('en_GB');
        $cart->expects(self::once())->method('setUpdatedAt')->with(self::isInstanceOf(\DateTime::class));
        $token = null;
        $cart->expects(self::once())->method('setTokenValue')->willReturnCallback(function (string $value) use (&$token): void {
            $token = $value;
        });
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::once())->method('persist')->with($cart);
        $manager->expects(self::once())->method('flush');
        $modifier = $this->createMock(OrderModifierInterface::class);
        $modifier->expects(self::exactly(2))->method('addToOrder')->with($cart, self::isInstanceOf(OrderItemInterface::class));

        $write = $this->store(['A' => $this->variant('A'), 'B' => $this->variant('B')], $cart, manager: $manager, modifier: $modifier)
            ->create($channel, 'en_GB', $this->request('A', 'B'), 'key_one');

        self::assertSame($cart, $write->cart);
        self::assertSame([], $write->messages);
        self::assertMatchesRegularExpression('/^acp_[0-9a-f]{32}_[0-9a-f]{16}$/', (string) $token);
    }

    public function testEachLineBecomesAnItemOfItsVariantAndQuantity(): void
    {
        $variant = $this->variant('A');
        $item = $this->createMock(OrderItemInterface::class);
        $item->expects(self::once())->method('setVariant')->with($variant);
        $items = $this->createMock(\Sylius\Resource\Factory\FactoryInterface::class);
        $items->method('createNew')->willReturn($item);
        $quantities = $this->createMock(\Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface::class);
        $quantities->expects(self::once())->method('modify')->with($item, 4);

        $this->store(['A' => $variant], quantities: $quantities, itemFactory: $items)
            ->create($this->channel(), 'de_DE', new CartRequest([new CartLine('A', 4)], null, false), 'key_one');
    }

    public function testTheSealIsAnHmacOfNonceAndOwner(): void
    {
        $token = $this->createdToken($this->channel(), 'key_one');
        [, $nonce, $seal] = explode('_', $token);

        self::assertSame(substr(hash_hmac('sha256', $nonce."\0".'key_one', 'test-secret'), 0, 16), $seal);
    }

    public function testUnavailableItemsAreDroppedWithAMessageEach(): void
    {
        $variants = [
            'OK' => $this->variant('OK'),
            'OFF' => $this->variant('OFF', enabled: false),
            'GONE' => $this->variant('GONE', productEnabled: false),
            'ELSEWHERE' => $this->variant('ELSEWHERE', inChannel: false),
        ];
        $modifier = $this->createMock(OrderModifierInterface::class);
        $modifier->expects(self::once())->method('addToOrder');

        $write = $this->store($variants, modifier: $modifier)
            ->create($this->channel(), 'de_DE', $this->request('OFF', 'OK', 'GONE', 'ELSEWHERE', 'UNKNOWN'), 'key_one');

        self::assertSame(['$.line_items[0]', '$.line_items[2]', '$.line_items[3]', '$.line_items[4]'], array_column($write->messages, 'param'));
        self::assertSame([
            'type' => 'error',
            'code' => 'not_found',
            'severity' => 'medium',
            'resolution' => 'recoverable',
            'param' => '$.line_items[0]',
            'content_type' => 'plain',
            'content' => 'Item "OFF" is not sold in this shop; it was not added.',
        ], $write->messages[0]);
    }

    public function testNothingAvailableIsA422AndNoCart(): void
    {
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::never())->method('persist');

        try {
            $this->store(manager: $manager)->create($this->channel(), 'de_DE', $this->request('X'), 'key_one');
            self::fail('Expected a 422');
        } catch (AcpError $error) {
            self::assertSame(422, $error->status);
            self::assertSame('out_of_stock', $error->errorCode);
            self::assertSame('$.line_items', $error->param);
        }
    }

    public function testReplaceSwapsTheLinesAndTouchesTheCart(): void
    {
        $channel = $this->channel();
        $cart = $this->createMock(OrderInterface::class);
        $cart->method('getChannel')->willReturn($channel);
        $cart->expects(self::once())->method('clearItems');
        $cart->expects(self::once())->method('setUpdatedAt');
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::once())->method('flush');
        $modifier = $this->createMock(OrderModifierInterface::class);
        $modifier->expects(self::once())->method('addToOrder')->with($cart);

        $write = $this->store(['A' => $this->variant('A')], manager: $manager, modifier: $modifier)->replace($cart, $this->request('A', 'NOPE'));

        self::assertSame($cart, $write->cart);
        self::assertCount(1, $write->messages);
    }

    public function testAReplaceWithNothingAvailableLeavesTheCartAlone(): void
    {
        $cart = $this->createMock(OrderInterface::class);
        $cart->method('getChannel')->willReturn($this->channel());
        $cart->expects(self::never())->method('clearItems');

        $this->expectException(AcpError::class);
        $this->store()->replace($cart, $this->request('NOPE'));
    }

    public function testAReplaceNeedsAChannel(): void
    {
        $this->expectException(\LogicException::class);
        $this->store()->replace($this->createMock(OrderInterface::class), $this->request('A'));
    }

    public function testOnlyTheCreatorFindsTheCart(): void
    {
        $channel = $this->channel();
        $token = $this->createdToken($channel, 'key_one');
        $stored = $this->cart(token: $token);
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('findCartByTokenValueAndChannel')->with($token, $channel)->willReturn($stored);
        $store = $this->store(orders: $orders);

        self::assertSame($stored, $store->find($token, $channel, 'key_one'));
        self::assertNull($store->find($token, $channel, 'key_two'));
        self::assertSame($stored, $store->find($token, $channel, null), 'the buyer handoff holds the id, not a key');
    }

    public function testIdsThatAreNotAgentCartsAreNotLookedUp(): void
    {
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->expects(self::never())->method('findCartByTokenValueAndChannel');
        $store = $this->store(orders: $orders);

        foreach (['a-shoppers-session-token', 'acp_short_0000000000000000', 'acp_'.str_repeat('0', 32).'_'.str_repeat('0', 17), 'xacp_'.str_repeat('0', 32).'_'.str_repeat('0', 16)] as $id) {
            self::assertNull($store->find($id, $this->channel(), null), $id);
        }
    }

    public function testCompletedExpiredOrMissingCartsAreNotFound(): void
    {
        $id = 'acp_'.str_repeat('0', 32).'_'.str_repeat('0', 16);
        foreach ([
            $this->cart(state: 'new'),
            $this->cart(updatedAt: new \DateTime('-3 days')),
            null,
        ] as $stored) {
            $orders = $this->createMock(OrderRepositoryInterface::class);
            $orders->method('findCartByTokenValueAndChannel')->willReturn($stored);

            self::assertNull($this->store(orders: $orders)->find($id, $this->channel(), null));
        }

        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('findCartByTokenValueAndChannel')->willReturn($fresh = $this->cart(updatedAt: new \DateTime('-1 day')));
        self::assertSame($fresh, $this->store(orders: $orders)->find($id, $this->channel(), null));
    }

    public function testACartExpiresOneSyliusPeriodAfterItsLastChange(): void
    {
        $cart = $this->createMock(OrderInterface::class);
        $cart->method('getUpdatedAt')->willReturn(new \DateTime('2026-11-05 10:00:00 UTC'));

        self::assertSame('2026-11-07T10:00:00+00:00', $this->store()->expiresAt($cart)->format(\DATE_ATOM));
        self::assertSame('2026-11-05T13:00:00+00:00', $this->store(period: '3 hours')->expiresAt($cart)->format(\DATE_ATOM));

        $both = $this->createMock(OrderInterface::class);
        $both->method('getCreatedAt')->willReturn(new \DateTime('2026-11-01 00:00:00 UTC'));
        $both->method('getUpdatedAt')->willReturn(new \DateTime('2026-11-05 10:00:00 UTC'));
        self::assertSame('2026-11-07T10:00:00+00:00', $this->store()->expiresAt($both)->format(\DATE_ATOM), 'the last change counts');

        $new = $this->createMock(OrderInterface::class);
        $new->method('getCreatedAt')->willReturn(new \DateTime('2026-11-01 00:00:00 UTC'));
        self::assertSame('2026-11-03T00:00:00+00:00', $this->store()->expiresAt($new)->format(\DATE_ATOM));
    }

    public function testCancelRemovesTheCart(): void
    {
        $cart = $this->createMock(OrderInterface::class);
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::once())->method('remove')->with($cart);
        $manager->expects(self::once())->method('flush');

        $this->store(manager: $manager)->cancel($cart);
    }

    public function testStockIsCheckedForTheLinesQuantity(): void
    {
        self::assertTrue($this->store(stock: true)->isStockSufficient($this->item($this->variant('A'))));
        self::assertFalse($this->store(stock: false)->isStockSufficient($this->item($this->variant('A'))));
        self::assertFalse($this->store(stock: true)->isStockSufficient($this->item(null)));
    }

    private function createdToken(\Sylius\Component\Core\Model\ChannelInterface $channel, string $owner): string
    {
        $token = '';
        $cart = $this->createMock(OrderInterface::class);
        $cart->method('setTokenValue')->willReturnCallback(function (string $value) use (&$token): void {
            $token = $value;
        });
        $this->store(['A' => $this->variant('A')], $cart)->create($channel, 'de_DE', $this->request('A'), $owner);

        return $token;
    }

    private function request(string ...$ids): CartRequest
    {
        return new CartRequest(array_map(static fn (string $id): CartLine => new CartLine($id, 1), array_values($ids)), null, false);
    }
}
