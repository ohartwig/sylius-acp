<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusAcp\Controller\AcpDiscoveryController;
use Koh\SyliusAcp\Controller\CartController;
use Koh\SyliusAcp\Controller\CartHandoffController;
use Koh\SyliusAcp\Controller\ProductFeedController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('koh_acp_discovery', '/.well-known/acp.json')
        ->controller(AcpDiscoveryController::class)
        ->methods(['GET', 'HEAD']);

    // Kept in step with koh_acp.api_base — the discovery document advertises
    // this path, so the two must not drift.
    $routes->add('koh_acp_product_feed', '/api/agent/feed')
        ->controller(ProductFeedController::class)
        ->methods(['GET', 'HEAD']);

    // ACP carts, {api_base_url}/carts. Stateless: bearer key, no session.
    $routes->add('koh_acp_cart_create', '/api/agent/carts')
        ->controller([CartController::class, 'create'])
        ->methods(['POST']);
    $routes->add('koh_acp_cart_retrieve', '/api/agent/carts/{id}')
        ->controller([CartController::class, 'retrieve'])
        ->requirements(['id' => '[A-Za-z0-9_]{1,64}'])
        ->methods(['GET', 'HEAD']);
    $routes->add('koh_acp_cart_update', '/api/agent/carts/{id}')
        ->controller([CartController::class, 'update'])
        ->requirements(['id' => '[A-Za-z0-9_]{1,64}'])
        ->methods(['PUT']);
    $routes->add('koh_acp_cart_cancel', '/api/agent/carts/{id}/cancel')
        ->controller([CartController::class, 'cancel'])
        ->requirements(['id' => '[A-Za-z0-9_]{1,64}'])
        ->methods(['POST']);

    // continue_url: the buyer's browser, inside the shop firewall and session.
    // The hyphen keeps the first segment from looking like a shop locale.
    $routes->add('koh_acp_cart_continue', '/acp-cart/{id}')
        ->controller(CartHandoffController::class)
        ->requirements(['id' => '[A-Za-z0-9_]{1,64}'])
        ->methods(['GET']);
};
