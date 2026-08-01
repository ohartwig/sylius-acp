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
 * ACP discovery and the agent-facing product feed.
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
                ->scalarNode('version')->defaultValue('2026-01-30')->end()
                ->scalarNode('api_base')
                    ->defaultValue('/api/agent')
                    ->info('Advertised as api_base_url. Change the route in config/routes.php to match.')
                ->end()
                ->booleanNode('checkout')
                    ->defaultFalse()
                    ->info('Declare the checkout service. Off by default: an agent reading it there will try to complete a purchase through the API.')
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

        $builder->setParameter('koh_acp.version', (string) ($config['version'] ?? '2026-01-30'));
        $builder->setParameter('koh_acp.api_base', (string) ($config['api_base'] ?? '/api/agent'));
        $builder->setParameter('koh_acp.checkout', (bool) ($config['checkout'] ?? false));
        $builder->setParameter('koh_acp.age_restriction', $config['age_restriction'] ?? null);
        $builder->setParameter('koh_acp.feed.default_limit', (int) ($feed['default_limit'] ?? 100));
        $builder->setParameter('koh_acp.feed.max_limit', (int) ($feed['max_limit'] ?? 500));

        $container->import('../config/services.php');
    }
}
