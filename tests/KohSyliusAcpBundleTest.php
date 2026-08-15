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

        self::assertSame('2026-01-30', $config['version']);
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
