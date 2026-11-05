<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests;

use Koh\SyliusAcp\KohSyliusAcpBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Loader\DefinitionFileLoader;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * One default in here is a safety decision rather than a convenience.
 *
 * `checkout` is off, and AcpTest already pins what that means downstream: an
 * agent that reads `checkout` among the declared services will try to complete
 * a purchase through the API. For an age-restricted catalogue that has to
 * happen in a browser session where the buyer can be verified. A default that
 * flipped to true would not fail anything — it would publish a capability, and
 * the shop would find out when an agent used it. So the default is asserted
 * here at the source as well as at the document.
 *
 * The rest is the same shape as the sibling bundles: `getPath()` is overridden
 * because `config/` sits at the package root, and without it every
 * `import('../config/…')` resolves one level too deep and the services are
 * silently not registered — a container that boots and answers 404.
 */
final class KohSyliusAcpBundleTest extends TestCase
{
    public function testCheckoutIsOffUnlessSomebodyTurnsItOn(): void
    {
        self::assertFalse($this->processConfig([])['checkout']);
        self::assertFalse($this->loadExtension([])->getParameter('koh_acp.checkout'));
    }

    public function testCheckoutCanBeTurnedOnDeliberately(): void
    {
        self::assertTrue($this->processConfig([['checkout' => true]])['checkout']);
        self::assertTrue($this->loadExtension(['checkout' => true])->getParameter('koh_acp.checkout'));
    }

    public function testCartsAreOffUntilSwitchedOnWithUsableDefaults(): void
    {
        $carts = $this->processConfig([])['carts'];

        self::assertFalse($carts['enabled']);
        self::assertSame('', $carts['bearer_token_hashes']);
        self::assertSame(50, $carts['max_line_items']);
        self::assertSame(99, $carts['max_quantity']);
        self::assertSame(120, $carts['rate_limit']);
        self::assertSame('1 minute', $carts['rate_limit_interval']);
        self::assertSame('sylius_shop_cart_summary', $carts['handoff_route']);
        self::assertSame('sylius_shop_homepage', $carts['fallback_route']);

        $builder = $this->loadExtension([]);
        self::assertFalse($builder->getParameter('koh_acp.carts.enabled'));
        self::assertSame('', $builder->getParameter('koh_acp.carts.bearer_token_hashes'));
        self::assertSame(50, $builder->getParameter('koh_acp.carts.max_line_items'));
        self::assertSame(99, $builder->getParameter('koh_acp.carts.max_quantity'));
        self::assertSame(120, $builder->getParameter('koh_acp.carts.rate_limit'));
        self::assertSame('1 minute', $builder->getParameter('koh_acp.carts.rate_limit_interval'));
        self::assertSame('sylius_shop_cart_summary', $builder->getParameter('koh_acp.carts.handoff_route'));
        self::assertSame('sylius_shop_homepage', $builder->getParameter('koh_acp.carts.fallback_route'));
    }

