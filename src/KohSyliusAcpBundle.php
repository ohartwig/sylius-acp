<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * ACP discovery, the ACP cart service with its buyer handoff, and the
 * agent-facing product feed.
 */
final class KohSyliusAcpBundle extends AbstractBundle
{
    protected string $extensionAlias = 'koh_acp';

    /** config/ sits at the package root, not under src/. */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('version')->defaultValue('2026-04-17')->end()
                ->scalarNode('api_base')
                    ->defaultValue('/api/agent')
                    ->info('Advertised as api_base_url. Change the route in config/routes.php to match.')
                ->end()
                ->booleanNode('checkout')
                    ->defaultFalse()
                    ->info('Declare that this installation serves ACP checkout sessions under api_base itself; this bundle does not implement them. An agent reading checkout will try to complete a purchase through the API.')
                ->end()
                ->arrayNode('carts')
                    ->info('The ACP cart service, implemented by this bundle. With neither carts nor checkout on, /.well-known/acp.json answers 404 (no ACP service).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('bearer_token_hashes')
                            ->defaultValue('')
                            ->info('SHA-256 hex digests of the API keys issued to agent platforms, separated by commas or whitespace. Use an env var: %env(ACP_CART_TOKEN_HASHES)%.')
                        ->end()
                        ->integerNode('max_line_items')->defaultValue(50)->min(1)->end()
                        ->integerNode('max_quantity')->defaultValue(99)->min(1)->end()
                        ->integerNode('rate_limit')
                            ->defaultValue(120)
                            ->min(1)
                            ->info('Requests per API key and rate_limit_interval.')
                        ->end()
                        ->scalarNode('rate_limit_interval')->defaultValue('1 minute')->end()
                        ->scalarNode('handoff_route')
                            ->defaultValue('sylius_shop_cart_summary')
                            ->info('Where continue_url lands the buyer once the cart is theirs.')
                        ->end()
                        ->scalarNode('fallback_route')
                            ->defaultValue('sylius_shop_homepage')
                            ->info('Where continue_url lands the buyer when the cart expired or a shop gate refused it.')
                        ->end()
                    ->end()
                ->end()
                ->integerNode('age_restriction')
                    ->defaultNull()
                    ->info('Minimum age, carried on every feed item. Set it for age-restricted catalogues.')
                ->end()
                ->arrayNode('feed')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('default_limit')->defaultValue(100)->end()
                        ->integerNode('max_limit')->defaultValue(500)->end()
                        ->integerNode('max_page')
                            ->defaultValue(1000)
                            ->min(1)
                            ->info('Highest page the feed answers; beyond it the list is empty. Bounds the offset an anonymous caller can ask the database for.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $feed = \is_array($config['feed'] ?? null) ? $config['feed'] : [];
        $carts = \is_array($config['carts'] ?? null) ? $config['carts'] : [];

        $builder->setParameter('koh_acp.version', (string) ($config['version'] ?? '2026-04-17'));
        $builder->setParameter('koh_acp.api_base', (string) ($config['api_base'] ?? '/api/agent'));
        $builder->setParameter('koh_acp.checkout', (bool) ($config['checkout'] ?? false));
        $builder->setParameter('koh_acp.age_restriction', $config['age_restriction'] ?? null);
        $builder->setParameter('koh_acp.carts.enabled', (bool) ($carts['enabled'] ?? false));
        $builder->setParameter('koh_acp.carts.bearer_token_hashes', $carts['bearer_token_hashes'] ?? '');
        $builder->setParameter('koh_acp.carts.max_line_items', (int) ($carts['max_line_items'] ?? 50));
        $builder->setParameter('koh_acp.carts.max_quantity', (int) ($carts['max_quantity'] ?? 99));
        $builder->setParameter('koh_acp.carts.rate_limit', (int) ($carts['rate_limit'] ?? 120));
        $builder->setParameter('koh_acp.carts.rate_limit_interval', (string) ($carts['rate_limit_interval'] ?? '1 minute'));
        $builder->setParameter('koh_acp.carts.handoff_route', (string) ($carts['handoff_route'] ?? 'sylius_shop_cart_summary'));
        $builder->setParameter('koh_acp.carts.fallback_route', (string) ($carts['fallback_route'] ?? 'sylius_shop_homepage'));
        $builder->setParameter('koh_acp.feed.default_limit', (int) ($feed['default_limit'] ?? 100));
        $builder->setParameter('koh_acp.feed.max_limit', (int) ($feed['max_limit'] ?? 500));
        $builder->setParameter('koh_acp.feed.max_page', (int) ($feed['max_page'] ?? 1000));

        $container->import('../config/services.php');
    }
}
