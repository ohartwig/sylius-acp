<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Controller;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the ACP discovery document at /.well-known/acp.json (ACP 2026-04-17,
 * `DiscoveryResponse` in schema.agentic_checkout.json).
 *
 * `capabilities.services` is a closed enum (checkout, orders, delegate_payment,
 * carts) and lists ACP operations the seller implements. This bundle
 * implements none of them: the product feed under `api_base` is not an ACP
 * resource. So the document exists only when the installation declares that
 * it serves ACP checkout (`koh_acp.checkout`). Without that it answers 404,
 * which the discovery RFC defines as "this seller does not support ACP" — an
 * agent then does not call `{api_base_url}/checkout_sessions` blindly.
 *
 * Checkout also stays off by default because an agent that reads it will try
 * to complete a purchase through the API — and for a shop with an
 * age-restricted catalogue that has to happen in a browser session where the
 * buyer can be verified.
 */
final class AcpDiscoveryController
{
    public function __construct(
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly AgentJsonResponder $responder,
        private readonly string $version = '2026-04-17',
        private readonly string $apiBase = '/api/agent',
        private readonly bool $checkoutEnabled = false,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->checkoutEnabled) {
            throw new NotFoundHttpException('This shop declares no ACP service.');
        }

        $context = $this->contextFactory->create();
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
                'services' => ['checkout'],
                'supported_currencies' => null === $currency ? [] : [strtolower($currency)],
                'supported_locales' => array_map(
                    static fn (string $l): string => str_replace('_', '-', $l),
                    $context->localeCodes,
                ),
            ],
        ], maxAge: 3600, request: $request);
    }
}
