<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusAcp\Agent\AcpAgentProvider;
use Koh\SyliusAcp\Controller\AcpDiscoveryController;
use Koh\SyliusAcp\Controller\ProductFeedController;
use Koh\SyliusAcp\Feed\ProductFeedBuilder;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(ProductFeedBuilder::class)
        ->args([
            service('sylius.repository.product'),
            param('koh_acp.age_restriction'),
            param('koh_acp.feed.default_limit'),
            param('koh_acp.feed.max_limit'),
        ])
        ->public();

    $services->set(AcpDiscoveryController::class)
        ->args([
            service(AgentContextFactoryInterface::class),
            service(AgentJsonResponder::class),
            param('koh_acp.version'),
            param('koh_acp.api_base'),
            param('koh_acp.checkout'),
        ])
        ->public()
        ->tag('controller.service_arguments');

    $services->set(ProductFeedController::class)
        ->args([
            service(ProductFeedBuilder::class),
            service(AgentContextFactoryInterface::class),
            service(AgentJsonResponder::class),
        ])
        ->public()
        ->tag('controller.service_arguments');

    $services->set(AcpAgentProvider::class)
        ->args([param('koh_acp.api_base')])
        ->tag('koh_agent.endpoint')
        ->tag('koh_agent.api_catalog_entry')
        ->tag('koh_agent.skill');
};
