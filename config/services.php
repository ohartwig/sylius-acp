<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusAcp\Agent\AcpAgentProvider;
use Koh\SyliusAcp\Cart\AgentCartStore;
use Koh\SyliusAcp\Command\CreateFeedCommand;
use Koh\SyliusAcp\Command\ExportFeedCommand;
use Koh\SyliusAcp\Command\PushFeedCommand;
use Koh\SyliusAcp\Cart\CartPresenter;
use Koh\SyliusAcp\Cart\CartRequestParser;
use Koh\SyliusAcp\Controller\AcpDiscoveryController;
use Koh\SyliusAcp\Controller\CartController;
use Koh\SyliusAcp\Controller\CartHandoffController;
use Koh\SyliusAcp\Controller\ProductFeedController;
use Koh\SyliusAcp\Feed\DoctrineFeedProductSource;
use Koh\SyliusAcp\Feed\ProductFeedBuilder;
use Koh\SyliusAcp\Http\BearerTokenAuthenticator;
use Koh\SyliusAcp\Http\IdempotencyStore;
use Koh\SyliusAcp\ProductFeed\AcpFeedBuilder;
use Koh\SyliusAcp\ProductFeed\AcpProductMapper;
use Koh\SyliusAcp\ProductFeed\Api\FeedApiClient;
use Koh\SyliusAcp\ProductFeed\DoctrineCatalogueStream;
use Koh\SyliusAcp\ProductFeed\FeedPusher;
use Koh\SyliusAcp\ProductFeed\FeedScopeFactory;
use Koh\SyliusAcp\ProductFeed\JsonlFeedWriter;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(DoctrineFeedProductSource::class)
        ->args([service('sylius.repository.product')]);

    $services->set(ProductFeedBuilder::class)
        ->args([
            service(DoctrineFeedProductSource::class),
            param('koh_acp.age_restriction'),
            param('koh_acp.feed.default_limit'),
            param('koh_acp.feed.max_limit'),
            param('koh_acp.feed.max_page'),
        ])
        ->public();

    $services->set(AcpDiscoveryController::class)
        ->args([
            service(AgentContextFactoryInterface::class),
            service(AgentJsonResponder::class),
            param('koh_acp.version'),
            param('koh_acp.api_base'),
            param('koh_acp.checkout'),
            param('koh_acp.carts.enabled'),
        ])
        ->public()
        ->tag('controller.service_arguments');

    // ACP carts. Services are defined even when carts are off, so the routes
    // resolve; the controllers then answer 404.
    $services->set(AgentCartStore::class)
        ->args([
            service('sylius.factory.order'),
            service('sylius.factory.order_item'),
            service('sylius.modifier.order_item_quantity'),
            service('sylius.modifier.order'),
            service('sylius.repository.order'),
            service('sylius.repository.product_variant'),
            service('sylius.checker.inventory.availability'),
            service('sylius.manager.order'),
            param('kernel.secret'),
            param('sylius_order.cart_expiration_period'),
        ]);

    $services->set(CartPresenter::class)
        ->args([service(AgentCartStore::class), param('koh_acp.age_restriction')]);

    $services->set(CartRequestParser::class)
        ->args([param('koh_acp.carts.max_line_items'), param('koh_acp.carts.max_quantity')]);

    $services->set(BearerTokenAuthenticator::class)
        ->args([param('koh_acp.carts.bearer_token_hashes')]);

    $services->set(IdempotencyStore::class)
        ->args([service('cache.app')]);

    $services->set('koh_acp.carts.limiter_storage', CacheStorage::class)
        ->args([service('cache.app')]);
    $services->set('koh_acp.carts.limiter', RateLimiterFactory::class)
        ->args([
            [
                'id' => 'koh_acp_carts',
                'policy' => 'sliding_window',
                'limit' => param('koh_acp.carts.rate_limit'),
                'interval' => param('koh_acp.carts.rate_limit_interval'),
            ],
            service('koh_acp.carts.limiter_storage'),
        ]);

    $services->set(CartController::class)
        ->args([
            service(AgentCartStore::class),
            service(CartPresenter::class),
            service(CartRequestParser::class),
            service(BearerTokenAuthenticator::class),
            service(IdempotencyStore::class),
            service(AgentContextFactoryInterface::class),
            param('koh_acp.carts.enabled'),
            [param('koh_acp.version')],
            service('koh_acp.carts.limiter'),
        ])
        ->public()
        ->tag('controller.service_arguments');

    $services->set(CartHandoffController::class)
        ->args([
            service(AgentCartStore::class),
            service(AgentContextFactoryInterface::class),
            service('sylius.context.cart'),
            service('sylius.factory.order_item'),
            service('sylius.modifier.order_item_quantity'),
            service('sylius.factory.add_to_cart_command'),
            service('event_dispatcher'),
            service('sylius.manager.order'),
            service('router'),
            param('koh_acp.carts.enabled'),
            param('koh_acp.carts.handoff_route'),
            param('koh_acp.carts.fallback_route'),
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

    // ACP product feed, published to an agent. Only commands use these; no
    // route serves the ACP feed, because in ACP the agent hosts the Feed API.
    $services->set(DoctrineCatalogueStream::class)
        ->args([service('sylius.repository.product'), service('sylius.manager.product')]);

    $services->set(AcpProductMapper::class)
        ->args([service('sylius.checker.inventory.availability')]);

    $services->set(AcpFeedBuilder::class)
        ->args([
            service(DoctrineCatalogueStream::class),
            service(AcpProductMapper::class),
            param('koh_acp.acp_feed.batch_size'),
        ]);

    $services->set(FeedScopeFactory::class)
        ->args([
            service('sylius.repository.channel'),
            service(AgentContextFactoryInterface::class),
            param('koh_acp.acp_feed.channel'),
            param('koh_acp.acp_feed.locale'),
            param('koh_acp.acp_feed.target_country'),
            param('koh_acp.acp_feed.seller.name'),
            param('koh_acp.acp_feed.seller.links'),
            param('koh_acp.acp_feed.media_path_prefix'),
        ]);

    $services->set(JsonlFeedWriter::class);

    $services->set(FeedApiClient::class)
        ->args([
            service('http_client'),
            param('koh_acp.acp_feed.api.base_url'),
            param('koh_acp.acp_feed.api.bearer_token'),
            param('koh_acp.version'),
            param('koh_acp.acp_feed.api.timeout'),
            param('koh_acp.acp_feed.api.max_attempts'),
        ]);

    $services->set(FeedPusher::class)
        ->args([
            service(AcpFeedBuilder::class),
            service(FeedApiClient::class),
            param('koh_acp.acp_feed.batch_size'),
        ]);

    $services->set(ExportFeedCommand::class)
        ->args([
            service(FeedScopeFactory::class),
            service(AcpFeedBuilder::class),
            service(JsonlFeedWriter::class),
            param('koh_acp.acp_feed.feed_id'),
        ])
        ->tag('console.command');

    $services->set(PushFeedCommand::class)
        ->args([
            service(FeedScopeFactory::class),
            service(FeedPusher::class),
            service(FeedApiClient::class),
            param('koh_acp.acp_feed.feed_id'),
        ])
        ->tag('console.command');

    $services->set(CreateFeedCommand::class)
        ->args([service(FeedScopeFactory::class), service(FeedApiClient::class)])
        ->tag('console.command');

    $services->set(AcpAgentProvider::class)
        ->args([param('koh_acp.api_base'), param('koh_acp.checkout'), param('koh_acp.carts.enabled')])
        ->tag('koh_agent.endpoint')
        ->tag('koh_agent.api_catalog_entry')
        ->tag('koh_agent.skill');
};