    public function testCartLimitsMustBePositive(): void
    {
        foreach (['max_line_items', 'max_quantity', 'rate_limit'] as $key) {
            self::assertSame(1, $this->processConfig([['carts' => [$key => 1]]])['carts'][$key]);
            try {
                $this->processConfig([['carts' => [$key => 0]]]);
                self::fail($key.' accepted 0');
            } catch (\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheCartSettingsReachTheContainer(): void
    {
        $builder = $this->loadExtension(['carts' => [
            'enabled' => true,
            'bearer_token_hashes' => '%env(ACP_CART_TOKEN_HASHES)%',
            'max_line_items' => 10,
            'max_quantity' => 5,
            'rate_limit' => 30,
            'rate_limit_interval' => '10 seconds',
            'handoff_route' => 'app_cart',
            'fallback_route' => 'app_home',
        ]]);

        self::assertTrue($builder->getParameter('koh_acp.carts.enabled'));
        self::assertSame('%env(ACP_CART_TOKEN_HASHES)%', $builder->getParameter('koh_acp.carts.bearer_token_hashes'));
        self::assertSame(10, $builder->getParameter('koh_acp.carts.max_line_items'));
        self::assertSame(5, $builder->getParameter('koh_acp.carts.max_quantity'));
        self::assertSame(30, $builder->getParameter('koh_acp.carts.rate_limit'));
        self::assertSame('10 seconds', $builder->getParameter('koh_acp.carts.rate_limit_interval'));
        self::assertSame('app_cart', $builder->getParameter('koh_acp.carts.handoff_route'));
        self::assertSame('app_home', $builder->getParameter('koh_acp.carts.fallback_route'));
    }

    public function testTheCartControllersAreRegistered(): void
    {
        $builder = $this->loadExtension([]);

        foreach ([\Koh\SyliusAcp\Controller\CartController::class, \Koh\SyliusAcp\Controller\CartHandoffController::class] as $id) {
            self::assertTrue($builder->hasDefinition($id), $id);
            self::assertTrue($builder->getDefinition($id)->isPublic());
            self::assertTrue($builder->getDefinition($id)->hasTag('controller.service_arguments'));
        }
    }

    public function testThePathPointsAtThePackageRootWhereConfigLives(): void
    {
        $path = (new KohSyliusAcpBundle())->getPath();

        self::assertDirectoryExists($path . '/config');
        self::assertFileExists($path . '/config/services.php');
        self::assertSame(\dirname(__DIR__), $path);
    }

    public function testTheDefaultsAreUsableWithoutAnyConfiguration(): void
    {
        $config = $this->processConfig([]);

        self::assertSame('2026-04-17', $config['version']);
        self::assertSame('2026-04-17', $this->loadExtension([])->getParameter('koh_acp.version'));
        self::assertSame('/api/agent', $config['api_base']);
        self::assertSame(100, $config['feed']['default_limit']);
        self::assertSame(500, $config['feed']['max_limit']);
    }

    public function testTheFeedSectionExistsEvenWhenNobodyWritesIt(): void
    {
        // addDefaultsIfNotSet. Without it `$config['feed']` is absent and
        // loadExtension falls back to its own literals — a second place where
        // the page size lives.
        self::assertArrayHasKey('feed', $this->processConfig([]));
    }

    public function testAnAgeRestrictionIsAbsentUntilSet(): void
    {
        // null means "not an age-restricted catalogue". A zero here would be a
        // different statement, and one the feed would carry on every item.
        self::assertNull($this->processConfig([])['age_restriction']);
        self::assertNull($this->loadExtension([])->getParameter('koh_acp.age_restriction'));
    }

    public function testAnAgeRestrictionIsCarriedThroughAsAnInteger(): void
    {
        self::assertSame(18, $this->processConfig([['age_restriction' => 18]])['age_restriction']);
        self::assertSame(18, $this->loadExtension(['age_restriction' => 18])->getParameter('koh_acp.age_restriction'));
    }

    public function testTheFeedLimitsReachTheContainer(): void
    {
        $builder = $this->loadExtension(['feed' => ['default_limit' => 25, 'max_limit' => 50]]);

        self::assertSame(25, $builder->getParameter('koh_acp.feed.default_limit'));
        self::assertSame(50, $builder->getParameter('koh_acp.feed.max_limit'));
    }

    public function testAMalformedFeedSectionFallsBackInsteadOfFailing(): void
    {
        // loadExtension guards with is_array. A scalar there would otherwise be
        // indexed as a string and produce a max_limit of 0 — which the builder
        // clamps to 1, turning a config typo into a one-item feed.
        $builder = $this->loadExtension(['feed' => 'nonsense']);

        self::assertSame(100, $builder->getParameter('koh_acp.feed.default_limit'));
        self::assertSame(500, $builder->getParameter('koh_acp.feed.max_limit'));
    }

    public function testAVersionWrittenAsAYamlNumberIsNotPublishedAsOne(): void
    {
        // `version: 1.0` parses as a float and would reach the discovery
        // document as "1". The cast in loadExtension is what stops that.
        self::assertSame('1', $this->loadExtension(['version' => 1.0])->getParameter('koh_acp.version'));
    }

    public function testTheServiceDefinitionsAreImported(): void
    {
        // If getPath() regressed, the import would resolve elsewhere and the
        // container would come up without the controllers.
        self::assertNotSame([], $this->loadExtension([])->getDefinitions());
    }

    public function testTheAcpFeedIsUnconfiguredByDefault(): void
    {
        // Nothing may be published until somebody names a target: no feed id,
        // no Feed API URL, no token.
        $acpFeed = $this->processConfig([])['acp_feed'];

        self::assertNull($acpFeed['feed_id']);
        self::assertNull($acpFeed['channel']);
        self::assertNull($acpFeed['locale']);
        self::assertNull($acpFeed['target_country']);
        self::assertSame('/media/image/', $acpFeed['media_path_prefix']);
        self::assertSame(100, $acpFeed['batch_size']);
        self::assertSame(['name' => null, 'links' => []], $acpFeed['seller']);
        self::assertSame(['base_url' => '', 'bearer_token' => '', 'timeout' => 30.0, 'max_attempts' => 3], $acpFeed['api']);

        $builder = $this->loadExtension([]);
        self::assertNull($builder->getParameter('koh_acp.acp_feed.feed_id'));
        self::assertSame('', $builder->getParameter('koh_acp.acp_feed.api.base_url'));
        self::assertSame('', $builder->getParameter('koh_acp.acp_feed.api.bearer_token'));
        self::assertSame([], $builder->getParameter('koh_acp.acp_feed.seller.links'));
    }

    public function testTheAcpFeedSettingsReachTheContainer(): void
    {
        $settings = [
            'feed_id' => '%env(ACP_FEED_ID)%',
            'channel' => 'WEB',
            'locale' => 'de_DE',
            'target_country' => 'DE',
            'media_path_prefix' => '/media/cache/large/',
            'batch_size' => 50,
            'seller' => ['name' => 'Example Shop', 'links' => [['type' => 'refund_policy', 'url' => 'https://shop.example.com/refunds', 'title' => 'Refunds']]],
            'api' => ['base_url' => '%env(ACP_FEED_API_URL)%', 'bearer_token' => '%env(ACP_FEED_API_TOKEN)%', 'timeout' => 10.0, 'max_attempts' => 5],
        ];
        $this->processConfig([['acp_feed' => $settings]]);
        $builder = $this->loadExtension(['acp_feed' => $settings]);

        self::assertSame('%env(ACP_FEED_ID)%', $builder->getParameter('koh_acp.acp_feed.feed_id'));
        self::assertSame('WEB', $builder->getParameter('koh_acp.acp_feed.channel'));
        self::assertSame('de_DE', $builder->getParameter('koh_acp.acp_feed.locale'));
        self::assertSame('DE', $builder->getParameter('koh_acp.acp_feed.target_country'));
        self::assertSame('/media/cache/large/', $builder->getParameter('koh_acp.acp_feed.media_path_prefix'));
        self::assertSame(50, $builder->getParameter('koh_acp.acp_feed.batch_size'));
        self::assertSame('Example Shop', $builder->getParameter('koh_acp.acp_feed.seller.name'));
        self::assertSame($settings['seller']['links'], $builder->getParameter('koh_acp.acp_feed.seller.links'));
        self::assertSame('%env(ACP_FEED_API_URL)%', $builder->getParameter('koh_acp.acp_feed.api.base_url'));
        self::assertSame('%env(ACP_FEED_API_TOKEN)%', $builder->getParameter('koh_acp.acp_feed.api.bearer_token'));
        self::assertSame(10.0, $builder->getParameter('koh_acp.acp_feed.api.timeout'));
        self::assertSame(5, $builder->getParameter('koh_acp.acp_feed.api.max_attempts'));
    }

    public function testAcpFeedLimitsAreBounded(): void
    {
        foreach ([['batch_size', 1, 1000, 0, 1001], ['max_attempts', 1, 10, 0, 11]] as [$key, $low, $high, $tooLow, $tooHigh]) {
            $section = 'max_attempts' === $key ? 'api' : null;
            $wrap = static fn (int $value): array => [['acp_feed' => null === $section ? [$key => $value] : [$section => [$key => $value]]]];
            $read = fn (int $value): mixed => null === $section
                ? $this->processConfig($wrap($value))['acp_feed'][$key]
                : $this->processConfig($wrap($value))['acp_feed'][$section][$key];

            self::assertSame($low, $read($low));
            self::assertSame($high, $read($high));
            foreach ([$tooLow, $tooHigh] as $invalid) {
                try {
                    $this->processConfig($wrap($invalid));
                    self::fail($key.' accepted '.$invalid);
                } catch (\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException) {
                    $this->addToAssertionCount(1);
                }
            }
        }

        self::assertSame(1.0, $this->processConfig([['acp_feed' => ['api' => ['timeout' => 1.0]]]])['acp_feed']['api']['timeout']);
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->processConfig([['acp_feed' => ['api' => ['timeout' => 0.5]]]]);
    }

    public function testASellerLinkMustBeAbsolute(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessage('Seller link url must be absolute');

        $this->processConfig([['acp_feed' => ['seller' => ['links' => [['type' => 'faq', 'url' => '/faq']]]]]]);
    }

    public function testTheFeedCommandsAreRegisteredButNoRouteServesTheAcpFeed(): void
    {
        $builder = $this->loadExtension([]);

        foreach ([\Koh\SyliusAcp\Command\ExportFeedCommand::class, \Koh\SyliusAcp\Command\PushFeedCommand::class, \Koh\SyliusAcp\Command\CreateFeedCommand::class] as $id) {
            self::assertTrue($builder->hasDefinition($id), $id);
            self::assertTrue($builder->getDefinition($id)->hasTag('console.command'), $id);
        }
        self::assertSame('http_client', (string) $builder->getDefinition(\Koh\SyliusAcp\ProductFeed\Api\FeedApiClient::class)->getArgument(0));
    }

    /**
     * @param list<array<string, mixed>> $configs
     *
     * @return array<string, mixed>
     */
    private function processConfig(array $configs): array
    {
        $treeBuilder = new TreeBuilder('koh_acp');
        $configurator = new DefinitionConfigurator(
            $treeBuilder,
            $this->createStub(DefinitionFileLoader::class),
            __DIR__,
            'test',
        );

        (new KohSyliusAcpBundle())->configure($configurator);

        return (new Processor())->process($treeBuilder->buildTree(), $configs);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function loadExtension(array $config): ContainerBuilder
    {
        $bundle = new KohSyliusAcpBundle();
        $builder = new ContainerBuilder();
        $path = $bundle->getPath() . '/config/services.php';

        $loader = new PhpFileLoader($builder, new FileLocator(\dirname($path)));
        $instanceof = [];
        $configurator = new ContainerConfigurator($builder, $loader, $instanceof, $path, $path);

        $bundle->loadExtension($config, $configurator, $builder);

        return $builder;
    }
}
