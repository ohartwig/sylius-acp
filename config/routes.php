<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusAcp\Controller\AcpDiscoveryController;
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
};
