<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Controller;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the ACP discovery document at /.well-known/acp.json.
 *
 * Declares only what this shop actually implements. `checkout` stays out of
 * capabilities.services unless it is switched on, because an agent that reads
 * it there will try to complete a purchase through the API — and for a shop
 * with an age-restricted catalogue that has to happen in a browser session
 * where the buyer can be verified.
 */
final class AcpDiscoveryController
{
    public function __construct(
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly AgentJsonResponder $responder,
        private readonly string $version = '2026-01-30',
        private readonly string $apiBase = '/api/agent',
        private readonly bool $checkoutEnabled = false,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $context = $this->contextFactory->create();

        $services = ['catalog'];
        if ($this->checkoutEnabled) {
            $services[] = 'checkout';
        }

        $currency = $context->channel->getBaseCurrency()?->getCode();

        return $this->responder->respond([
            'protocol' => [
                'name' => 'acp',
                'version' => $this->version,
                'supported_versions' => [$this->version],
                'documentation_url' => 'https://agenticcommerce.dev',
            ],
            'api_base_url' => $context->url($this->apiBase),
            'transports' => ['rest'],
            'capabilities' => [
                'services' => $services,
                'supported_currencies' => null === $currency ? [] : [strtolower($currency)],
                'supported_locales' => array_map(
                    static fn (string $l): string => str_replace('_', '-', $l),
                    $context->localeCodes,
                ),
            ],
        ], maxAge: 3600, request: $request);
    }
}
