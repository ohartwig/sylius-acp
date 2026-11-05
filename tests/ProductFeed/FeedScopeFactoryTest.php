<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests\ProductFeed;

use Koh\SyliusAcp\ProductFeed\FeedConfigurationException;
use Koh\SyliusAcp\ProductFeed\FeedScopeFactory;
use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Addressing\Model\Country;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ShopBillingData;

#[CoversClass(FeedScopeFactory::class)]
final class FeedScopeFactoryTest extends TestCase
{
    public function testTheOnlyEnabledChannelWithItsDefaultLocaleAndDerivedValues(): void
    {
        $scope = $this->factory([FeedFixtures::channel()])->create();

        self::assertSame('WEB', $scope->channel->getCode());
        self::assertSame('de_DE', $scope->localeCode);
        self::assertSame('https://shop.example.com', $scope->baseUrl);
        self::assertSame('EUR', $scope->currency);
        self::assertSame('DE', $scope->targetCountry);
        self::assertSame(['name' => 'Example Shop'], $scope->seller);
        self::assertSame('/media/image/', $scope->mediaPathPrefix);
    }

    public function testOptionsWinOverConfiguration(): void
    {
        $web = FeedFixtures::channel('WEB');
        $other = FeedFixtures::channel('OTHER');
        $factory = $this->factory([$web, $other], defaultChannel: 'WEB', defaultLocale: 'de_DE', defaultTargetCountry: 'AT');

        $scope = $factory->create('OTHER', 'en_US', 'ch');

        self::assertSame($other, $scope->channel);
        self::assertSame('en_US', $scope->localeCode);
        self::assertSame('CH', $scope->targetCountry);

        $configured = $factory->create(' ', '');
        self::assertSame($web, $configured->channel);
        self::assertSame('de_DE', $configured->localeCode);
        self::assertSame('AT', $configured->targetCountry);
    }

    public function testSeveralEnabledChannelsNeedAName(): void
    {
        $this->expectException(FeedConfigurationException::class);
        $this->expectExceptionMessage('2 enabled channels');

        $this->factory([FeedFixtures::channel('A'), FeedFixtures::channel('B')])->create();
    }

    public function testAnUnknownChannelIsRefused(): void
    {
        $this->expectException(FeedConfigurationException::class);
        $this->expectExceptionMessage('Channel "NOPE" does not exist.');

        $this->factory([FeedFixtures::channel()])->create('NOPE');
    }

    public function testALocaleTheChannelDoesNotOfferIsRefused(): void
    {
        $this->expectException(FeedConfigurationException::class);
        $this->expectExceptionMessage('Locale "fr_FR" is not enabled on channel "WEB" (enabled: de_DE, en_US).');

        $this->factory([FeedFixtures::channel()])->create(null, 'fr_FR');
    }

    public function testAChannelWithoutHostnameCannotBePublished(): void
    {
        $this->expectException(FeedConfigurationException::class);
        $this->expectExceptionMessage('Channel "WEB" has no hostname');

        $this->factory([FeedFixtures::channel()], baseUrl: '')->create();
    }

    public function testAChannelWithoutCurrencyCannotBePublished(): void
    {
        $channel = FeedFixtures::channel();
        $channel->setBaseCurrency(null);

        $this->expectException(FeedConfigurationException::class);
        $this->expectExceptionMessage('Channel "WEB" has no ISO 4217 base currency.');

        $this->factory([$channel])->create();
    }

    public function testAnInvalidExplicitTargetCountryIsRefused(): void
    {
        $this->expectException(FeedConfigurationException::class);
        $this->expectExceptionMessage('Target country "DEU" is not an ISO 3166-1 alpha-2 code.');

        $this->factory([FeedFixtures::channel()])->create(null, null, 'deu');
    }

    public function testWithSeveralCountriesTheBillingCountryIsTheMarket(): void
    {
        $channel = FeedFixtures::channel();
        $austria = new Country();
        $austria->setCode('AT');
        $channel->addCountry($austria);
        $billing = new ShopBillingData();
        $billing->setCountryCode('at');
        $billing->setCompany('Example Trading GmbH');
        $channel->setShopBillingData($billing);

        $scope = $this->factory([$channel])->create();

        self::assertSame('AT', $scope->targetCountry);
        self::assertSame(['name' => 'Example Trading GmbH'], $scope->seller);
    }

    public function testWithoutAnyCountryThereIsNoTargetCountry(): void
    {
        $channel = new Channel();
        $template = FeedFixtures::channel();
        $channel->setCode('WEB');
        $channel->setBaseCurrency($template->getBaseCurrency());
        foreach ($template->getLocales() as $locale) {
            $channel->addLocale($locale);
        }
        $channel->setDefaultLocale($template->getDefaultLocale());

        $scope = $this->factory([$channel])->create();

        self::assertNull($scope->targetCountry);
        self::assertNull($scope->seller, 'no name anywhere, no links: no seller object');
    }

    public function testConfiguredSellerNameAndLinks(): void
    {
        $scope = $this->factory([FeedFixtures::channel()], sellerName: 'Example Seller', sellerLinks: [
            ['type' => 'refund_policy', 'url' => 'https://shop.example.com/refunds', 'title' => 'Refunds'],
            ['type' => 'privacy_policy', 'url' => 'https://shop.example.com/privacy', 'title' => ' '],
            ['type' => 'faq', 'url' => 'https://shop.example.com/faq'],
        ], mediaPathPrefix: '/media/cache/large/')->create();

        self::assertSame([
            'name' => 'Example Seller',
            'links' => [
                ['type' => 'refund_policy', 'url' => 'https://shop.example.com/refunds', 'title' => 'Refunds'],
                ['type' => 'privacy_policy', 'url' => 'https://shop.example.com/privacy'],
                ['type' => 'faq', 'url' => 'https://shop.example.com/faq'],
            ],
        ], $scope->seller);
        self::assertSame('https://shop.example.com/media/cache/large/a/b.jpg', $scope->mediaUrl('/a/b.jpg'));
    }

    /**
     * @param list<Channel>                                           $channels
     * @param list<array{type: string, url: string, title?: ?string}> $sellerLinks
     */
    private function factory(
        array $channels,
        ?string $defaultChannel = null,
        ?string $defaultLocale = null,
        ?string $defaultTargetCountry = null,
        ?string $sellerName = null,
        array $sellerLinks = [],
        string $mediaPathPrefix = '/media/image/',
        string $baseUrl = 'https://shop.example.com/',
    ): FeedScopeFactory {
        $repository = $this->createStub(ChannelRepositoryInterface::class);
        $repository->method('findEnabled')->willReturn($channels);
        $repository->method('findOneByCode')->willReturnCallback(static function (string $code) use ($channels): ?Channel {
            foreach ($channels as $channel) {
                if ($channel->getCode() === $code) {
                    return $channel;
                }
            }

            return null;
        });

        $contexts = $this->createStub(AgentContextFactoryInterface::class);
        $contexts->method('create')->willReturnCallback(
            static fn (?ChannelInterface $channel = null): AgentContext => new AgentContext($channel ?? $channels[0], $baseUrl, 'de_DE'),
        );

        return new FeedScopeFactory($repository, $contexts, $defaultChannel, $defaultLocale, $defaultTargetCountry, $sellerName, $sellerLinks, $mediaPathPrefix);
    }
}
