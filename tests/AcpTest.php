<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests;

use Koh\SyliusAcp\Controller\AcpDiscoveryController;
use Koh\SyliusAcp\Feed\FeedProductSource;
use Koh\SyliusAcp\Feed\ProductFeedBuilder;
use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTranslationInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Product\Model\ProductVariantTranslationInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AcpTest extends TestCase
{
    public function testDiscoveryDocumentIdentifiesTheProtocol(): void
    {
        $doc = $this->discovery(checkout: true);

        self::assertSame([
            'name' => 'acp',
            'version' => '2026-04-17',
            'supported_versions' => ['2026-04-17'],
            'documentation_url' => 'https://agenticcommerce.dev',
        ], $doc['protocol']);
        self::assertSame(['rest'], $doc['transports']);
        self::assertSame('https://shop.example.com/api/agent', $doc['api_base_url']);
        self::assertSame(['protocol', 'api_base_url', 'transports', 'capabilities'], array_keys($doc));
    }

    /**
     * `services` is a closed enum of ACP operations. The bundle implements none
     * of them, so without checkout there is nothing to declare, and the
     * discovery RFC's answer for "no ACP" is 404.
     */
    public function testWithoutCheckoutThereIsNoDiscoveryDocument(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->discovery();
    }

    /**
     * An agent reading `checkout` will try to complete a purchase through the
     * API. For an age-restricted catalogue that has to happen in a browser
     * session where the buyer can be verified, so it is opt-in.
     */
    public function testCheckoutIsTheOnlyServiceWhenSwitchedOn(): void
    {
        self::assertSame(['checkout'], $this->discovery(checkout: true)['capabilities']['services']);
    }

    /** carts is the service this bundle implements itself. */
    public function testCartsAreDeclaredWhenSwitchedOn(): void
    {
        self::assertSame(['carts'], $this->discovery(carts: true)['capabilities']['services']);
        self::assertSame(['checkout', 'carts'], $this->discovery(checkout: true, carts: true)['capabilities']['services']);
    }

    public function testTheDocumentIsPubliclyCacheableForAnHour(): void
    {
        $response = $this->controller(checkout: true)(new Request());

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('3600', $response->headers->getCacheControlDirective('max-age'));
    }

    public function testCurrenciesAndLocalesUseTheirWireFormats(): void
    {
        $capabilities = $this->discovery(checkout: true)['capabilities'];

        self::assertSame(['eur'], $capabilities['supported_currencies']);
        self::assertSame(['de-DE', 'en-GB'], $capabilities['supported_locales']);
    }

    /** One row per variant: a product with three prices cannot be one row without lying about two. */
    public function testFeedIsSkuGranular(): void
    {
        $feed = $this->feed();

        self::assertCount(2, $feed['items']);
        self::assertSame(['VAR-1', 'VAR-2'], array_column($feed['items'], 'id'));
        self::assertSame(['PROD-1', 'PROD-1'], array_column($feed['items'], 'item_group_id'));
    }

    /** Minor units and currency separately — arithmetic on a formatted string breaks per locale. */
    public function testPriceIsMinorUnitsWithACurrencyBesideIt(): void
    {
        $item = $this->feed()['items'][0];

        self::assertSame('1250', $item['price']);
        self::assertSame('EUR', $item['price_currency']);
    }

    public function testAvailabilityReflectsStock(): void
    {
        self::assertSame(['in_stock', 'out_of_stock'], array_column($this->feed()['items'], 'availability'));
    }

    /** A feed that omits it invites an agent to treat a restricted item as ordinary stock. */
    public function testAgeRestrictionIsCarriedOnEveryItem(): void
    {
        foreach ($this->feed(ageRestriction: 18)['items'] as $item) {
            self::assertSame(18, $item['age_restriction']);
        }
    }

    public function testAgeRestrictionIsAbsentWhenNotConfigured(): void
    {
        self::assertArrayNotHasKey('age_restriction', $this->feed()['items'][0]);
    }

    public function testLimitIsCappedSoOneRequestCannotAskForEverything(): void
    {
        self::assertSame(500, $this->feed(limit: 100000)['limit']);
    }

    /**
     * The cap has to hold at the bottom too. A negative limit reaches the
     * repository as a negative max-result, which Doctrine reads as "no limit" —
     * so `?limit=-1` would walk straight past max_limit and return the whole
     * catalogue from a route that needs no credentials.
     */
    public function testNegativeLimitCannotBypassTheCap(): void
    {
        self::assertSame(1, $this->feed(limit: -1)['limit']);
    }

    public function testZeroLimitIsRaisedToAWorkingPage(): void
    {
        self::assertSame(1, $this->feed(limit: 0)['limit']);
    }

    /** @return array<string, mixed> */
    private function discovery(bool $checkout = false, bool $carts = false): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $this->controller($checkout, $carts)(new Request())->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function controller(bool $checkout, bool $carts = false): AcpDiscoveryController
    {
        // The defaults are what an installation gets without configuration.
        return new AcpDiscoveryController($this->contextFactory(), new AgentJsonResponder(), checkoutEnabled: $checkout, cartsEnabled: $carts);
    }

    /** @return array{items: list<array<string, mixed>>, page: int, limit: int, count: int} */
    private function feed(?int $ageRestriction = null, ?int $limit = null): array
    {
        $repository = $this->createStub(FeedProductSource::class);
        $repository->method('findPage')->willReturn([$this->product()]);

        return (new ProductFeedBuilder($repository, $ageRestriction))->build($this->context(), 1, $limit);
    }

    private function product(): ProductInterface
    {
        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getName')->willReturn('Zigarre');
        $translation->method('getSlug')->willReturn('zigarre');
        $translation->method('getShortDescription')->willReturn('Handrolled.');

        $product = $this->createMock(ProductInterface::class);
        $product->method('getCode')->willReturn('PROD-1');
        $product->method('getTranslation')->willReturn($translation);
        $product->method('getEnabledVariants')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([
            $this->variant('VAR-1', 1250, true),
            $this->variant('VAR-2', 2500, false),
        ]));

        return $product;
    }

    private function variant(string $code, int $price, bool $inStock): ProductVariantInterface
    {
        $translation = $this->createMock(ProductVariantTranslationInterface::class);
        $translation->method('getName')->willReturn($code);

        $pricing = $this->createMock(ChannelPricingInterface::class);
        $pricing->method('getPrice')->willReturn($price);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn($code);
        $variant->method('getTranslation')->willReturn($translation);
        $variant->method('getChannelPricingForChannel')->willReturn($pricing);
        $variant->method('isInStock')->willReturn($inStock);

        return $variant;
    }

    private function contextFactory(): AgentContextFactoryInterface
    {
        $factory = $this->createMock(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn($this->context());

        return $factory;
    }

    private function context(): AgentContext
    {
        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getCode')->willReturn('EUR');

        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');
        $channel->method('getName')->willReturn('Example Shop');
        $channel->method('getBaseCurrency')->willReturn($currency);

        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE', 'en_GB']);
    }
}
