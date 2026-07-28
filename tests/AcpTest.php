<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests;

use Koh\SyliusAcp\Controller\AcpDiscoveryController;
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
use Sylius\Component\Product\Model\ProductVariantTranslationInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Symfony\Component\HttpFoundation\Request;

final class AcpTest extends TestCase
{
    public function testDiscoveryDocumentIdentifiesTheProtocol(): void
    {
        $doc = $this->discovery();

        self::assertSame('acp', $doc['protocol']['name']);
        self::assertSame('2026-01-30', $doc['protocol']['version']);
        self::assertSame(['rest'], $doc['transports']);
        self::assertSame('https://shop.example.com/api/agent', $doc['api_base_url']);
    }

    /**
     * An agent reading `checkout` will try to complete a purchase through the
     * API. For an age-restricted catalogue that has to happen in a browser
     * session where the buyer can be verified.
     */
    public function testCheckoutIsNotDeclaredByDefault(): void
    {
        self::assertSame(['catalog'], $this->discovery()['capabilities']['services']);
    }

    public function testCheckoutAppearsOnlyWhenSwitchedOn(): void
    {
        self::assertSame(['catalog', 'checkout'], $this->discovery(checkout: true)['capabilities']['services']);
    }

    public function testCurrenciesAndLocalesUseTheirWireFormats(): void
    {
        $capabilities = $this->discovery()['capabilities'];

        self::assertSame(['eur'], $capabilities['supported_currencies']);
        self::assertSame(['de-DE'], $capabilities['supported_locales']);
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
    private function discovery(bool $checkout = false): array
    {
        $controller = new AcpDiscoveryController(
            $this->contextFactory(),
            new AgentJsonResponder(),
            '2026-01-30',
            '/api/agent',
            $checkout,
        );

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $controller(new Request())->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** @return array{items: list<array<string, mixed>>, page: int, limit: int, count: int} */
    private function feed(?int $ageRestriction = null, ?int $limit = null): array
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('findLatestByChannel')->willReturn([$this->product()]);

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
        $channel->method('getName')->willReturn('Tabak Budde');
        $channel->method('getBaseCurrency')->willReturn($currency);

        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']);
    }
}
